<?php

namespace App\Services\LegacyUsers\Readiness;

use DateTimeImmutable;
use RuntimeException;

/** Inventories assert coverage of named fields, never a fresh full database snapshot. */
final class Observations
{
    private array $data;

    private array $rows = [];

    private array $coverage = [];

    public function __construct(public readonly string $path, public readonly string $sha256, Snapshot $baseline)
    {
        ReadinessFiles::file($path, $sha256);
        if (filesize($path) > 128 * 1024 * 1024) {
            throw new RuntimeException('Observation file exceeds supported size.');
        }
        $this->data = json_decode(file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
        ReadinessFiles::keys($this->data, ['format', 'source_database', 'captured_at', 'consistency', 'baseline_manifest_sha256', 'provenance', 'scopes']);
        $data = $this->data;
        if ($data['format'] !== 'legacy_readiness_observations_v1'
            || $data['source_database'] !== $baseline->bundle->manifest['source_database']
            || $data['baseline_manifest_sha256'] !== $baseline->bundle->manifestHash
            || $data['consistency'] !== 'sequential_non_atomic'
            || ! is_string($data['captured_at'])
            || ! preg_match('/\A\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(?:\.\d{1,6})?(?:Z|[+-]\d\d:\d\d)\z/', $data['captured_at'])) {
            throw new RuntimeException('Invalid observation provenance.');
        }
        ReadinessFiles::keys($data['provenance'], ['capture_sha256', 'order_capture_sha256', 'normalizer_sha256']);
        foreach ($data['provenance'] as $hash) {
            if (! ReadinessFiles::digest($hash)) {
                throw new RuntimeException('Invalid observation provenance hash.');
            }
        }
        ReadinessFiles::keys($data['scopes'], ['accesses', 'members', 'pages', 'orders']);
        foreach ($data['scopes'] as $scope => $entry) {
            ReadinessFiles::keys($entry, ['coverage', 'rows', 'row_count']);
            if (! in_array($entry['coverage'], ['complete', 'partial'], true)
                || ($scope === 'orders' && $entry['coverage'] !== 'partial')
                || ! is_array($entry['rows']) || ! array_is_list($entry['rows'])
                || ! is_int($entry['row_count']) || count($entry['rows']) !== $entry['row_count']) {
                throw new RuntimeException('Invalid observation coverage.');
            }
            $this->coverage[$scope] = ['coverage' => $entry['coverage'], 'row_count' => $entry['row_count'],
                'fields' => match ($scope) {
                    'accesses' => ['source_access_id', 'source_member_id', 'source_page_id', 'source_subject_id', 'grant_kind', 'source_order_id', 'created_at'],
                    'orders' => ['source_order_id', 'source_status', 'updated_at'],
                    default => ['source_id'],
                }];
            $this->rows[$scope] = [];
            foreach ($entry['rows'] as $row) {
                if (in_array($scope, ['members', 'pages'], true)) {
                    $id = $row;
                    $row = true;
                } elseif ($scope === 'orders') {
                    ReadinessFiles::keys($row, ['source_order_id', 'source_status', 'updated_at']);
                    $id = $row['source_order_id'];
                    if (! is_int($row['source_status']) || $row['source_status'] < 0) {
                        throw new RuntimeException('Invalid observed order status.');
                    }
                    $this->date($row['updated_at']);
                } else {
                    ReadinessFiles::keys($row, ['source_access_id', 'source_member_id', 'source_page_id', 'source_subject_id', 'grant_kind', 'source_order_id', 'created_at']);
                    $id = $row['source_access_id'];
                    foreach (['source_member_id', 'source_page_id', 'source_subject_id', 'source_order_id'] as $field) {
                        $row[$field] = Snapshot::reference($row[$field]);
                    }
                    if (! in_array($row['grant_kind'], ['order', 'manual', 'legacy_import_marker', 'other'], true)
                        || ($row['grant_kind'] !== 'order' && $row['source_order_id'] !== null)
                        || ($row['grant_kind'] === 'order' && $row['source_order_id'] === null)) {
                        throw new RuntimeException('Contradictory observed grant provenance.');
                    }
                    $this->date($row['created_at']);
                }
                if (! is_int($id) || $id <= 0 || array_key_exists($id, $this->rows[$scope])) {
                    throw new RuntimeException('Duplicate or invalid observation identity.');
                }
                $this->rows[$scope][$id] = $row;
            }
            ksort($this->rows[$scope], SORT_NUMERIC);
        }
        // Release the much larger decoded lists after constructing keyed, whitelisted inventories.
        unset($this->data['scopes']);
    }

    private function date(mixed $value): void
    {
        if ($value === null) {
            return;
        }
        if (! is_string($value) || ! preg_match('/\A\d{4}-\d\d-\d\d \d\d:\d\d:\d\d\z/', $value)
            || DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value) === false
            || DateTimeImmutable::getLastErrors() !== false) {
            throw new RuntimeException('Invalid observed source date.');
        }
    }

    public function report(Snapshot $baseline, array $archive): array
    {
        $coverage = $this->coverage;
        $completeAccess = $coverage['accesses']['coverage'] === 'complete';
        $baseAccess = $this->difference($baseline->core['platform_access'], $this->rows['accesses'], $completeAccess,
            ['source_member_id', 'source_page_id', 'source_subject_id', 'grant_kind', 'source_order_id', 'created_at']);
        $targetAccess = $this->difference($archive['platform_access'], $this->rows['accesses'], $completeAccess,
            ['source_member_id', 'source_page_id', 'source_subject_id']);
        $pairs = [];
        foreach ($this->rows['accesses'] as $row) {
            $key = ($row['source_member_id'] ?? 0).':'.($row['source_page_id'] ?? 0);
            $pairs[$key] = ($pairs[$key] ?? 0) + 1;
        }
        $removed = [];
        foreach ($targetAccess['removed_source_ids'] ?? [] as $id) {
            $row = $archive['platform_access'][$id];
            $source = $baseline->core['platform_access'][$id] ?? [];
            $orderId = $source['source_order_id'] ?? null;
            $removed[] = [
                'source_access_id' => $id, 'archive_row_id' => $row['id'],
                'source_member_id' => $row['source_member_id'], 'source_page_id' => $row['source_page_id'],
                'source_order_id' => $orderId, 'grant_kind' => $source['grant_kind'] ?? 'unknown',
                'observed_order_status' => $this->rows['orders'][$orderId]['source_status'] ?? null,
                'same_owner_page_observed' => isset($pairs[($row['source_member_id'] ?? 0).':'.($row['source_page_id'] ?? 0)]),
                'confirmed_test_profile' => $row['source_member_id'] === 37476,
            ];
        }
        $statuses = [];
        foreach ($this->rows['orders'] as $id => $row) {
            $before = $baseline->core['order'][$id]['source_status'] ?? null;
            $statuses[] = $row + ['baseline_status' => $before,
                'changed' => $before !== $row['source_status'], 'bank_refund_verified' => false];
        }

        return [
            'provenance' => $this->data + ['file_sha256' => $this->sha256],
            'coverage' => $coverage, 'full_core_comparison' => 'unknown',
            'baseline_accesses' => $baseAccess, 'archive_accesses' => $targetAccess,
            'removed_archive_accesses' => $removed, 'order_status_observations' => $statuses,
            'baseline_member_ids' => $this->difference($baseline->core['member'], $this->rows['members'], $coverage['members']['coverage'] === 'complete', []),
            'archive_member_ids' => $this->difference($archive['member'], $this->rows['members'], $coverage['members']['coverage'] === 'complete', []),
            'baseline_page_ids' => $this->difference($baseline->pages, $this->rows['pages'], $coverage['pages']['coverage'] === 'complete', []),
        ];
    }

    private function difference(array $before, array $after, bool $complete, array $fields): array
    {
        $changed = [];
        foreach (array_intersect_key($after, $before) as $id => $row) {
            foreach ($fields as $field) {
                if (($row[$field] ?? null) !== ($before[$id][$field] ?? null)) {
                    $changed[] = $id;
                    break;
                }
            }
        }

        return [
            'comparison_fields' => $fields,
            'added_source_ids' => array_keys(array_diff_key($after, $before)),
            'changed_source_ids' => $changed,
            'removed_source_ids' => $complete ? array_keys(array_diff_key($before, $after)) : null,
            'absence' => $complete ? 'observed_within_declared_scope' : 'unknown',
        ];
    }
}
