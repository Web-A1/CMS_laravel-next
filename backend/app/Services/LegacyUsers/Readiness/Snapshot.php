<?php

namespace App\Services\LegacyUsers\Readiness;

use App\Services\LegacyUsers\LegacyUserBundle;
use App\Services\LegacyUsers\LegacyUserRecordMapper;
use App\Services\LegacyUsers\LegacyUserSourcePolicy;
use RuntimeException;

final class Snapshot
{
    public const CORE = ['member', 'order', 'order_item', 'platform_access'];

    public readonly LegacyUserBundle $bundle;

    public array $core = ['member' => [], 'order' => [], 'order_item' => [], 'platform_access' => []];

    public array $pages = [];

    public array $money = [];

    public array $qualityIssues = [];

    public function __construct(
        public readonly string $directory,
        string $snapshot,
        string $mappingVersion,
        private readonly LegacyUserSourcePolicy $policy,
        LegacyUserRecordMapper $mapper,
    ) {
        ReadinessFiles::directory($directory);
        ReadinessFiles::file($directory.'/manifest.json');
        $this->bundle = new LegacyUserBundle($directory, $policy);
        if ($snapshot !== $this->bundle->manifest['snapshot_id']
            || $mappingVersion !== (string) $this->bundle->manifest['mapping_version']) {
            throw new RuntimeException('Explicit snapshot identity is required.');
        }
        $this->verifyUnchanged();
        $profiles = $orders = [];
        foreach ($this->bundle->tableOrder() as $table) {
            foreach ($this->bundle->rows($table) as $record) {
                $id = $record['source_id'];
                if ($table === 'platform_page') {
                    $this->pages[$id] = true;
                }
                if (in_array($table, ['member_data_full', 'tags'], true) && $record['ownership_status'] === 'linked') {
                    $member = $record['source_member_id'];
                    $this->core['member'][$member]['hash'] = hash('sha256', $this->core['member'][$member]['hash']
                        .$table.ReadinessFiles::json($policy->sanitize($table, $record['row'])));
                }
                if (! in_array($table, self::CORE, true)) {
                    continue;
                }
                if ($table === 'member') {
                    $profiles[$id] = -$id;
                } elseif ($table === 'order') {
                    $orders[$id] = -$id;
                }
                $safe = $policy->sanitize($table, $record['row']);
                foreach ($safe['issues'] as $issue) {
                    $key = $table.'.'.$issue['field'].'.'.$issue['reason'];
                    $this->qualityIssues[$key] = ($this->qualityIssues[$key] ?? 0) + 1;
                }
                $mapped = $mapper->attributes($table, $record, $profiles, $orders, []);
                if ($table === 'order' && ! is_int($mapped['source_status'])) {
                    throw new RuntimeException('Invalid source order status type.');
                }
                // Preflight totals before Importer::preview, whose sums assume integer headroom.
                $moneyFields = match ($table) {
                    'order' => ['amount' => 'amount_minor'],
                    'order_item' => ['priceSum' => 'unit_amount_minor', 'coupon_priceSum' => 'coupon_unit_amount_minor', 'cost' => 'cost_minor'],
                    default => [],
                };
                foreach ($moneyFields as $field => $destination) {
                    $value = $record['row'][$field] ?? null;
                    $minor = $value === null && $field === 'coupon_priceSum' ? null : ExactMoney::minor($value);
                    self::total($this->money, $table.'.'.$destination, $minor);
                    if ($table === 'order') {
                        self::total($this->money, 'order.status.'.$mapped['source_status'], $minor);
                    }
                }
                $fields = match ($table) {
                    'member' => [],
                    'order' => ['source_status', 'amount_minor'],
                    'order_item' => ['source_order_id', 'source_product_id', 'quantity', 'unit_amount_minor', 'coupon_unit_amount_minor', 'cost_minor'],
                    'platform_access' => ['source_page_id', 'source_subject_id'],
                };
                $entry = array_intersect_key($mapped, array_flip($fields)) + [
                    'source_member_id' => $record['source_member_id'],
                    'ownership_status' => $record['ownership_status'],
                    'hash' => hash('sha256', ReadinessFiles::json([$record['source_member_id'], $record['ownership_status'], $safe])),
                ];
                if ($table === 'platform_access') {
                    $kind = self::grantKind($record['row']['grantedBy'] ?? null);
                    $entry += [
                        'grant_kind' => $kind,
                        'source_order_id' => $kind === 'order' ? self::reference($record['row']['grantedById'] ?? null) : null,
                        'created_at' => $record['row']['dateCreated'] ?? null,
                    ];
                }
                $this->core[$table][$id] = $entry;
            }
        }
    }

    public static function total(array &$totals, string $key, mixed $value): void
    {
        $totals[$key] ??= ['rows' => 0, 'nulls' => 0, 'minor_units' => 0];
        $totals[$key]['rows']++;
        if ($value === null) {
            $totals[$key]['nulls']++;
        } else {
            $totals[$key]['minor_units'] = ExactMoney::add($totals[$key]['minor_units'], $value);
        }
    }

    public static function reference(mixed $value): ?int
    {
        if ($value === null || $value === 0) {
            return null;
        }
        if (! is_int($value) || $value < 0) {
            throw new RuntimeException('Invalid integer reference.');
        }

        return $value;
    }

    public static function grantKind(mixed $value): string
    {
        if (in_array($value, ['order', 'manual'], true)) {
            return $value;
        }

        return is_string($value) && str_starts_with($value, 'platform_access_') ? 'legacy_import_marker' : 'other';
    }

    public function verifyUnchanged(): void
    {
        ReadinessFiles::file($this->directory.'/manifest.json', $this->bundle->manifestHash);
        foreach ($this->bundle->manifest['tables'] as $entry) {
            if ($entry['file'] !== null) {
                ReadinessFiles::file($this->directory.'/'.$entry['file'], $entry['sha256']);
            }
        }
    }

    public function provenance(): array
    {
        return array_intersect_key($this->bundle->manifest, array_flip([
            'format', 'snapshot_id', 'snapshot_sha256', 'policy_sha256', 'source_database', 'mapping_version', 'source_rows', 'exported_rows',
        ])) + ['manifest_sha256' => $this->bundle->manifestHash, 'core_rows' => array_map('count', $this->core)];
    }

    public function compare(self $comparison): array
    {
        $a = $this->bundle->manifest;
        $b = $comparison->bundle->manifest;
        if ($a['snapshot_id'] === $b['snapshot_id'] || $a['snapshot_sha256'] === $b['snapshot_sha256']
            || $this->bundle->manifestHash === $comparison->bundle->manifestHash) {
            throw new RuntimeException('Comparison requires two distinct snapshots.');
        }
        foreach (['format', 'source_database', 'policy_sha256', 'mapping_version'] as $key) {
            if ($a[$key] !== $b[$key]) {
                throw new RuntimeException('Incompatible snapshot lineage.');
            }
        }
        $result = [];
        foreach (self::CORE as $table) {
            $before = $this->core[$table];
            $after = $comparison->core[$table];
            $changed = [];
            foreach (array_intersect_key($after, $before) as $id => $row) {
                if ($row['hash'] !== $before[$id]['hash']) {
                    $changed[] = $id;
                }
            }
            $result[$table] = [
                'added_source_ids' => array_keys(array_diff_key($after, $before)),
                'removed_source_ids' => array_keys(array_diff_key($before, $after)),
                'changed_source_ids' => $changed,
                'unchanged' => count(array_intersect_key($after, $before)) - count($changed),
            ];
        }

        return $result;
    }
}
