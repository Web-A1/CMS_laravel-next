<?php

namespace App\Services\LegacyUsers\Readiness;

use App\Services\LegacyUsers\LegacyUserIdentityPlan;
use App\Services\LegacyUsers\LegacyUserImporter;
use App\Services\LegacyUsers\LegacyUserRecordMapper;
use App\Services\LegacyUsers\LegacyUserSourcePolicy;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class ReadinessReport
{
    private array $findings = [];

    public function __construct(
        private readonly LegacyUserSourcePolicy $policy,
        private readonly LegacyUserRecordMapper $mapper,
        private readonly LegacyUserImporter $importer,
    ) {}

    public function build(Snapshot $baseline, ?Snapshot $comparison, ?Observations $observations, array $projection, string $targetHash): array
    {
        $this->findings = [];
        $archive = [];
        foreach (Snapshot::CORE as $table) {
            [$destination, $key] = $this->mapper->destination($table);
            $archive[$table] = [];
            foreach (DB::table($destination)->orderBy($key)->cursor() as $record) {
                $row = (array) $record;
                if (! is_int($row[$key]) || $row[$key] <= 0) {
                    throw new RuntimeException('Invalid archived source identity.');
                }
                $archive[$table][$row[$key]] = $row;
            }
        }
        [$candidates, $catalog] = $this->catalog($baseline);
        $preview = $this->importer->preview($baseline->bundle);
        // Omit target paths and legacy preview labels derived from raw source values.
        // Typed money/status totals are produced independently below.
        unset($preview['target'], $preview['source_dispositions'], $preview['orders']);
        $preview['target_fingerprint_scope'] = 'projected_columns_only';
        $profiles = array_column($archive['member'], 'id', 'source_member_id');
        $orders = array_column($archive['order'], 'id', 'source_order_id');
        foreach ($baseline->core['member'] as $id => $_) {
            $profiles[$id] ??= -$id;
        }
        foreach ($baseline->core['order'] as $id => $_) {
            $orders[$id] ??= -$id;
        }
        $identities = new LegacyUserIdentityPlan($baseline->bundle, $this->policy);
        $core = $relationships = [];
        foreach (Snapshot::CORE as $table) {
            $new = $changed = $fieldDrift = $links = [];
            $unchanged = 0;
            foreach ($baseline->bundle->rows($table) as $record) {
                $id = $record['source_id'];
                $old = $archive[$table][$id] ?? null;
                $mapped = $this->mapper->attributes($table, $record, $profiles, $orders, $candidates, $identities->questionPollIds);
                if ($table === 'member') {
                    // Same IdentityPlan and fingerprint composition as the existing importer.
                    $decision = $identities->decide($mapped, $old['user_id'] ?? null);
                    $managed = LegacyUserRecordMapper::json(array_intersect_key($decision, array_flip(['name', 'email', 'phone', 'profile', 'tags'])));
                    $mapped['source_fingerprint'] = hash('sha256', $mapped['source_fingerprint'].$managed.LegacyUserRecordMapper::json($decision['issues']));
                }
                if ($old === null) {
                    $new[] = $id;
                } elseif ($old['source_fingerprint'] !== $mapped['source_fingerprint']) {
                    $changed[] = $id;
                } else {
                    $unchanged++;
                }
                $fields = match ($table) {
                    'member' => ['source_member_id'],
                    'order' => ['source_member_id', 'ownership_status', 'source_status', 'amount_minor'],
                    'order_item' => ['source_member_id', 'ownership_status', 'source_order_id', 'source_product_id', 'quantity', 'unit_amount_minor', 'coupon_unit_amount_minor', 'cost_minor'],
                    'platform_access' => ['source_member_id', 'ownership_status', 'source_page_id', 'source_subject_id', 'candidate_lesson_id', 'mapping_status'],
                };
                if ($old !== null) {
                    $different = array_values(array_filter($fields, fn (string $field): bool => ($old[$field] ?? null) !== ($mapped[$field] ?? null)));
                    if ($different !== []) {
                        $fieldDrift[] = ['source_id' => $id, 'archive_row_id' => $old['id'], 'fields' => $different];
                    }
                }
                $reasons = [];
                $owner = $record['source_member_id'];
                if ($table !== 'member' && (! $owner || ! isset($baseline->core['member'][$owner]))) {
                    $reasons[] = 'source_owner_missing';
                }
                if ($table !== 'member' && $old !== null) {
                    $expected = $archive['member'][$owner]['id'] ?? null;
                    if ($old['legacy_user_profile_id'] !== $expected) {
                        $reasons[] = 'archive_profile_link_mismatch';
                    }
                    if ($owner && $expected === null) {
                        $reasons[] = 'archive_profile_missing';
                    }
                }
                if ($table === 'order_item') {
                    $order = $mapped['source_order_id'];
                    if (! isset($baseline->core['order'][$order])) {
                        $reasons[] = 'source_order_missing';
                    } elseif ($baseline->core['order'][$order]['source_member_id'] !== $owner) {
                        $reasons[] = 'source_order_owner_mismatch';
                    }
                    if ($old !== null && $old['legacy_user_order_id'] !== ($archive['order'][$order]['id'] ?? null)) {
                        $reasons[] = 'archive_order_link_mismatch';
                    }
                } elseif ($table === 'platform_access') {
                    $page = $mapped['source_page_id'];
                    if (! isset($baseline->pages[$page])) {
                        $reasons[] = 'source_page_missing';
                    }
                    if ($mapped['mapping_status'] !== 'unique_lesson_candidate') {
                        $reasons[] = $mapped['mapping_status'];
                    }
                    if (isset($catalog[$page]) && $catalog[$page]['inactive_or_missing_targets'] !== []) {
                        $reasons[] = 'inactive_or_missing_target';
                    }
                    $sourceOrder = $baseline->core[$table][$id]['source_order_id'];
                    if ($baseline->core[$table][$id]['grant_kind'] === 'order'
                        && (! $sourceOrder || ! isset($baseline->core['order'][$sourceOrder]))) {
                        $reasons[] = 'grant_order_missing';
                    } elseif ($baseline->core[$table][$id]['grant_kind'] === 'order'
                        && $baseline->core['order'][$sourceOrder]['source_member_id'] !== $owner) {
                        $reasons[] = 'grant_order_owner_mismatch';
                    }
                }
                if ($reasons !== []) {
                    sort($reasons);
                    $links[] = ['source_id' => $id, 'archive_row_id' => $old['id'] ?? null,
                        'source_member_id' => $owner, 'confirmed_test_profile' => $owner === 37476,
                        'reasons' => $reasons];
                }
            }
            $missing = array_keys(array_diff_key($archive[$table], $baseline->core[$table]));
            $core[$table] = [
                'source_rows' => count($baseline->core[$table]), 'archive_rows' => count($archive[$table]),
                'new_source_ids' => $new, 'changed_source_ids' => $changed, 'unchanged' => $unchanged,
                'missing_from_snapshot_source_ids' => $missing, 'projected_field_drift' => $fieldDrift,
            ];
            // Guard this additional presentation of identity fingerprints against semantic drift.
            foreach (['new' => count($new), 'changed' => count($changed), 'unchanged' => $unchanged] as $state => $count) {
                if (($preview['changes'][$table][$state] ?? 0) !== $count) {
                    throw new RuntimeException('Readiness and importer preview disagree.');
                }
            }
            $relationships[$table] = $links;
            $this->finding('archive_core_difference', $table, count($new) + count($changed) + count($missing) + count($fieldDrift));
            $this->finding('relationship_issues', $table, count($links));
        }
        $targetMoney = [];
        foreach (['order' => ['amount_minor'], 'order_item' => ['unit_amount_minor', 'coupon_unit_amount_minor', 'cost_minor']] as $table => $fields) {
            foreach ($archive[$table] as $row) {
                foreach ($fields as $field) {
                    Snapshot::total($targetMoney, $table.'.'.$field, $row[$field]);
                }
                if ($table === 'order') {
                    Snapshot::total($targetMoney, 'order.status.'.$row['source_status'], $row['amount_minor']);
                }
            }
        }
        $moneyEqual = ReadinessFiles::json($baseline->money) === ReadinessFiles::json($targetMoney);
        $this->finding('money_totals_differ', 'projected_money_fields', $moneyEqual ? 0 : 1);
        $drift = $comparison ? $baseline->compare($comparison) : null;
        if ($drift === null) {
            $this->finding('full_comparison_missing', 'four_core_tables', 1);
        } else {
            foreach ($drift as $table => $change) {
                $this->finding('snapshot_core_drift', $table, count($change['added_source_ids']) + count($change['changed_source_ids']) + count($change['removed_source_ids']));
            }
            $this->finding('comparison_source_quality_issues', 'core', array_sum($comparison->qualityIssues));
        }
        $this->finding('source_preview_issues', 'source_policy', array_sum($preview['issues']));
        $observed = $observations?->report($baseline, $archive);
        if ($observed !== null) {
            foreach (['baseline_accesses', 'archive_accesses', 'baseline_member_ids', 'archive_member_ids', 'baseline_page_ids'] as $scope) {
                $delta = $observed[$scope];
                $this->finding('observed_difference', $scope, count($delta['added_source_ids']) + count($delta['changed_source_ids']) + count($delta['removed_source_ids'] ?? []));
            }
            $this->finding('observed_order_status_change', 'orders', count(array_filter($observed['order_status_observations'], fn (array $row): bool => $row['changed'])));
        }
        $holds = $this->holds();
        $this->finding('linked_profile_missing_user', 'target_users', $holds['linked_profile_missing_user']);
        $this->finding('imported_user_not_held', 'target_users', $holds['not_held']);
        $this->finding('existing_imported_user_grants', 'target_grants', array_sum($holds['grant_status_counts']));
        usort($this->findings, fn (array $a, array $b): int => [$a['code'], $a['scope']] <=> [$b['code'], $b['scope']]);

        return [
            'format' => 'legacy_users_readiness_report_v1',
            'inputs' => ['baseline' => $baseline->provenance(), 'comparison' => $comparison?->provenance()],
            'target' => ['kind' => 'immutable_sqlite_projection', 'sha256' => $targetHash, 'provenance' => $projection,
                'native_postgresql_integration' => 'not_tested', 'scope' => 'only_declared_projected_columns'],
            'import_preview' => $preview, 'core_reconciliation' => $core, 'relationships' => $relationships,
            'catalog_candidates' => array_values($catalog),
            'money' => ['currency' => 'RUB', 'basis' => 'same_defined_fields_in_minor_units_no_subtotal_inference',
                'baseline' => $baseline->money, 'archive' => $targetMoney, 'totals_equal' => $moneyEqual],
            'snapshot_comparison' => ['completeness' => $comparison ? 'complete_for_four_core_tables' : 'unknown',
                'direction' => 'declared_by_caller', 'chronology_verified' => false, 'drift' => $drift],
            'observations' => $observed, 'confirmed_exceptions' => $this->exception($baseline, $archive),
            'imported_user_state' => $holds, 'findings' => $this->findings,
            'technical_reconciliation' => $this->findings === [] ? 'reconciled_as_of_supplied_snapshots' : 'findings_or_incomplete_comparison',
            'exit_code' => $this->findings === [] ? 0 : 2,
            'activation' => ['current_freshness' => 'unknown', 'grant_authorized' => false, 'cutover_authorized' => false,
                'blockers' => ['current_atomic_source_not_proven', 'owner_activation_decision_not_part_of_this_tool'],
                'historical_access_is_entitlement' => false],
        ];
    }

    private function finding(string $code, string $scope, int $count): void
    {
        if ($count > 0) {
            $key = $code.':'.$scope;
            $this->findings[$key] ??= ['code' => $code, 'scope' => $scope, 'count' => 0];
            $this->findings[$key]['count'] += $count;
        }
    }

    private function catalog(Snapshot $baseline): array
    {
        $candidates = $catalog = [];
        $pages = $baseline->pages;
        foreach ($baseline->core['platform_access'] as $access) {
            if ($access['source_page_id'] !== null) {
                $pages[$access['source_page_id']] = true;
            }
        }
        foreach ($pages as $page => $_) {
            $catalog[$page] = ['source_page_id' => $page, 'source_page_present' => isset($baseline->pages[$page]),
                'target_lesson_ids' => [], 'inactive_or_missing_targets' => [], 'mapping_ids' => []];
        }
        $mappings = DB::table('legacy_import_mappings as m')
            ->leftJoin('lesson_learning_contents as c', 'c.id', '=', 'm.target_id')
            ->leftJoin('lessons as l', 'l.id', '=', 'c.lesson_id')
            ->leftJoin('courses as co', 'co.id', '=', 'l.course_id')
            ->leftJoin('subjects as s', 's.id', '=', 'co.subject_id')
            ->where('m.source_table', 'platform_page')->where('m.target_domain', 'learning_lesson_body')
            ->where('m.target_type', 'lesson_learning_content')->where('m.mapping_status', 'mapped')
            ->orderBy('m.id')->select(['m.id', 'm.source_id', 'm.target_id', 'l.id as lesson_id',
                'l.is_active as lesson_active', 'co.is_active as course_active', 's.is_active as subject_active'])->get();
        foreach ($mappings as $mapping) {
            if (! preg_match('/\A[1-9]\d{0,17}\z/', $mapping->source_id)) {
                throw new RuntimeException('Invalid catalog source identity.');
            }
            $page = (int) $mapping->source_id;
            $catalog[$page] ??= ['source_page_id' => $page, 'source_page_present' => isset($baseline->pages[$page]),
                'target_lesson_ids' => [], 'inactive_or_missing_targets' => [], 'mapping_ids' => []];
            $catalog[$page]['mapping_ids'][] = $mapping->id;
            if ($mapping->lesson_id !== null) {
                $candidates[$page][] = $mapping->lesson_id;
            }
            if (! $mapping->lesson_id || ! $mapping->lesson_active || ! $mapping->course_active || ! $mapping->subject_active) {
                $catalog[$page]['inactive_or_missing_targets'][] = ['mapping_id' => $mapping->id, 'target_content_id' => $mapping->target_id];
            }
        }
        foreach ($candidates as $page => &$ids) {
            $ids = array_values(array_unique($ids));
            sort($ids, SORT_NUMERIC);
            $catalog[$page]['target_lesson_ids'] = $ids;
        }
        unset($ids);
        ksort($catalog, SORT_NUMERIC);
        foreach ($catalog as &$row) {
            $row['status'] = $row['source_page_id'] === 1195 ? 'blocked_page_1195'
                : (count($row['target_lesson_ids']) > 1 ? 'ambiguous_scoped_mapping'
                    : (count($row['target_lesson_ids']) === 1 ? 'unique_lesson_candidate' : 'unmapped_page'));
            $this->finding('catalog_source_page_missing', 'catalog', $row['source_page_present'] ? 0 : 1);
            $this->finding('catalog_target_inactive_or_missing', 'catalog', count($row['inactive_or_missing_targets']));
            $this->finding('catalog_ambiguous_candidate', 'catalog', count($row['target_lesson_ids']) > 1 ? 1 : 0);
            $this->finding('catalog_unmapped_or_blocked', 'catalog', in_array($row['status'], ['unmapped_page', 'blocked_page_1195'], true) ? 1 : 0);
        }
        unset($row);

        return [$candidates, $catalog];
    }

    private function holds(): array
    {
        $rows = DB::table('legacy_user_profiles as p')->leftJoin('users as u', 'u.id', '=', 'p.user_id')
            ->whereNotNull('p.user_id')->select(['u.id', 'u.migration_hold', 'u.password_state'])->get();
        $missing = $notHeld = 0;
        foreach ($rows as $row) {
            if ($row->id === null) {
                $missing++;
            } elseif ($row->migration_hold !== 1 || $row->password_state !== 'migration_hold') {
                $notHeld++;
            }
        }
        $grants = [];
        foreach (DB::table('user_entitlement_grants as g')->whereIn('g.user_id', DB::table('legacy_user_profiles')->select('user_id')->whereNotNull('user_id'))
            ->select('g.status')->cursor() as $row) {
            $status = in_array($row->status, ['active', 'revoked', 'expired'], true) ? $row->status : 'other';
            $grants[$status] = ($grants[$status] ?? 0) + 1;
        }

        return ['linked_profiles' => count($rows), 'held' => count($rows) - $missing - $notHeld,
            'linked_profile_missing_user' => $missing, 'not_held' => $notHeld, 'grant_status_counts' => $grants];
    }

    private function exception(Snapshot $baseline, array $archive): array
    {
        $counts = [];
        foreach (Snapshot::CORE as $table) {
            $counts[$table] = [
                'baseline_rows' => count(array_filter($baseline->core[$table], fn (array $row): bool => $row['source_member_id'] === 37476)),
                'archive_rows' => count(array_filter($archive[$table], fn (array $row): bool => $row['source_member_id'] === 37476)),
            ];
        }

        return [['source_member_id' => 37476, 'reason' => 'owner_confirmed_test_profile',
            'decision_reference' => 'mvp-migration-readiness-handoff-20261012', 'decision_date' => '2026-10-12',
            'effect' => 'classification_only_counts_retained_no_restore_no_grant', 'counts' => $counts]];
    }
}
