<?php

namespace App\Services\LegacyUsers;

use Generator;
use InvalidArgumentException;

/** Immutable, checksummed database export. It never opens URLs or executes source code. */
final class LegacyUserBundle
{
    public readonly array $manifest;

    public readonly string $manifestHash;

    private readonly string $directory;

    private array $sourceMemberIds = [];

    private LegacyUserOwnershipIndex $ownershipIndex;

    public function __construct(string $directory, private readonly LegacyUserSourcePolicy $policy)
    {
        $this->directory = realpath($directory) ?: throw new InvalidArgumentException('Missing private export directory.');
        $path = $this->directory.'/manifest.json';
        if (! is_file($path) || is_link($path) || filesize($path) > 1024 * 1024) {
            throw new InvalidArgumentException('Invalid export manifest.');
        }
        $bytes = file_get_contents($path);
        $this->manifestHash = hash('sha256', $bytes);
        $this->manifest = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
        $m = $this->manifest;
        if (($m['format'] ?? null) !== 'legacy_user_records_v1' || ($m['status'] ?? null) !== 'complete'
            || ($m['source_database'] ?? null) !== config('legacy_users.source_database')
            || ($m['mapping_version'] ?? null) !== config('legacy_users.mapping_version')
            || ($m['binary_files'] ?? null) !== false || ($m['messenger_history'] ?? null) !== false
            || ! preg_match('/^[a-zA-Z0-9_-]{1,96}$/D', $m['snapshot_id'] ?? '')
            || ! preg_match('/^[a-f0-9]{64}$/D', $m['snapshot_sha256'] ?? '')
            || ! hash_equals(hash_file('sha256', resource_path('legacy-users/source-policy-v1.json')), $m['policy_sha256'] ?? '')
            || array_keys($m['tables'] ?? []) !== array_keys($policy->tables())) {
            throw new InvalidArgumentException('Export does not match the approved source contract.');
        }
        $sourceRows = $exportedRows = 0;
        foreach ($m['tables'] as $table => $entry) {
            if (! is_int($entry['source_rows']) || ! is_int($entry['exported_rows']) || $entry['source_rows'] < 0 || $entry['exported_rows'] < 0
                || array_sum($entry['dispositions']) !== $entry['source_rows']) {
                throw new InvalidArgumentException('Invalid export row reconciliation.');
            }
            $expectedExport = $entry['source_rows'] - ($entry['dispositions']['archive'] ?? 0) - ($entry['dispositions']['anonymous_archive'] ?? 0);
            if ($entry['exported_rows'] !== $expectedExport) {
                throw new InvalidArgumentException('Unexplained source row exclusion.');
            }
            if ($policy->table($table)['kind'] === 'archive') {
                if ($entry['exported_rows'] !== 0 || $entry['file'] !== null) {
                    throw new InvalidArgumentException('Excluded table cannot enter the import.');
                }
            } else {
                $file = $this->path($table);
                if (! preg_match('/^[a-f0-9]{64}$/D', $entry['sha256'] ?? '') || ! hash_equals($entry['sha256'], hash_file('sha256', $file))) {
                    throw new InvalidArgumentException('Export checksum mismatch.');
                }
            }
            $sourceRows += $entry['source_rows'];
            $exportedRows += $entry['exported_rows'];
        }
        if ($sourceRows !== $m['source_rows'] || $exportedRows !== $m['exported_rows']) {
            throw new InvalidArgumentException('Manifest totals do not reconcile.');
        }
        foreach ($this->readRows('member', false) as $record) {
            $this->sourceMemberIds[$record['source_id']] = true;
        }
        $ownerProofs = $this->manifest['owner_proofs'] ?? [];
        if (! is_array($ownerProofs)) {
            throw new InvalidArgumentException('Invalid source owner proofs.');
        }
        $this->ownershipIndex = LegacyUserOwnershipIndex::build(
            $this->policy,
            $this->sourceMemberIds,
            fn (string $table): Generator => $this->readRows($table, false),
            $ownerProofs
        );
    }

    /** @return Generator<int, array{source_id: int, source_member_id: ?int, ownership_status: string, row: array}> */
    public function rows(string $table): Generator
    {
        yield from $this->readRows($table, true);
    }

    /** @return Generator<int, array{source_id: int, source_member_id: ?int, ownership_status: string, row: array}> */
    private function readRows(string $table, bool $validateOwnership): Generator
    {
        $definition = $this->policy->table($table);
        if ($definition['kind'] === 'archive') {
            return;
        }
        $file = fopen($this->path($table), 'rb');
        $count = 0;
        $lastId = null;
        $digest = hash_init('sha256');
        $states = [];
        try {
            while (($line = fgets($file, 8 * 1024 * 1024)) !== false) {
                if (! str_ends_with($line, "\n")) {
                    throw new InvalidArgumentException('Oversized or incomplete source record.');
                }
                hash_update($digest, $line);
                $record = json_decode($line, true, 64, JSON_THROW_ON_ERROR);
                $this->policy->validateRecord($table, $record);
                $id = $record['source_id'] ?? null;
                $member = $record['source_member_id'] ?? null;
                if (! is_int($id) || $id < 0 || ($lastId !== null && $id <= $lastId)
                    || ($member !== null && (! is_int($member) || $member < 0))
                    || ! is_array($record['row'] ?? null) || ($record['row'][$definition['primary_key']] ?? null) !== $id
                    || ! in_array($record['ownership_status'] ?? null, ['identity', 'linked', 'orphan', 'unlinked', 'ambiguous', 'reference'], true)
                    || (in_array($record['ownership_status'], ['identity', 'linked'], true) && ! $member)) {
                    throw new InvalidArgumentException('Invalid source identity or ownership record.');
                }
                $lastId = $id;
                if ($record['ownership_status'] === 'linked' && ! isset($this->sourceMemberIds[$member])) {
                    throw new InvalidArgumentException('Linked source member does not exist in this snapshot.');
                }
                if ($definition['owner'] === 'memberId') {
                    $expectedState = isset($this->sourceMemberIds[$member]) ? 'linked' : ($member ? 'orphan' : 'unlinked');
                    if ($record['ownership_status'] !== $expectedState) {
                        throw new InvalidArgumentException('Direct source membership is inconsistent.');
                    }
                }
                if ($validateOwnership) {
                    $this->ownershipIndex->assert($table, $record);
                }
                $states[$record['ownership_status']] = ($states[$record['ownership_status']] ?? 0) + 1;
                $count++;
                yield $id => $record;
            }
            if ($count !== $this->manifest['tables'][$table]['exported_rows'] || ! hash_equals($this->manifest['tables'][$table]['sha256'], hash_final($digest))) {
                throw new InvalidArgumentException('Export changed or row count differs while reading.');
            }
            $expectedStates = array_filter(array_diff_key($this->manifest['tables'][$table]['dispositions'], ['archive' => true, 'anonymous_archive' => true]));
            ksort($states);
            ksort($expectedStates);
            if ($states !== $expectedStates) {
                throw new InvalidArgumentException('Actual ownership counts differ from the manifest.');
            }
        } finally {
            fclose($file);
        }
    }

    /** Identity and order parents always precede their dependent historical records. */
    public function tableOrder(): array
    {
        return array_values(array_unique(array_merge(['member', 'order', 'order_item', 'platform_access'], array_keys($this->policy->tables()))));
    }

    private function path(string $table): string
    {
        $entry = $this->manifest['tables'][$table];
        $path = $this->directory.'/'.$table.'.jsonl';
        if ($entry['file'] !== $table.'.jsonl' || is_link($path) || ! is_file($path) || dirname(realpath($path)) !== $this->directory) {
            throw new InvalidArgumentException('Unsafe export file path.');
        }

        return $path;
    }
}
