<?php

namespace Tests\Feature;

use App\Services\LegacyUsers\Readiness\ReadOnlyProjection;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Events\Verified;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\Support\LegacyUserBundleFixtures as Fixtures;
use Tests\TestCase;

class LegacyUserReadinessCommandTest extends TestCase
{
    use RefreshDatabase;

    private Fixtures $fixtures;

    private int $run = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtures = new Fixtures;
        Http::preventStrayRequests();
        Http::fake();
        Mail::fake();
        Queue::fake();
    }

    protected function tearDown(): void
    {
        $this->fixtures->cleanup();
        parent::tearDown();
    }

    private function prepared(?array $records = null): array
    {
        $this->fixtures->mapping();
        $baseline = $this->fixtures->bundle($records ?? Fixtures::records());
        $this->fixtures->import($baseline);

        return [$baseline, $this->fixtures->projection()];
    }

    private function invoke(string $baseline, string $target, array $options = []): array
    {
        $report = $this->fixtures->root.'/reports/report-'.(++$this->run).'.json';
        $options += ['directory' => $baseline, '--snapshot' => 'baseline', '--mapping-version' => '1',
            '--expect-env' => 'testing', '--target' => $target, '--target-sha256' => hash_file('sha256', $target),
            '--expect-target' => 'fixture-target', '--report' => $report];
        $code = Artisan::call('legacy:users-readiness', $options);
        $stdout = Artisan::output();
        $output = $options['--report'];

        return [$code, is_file($output) && str_ends_with($output, '.json') ? json_decode(file_get_contents($output), true) : null,
            $stdout, $output];
    }

    public function test_two_complete_distinct_snapshots_reconcile_without_authorizing_activation_or_side_effects(): void
    {
        [$baseline, $target] = $this->prepared();
        $comparison = $this->fixtures->bundle(Fixtures::records(), 'comparison', str_repeat('b', 64));
        $before = hash_file('sha256', $target);
        $sourceBefore = hash_file('sha256', $baseline.'/manifest.json');
        $configuration = config('database');
        Event::fake([Registered::class, Login::class, Verified::class]);
        $queries = [];
        DB::listen(function ($event) use (&$queries): void {
            $queries[] = $event->sql;
        });
        [$code, $report, $stdout, $path] = $this->invoke($baseline, $target, ['--comparison-directory' => $comparison, '--comparison-snapshot' => 'comparison']);
        $this->assertSame(0, $code, $stdout);
        $this->assertSame('reconciled_as_of_supplied_snapshots', $report['technical_reconciliation']);
        $this->assertSame('unknown', $report['activation']['current_freshness']);
        $this->assertFalse($report['activation']['grant_authorized']);
        $this->assertFalse($report['activation']['cutover_authorized']);
        $this->assertSame(29, $report['money']['baseline']['order.amount_minor']['minor_units']);
        $this->assertSame(-1, $report['money']['baseline']['order_item.cost_minor']['minor_units']);
        $this->assertSame(1, $report['money']['baseline']['order_item.coupon_unit_amount_minor']['nulls']);
        $this->assertSame([], $report['findings']);
        $this->assertSame(1, $report['imported_user_state']['held']);
        $this->assertSame($before, hash_file('sha256', $target));
        $this->assertSame($sourceBefore, hash_file('sha256', $baseline.'/manifest.json'));
        $this->assertSame($configuration, config('database'));
        $this->assertSame(0600, fileperms($path) & 0777);
        $this->assertSame(0700, fileperms(dirname($path)) & 0777);
        $this->assertNotEmpty($queries);
        foreach ($queries as $query) {
            $this->assertMatchesRegularExpression('/\A\s*(select|pragma)\b/i', $query);
            $this->assertDoesNotMatchRegularExpression('/\b(insert|update|delete|create|alter|drop|replace|attach|vacuum)\b/i', $query);
        }
        $this->assertSame(1, DB::table('users')->value('migration_hold'));
        $this->assertDatabaseCount('user_entitlement_grants', 0);
        Http::assertNothingSent();
        Mail::assertNothingSent();
        Queue::assertNothingPushed();
        Event::assertNothingDispatched();
    }

    public function test_single_snapshot_is_incomplete_and_report_bytes_are_repeatable_and_private(): void
    {
        $records = Fixtures::records();
        $records['platform_access'][0]['row']['grantedBy'] = 'platform_access_CANARY_CONTACT@example.test';
        [$baseline, $target] = $this->prepared($records);
        [$code, $report, $stdout, $first] = $this->invoke($baseline, $target);
        [$again, , $secondStdout, $second] = $this->invoke($baseline, $target);
        $this->assertSame(2, $code, $stdout);
        $this->assertSame(2, $again, $secondStdout);
        $this->assertSame(file_get_contents($first), file_get_contents($second));
        $this->assertSame('unknown', $report['snapshot_comparison']['completeness']);
        $this->assertContains('full_comparison_missing', array_column($report['findings'], 'code'));
        foreach (['CANARY_CONTACT', 'synthetic13@example.test', '+79990000013', 'platform_access_CANARY_CONTACT@example.test'] as $secret) {
            $this->assertStringNotContainsString($secret, $stdout.$secondStdout.file_get_contents($first));
        }
        $summary = json_decode($stdout, true, 32, JSON_THROW_ON_ERROR);
        $this->assertSame(hash_file('sha256', $first), $summary['report_sha256']);
        $this->assertArrayNotHasKey('execution', $report);
        $this->assertArrayHasKey('execution', $summary);
    }

    public function test_full_snapshot_delta_has_added_changed_removed_ids_for_every_core_type(): void
    {
        $before = Fixtures::records();
        $before['member'][] = Fixtures::envelope(20, 20, 'identity', ['email' => 'second@example.test', 'phone' => '+79990000020', 'gender' => '']);
        $before['order'][] = Fixtures::envelope(8, 20, 'linked', ['memberId' => 20, 'amount' => '10.00', 'status' => 3]);
        $before['order_item'][] = Fixtures::envelope(10, 20, 'linked', ['order_id' => 8, 'product_id' => 10, 'quantity' => 1, 'priceSum' => '10.00', 'cost' => '10.00']);
        $before['platform_access'][] = Fixtures::envelope(14, 20, 'linked', ['memberId' => 20, 'pageId' => 90]);
        [$baseline, $target] = $this->prepared($before);
        $after = Fixtures::records();
        $after['member'][0]['row']['firstname'] = 'Changed';
        $after['order'][0]['row']['amount'] = '0.30';
        $after['order_item'][0]['row']['cost'] = '-0.02';
        $after['platform_access'][0]['row']['grantedBy'] = 'manual';
        $after['member'][] = Fixtures::envelope(30, 30, 'identity', ['email' => 'third@example.test', 'phone' => '+79990000030', 'gender' => '']);
        $after['order'][] = Fixtures::envelope(9, 30, 'linked', ['memberId' => 30, 'amount' => '11.00', 'status' => 3]);
        $after['order_item'][] = Fixtures::envelope(11, 30, 'linked', ['order_id' => 9, 'product_id' => 10, 'quantity' => 1, 'priceSum' => '11.00', 'cost' => '11.00']);
        $after['platform_access'][] = Fixtures::envelope(15, 30, 'linked', ['memberId' => 30, 'pageId' => 90]);
        $comparison = $this->fixtures->bundle($after, 'comparison', str_repeat('b', 64));
        [$code, $report, $stdout] = $this->invoke($baseline, $target, ['--comparison-directory' => $comparison, '--comparison-snapshot' => 'comparison']);
        $this->assertSame(2, $code, $stdout);
        foreach (['member' => [30, 13, 20], 'order' => [9, 5, 8], 'order_item' => [11, 6, 10], 'platform_access' => [15, 7, 14]] as $table => [$added, $changed, $removed]) {
            $this->assertSame([$added], $report['snapshot_comparison']['drift'][$table]['added_source_ids']);
            $this->assertSame([$changed], $report['snapshot_comparison']['drift'][$table]['changed_source_ids']);
            $this->assertSame([$removed], $report['snapshot_comparison']['drift'][$table]['removed_source_ids']);
        }
        // Reading the changed snapshot against the old archive checks new/changed/missing presentation too.
        [$code, $report, $stdout] = $this->invoke($comparison, $target, ['--snapshot' => 'comparison']);
        $this->assertSame(2, $code, $stdout);
        foreach (['member' => [30, 13, 20], 'order' => [9, 5, 8], 'order_item' => [11, 6, 10], 'platform_access' => [15, 7, 14]] as $table => [$added, $changed, $removed]) {
            $this->assertSame([$added], $report['core_reconciliation'][$table]['new_source_ids']);
            $this->assertSame([$changed], $report['core_reconciliation'][$table]['changed_source_ids']);
            $this->assertSame([$removed], $report['core_reconciliation'][$table]['missing_from_snapshot_source_ids']);
        }
    }

    public function test_refund_and_removed_access_are_scoped_observations_and_partial_scope_cannot_prove_absence(): void
    {
        [$baseline, $target] = $this->prepared();
        $scopes = [
            'accesses' => ['coverage' => 'complete', 'rows' => [], 'row_count' => 0],
            'members' => ['coverage' => 'complete', 'rows' => [13], 'row_count' => 1],
            'pages' => ['coverage' => 'complete', 'rows' => [90], 'row_count' => 1],
            'orders' => ['coverage' => 'partial', 'rows' => [['source_order_id' => 5, 'source_status' => 6, 'updated_at' => '2026-10-07 18:15:06']], 'row_count' => 1],
        ];
        foreach (['complete', 'partial'] as $coverage) {
            $scopes['accesses']['coverage'] = $coverage;
            $observations = $this->fixtures->observation($baseline, $scopes);
            [$code, $report, $stdout] = $this->invoke($baseline, $target, ['--observations' => $observations, '--observations-sha256' => hash_file('sha256', $observations)]);
            $this->assertSame(2, $code, $stdout);
            $this->assertSame($coverage === 'complete' ? [7] : null, $report['observations']['archive_accesses']['removed_source_ids']);
            $status = $report['observations']['order_status_observations'][0];
            $this->assertSame(3, $status['baseline_status']);
            $this->assertSame(6, $status['source_status']);
            $this->assertFalse($status['bank_refund_verified']);
            if ($coverage === 'complete') {
                $removed = $report['observations']['removed_archive_accesses'][0];
                $this->assertSame(5, $removed['source_order_id']);
                $this->assertFalse($removed['same_owner_page_observed']);
                $this->assertNotSame(7, $removed['archive_row_id']);
            }
            $this->assertSame('unknown', $report['snapshot_comparison']['completeness']);
        }
    }

    public function test_only_explicit_confirmed_profile_is_classified_including_profiles_without_purchases(): void
    {
        $records = Fixtures::records();
        foreach ([20, 37476] as $id) {
            $records['member'][] = Fixtures::envelope($id, $id, 'identity', ['email' => 'fixture'.$id.'@example.test', 'phone' => '+799900'.str_pad((string) $id, 5, '0', STR_PAD_LEFT), 'gender' => '']);
        }
        $records['platform_access'][] = Fixtures::envelope(8, 37476, 'linked', ['memberId' => 37476, 'pageId' => 90]);
        [$baseline, $target] = $this->prepared($records);
        [$code, $report, $stdout] = $this->invoke($baseline, $target);
        $this->assertSame(2, $code, $stdout);
        $this->assertSame([37476], array_column($report['confirmed_exceptions'], 'source_member_id'));
        $this->assertSame(3, $report['core_reconciliation']['member']['source_rows']);
        $this->assertSame(1, $report['confirmed_exceptions'][0]['counts']['platform_access']['baseline_rows']);
        $this->assertSame('owner_confirmed_test_profile', $report['confirmed_exceptions'][0]['reason']);
    }

    public function test_links_mapping_ambiguity_missing_page_inactive_target_and_blocked_page_are_findings(): void
    {
        $records = Fixtures::records();
        $records['platform_access'][] = Fixtures::envelope(8, 999, 'orphan', ['memberId' => 999, 'pageId' => 777]);
        $records['platform_access'][] = Fixtures::envelope(9, 13, 'linked', ['memberId' => 13, 'pageId' => 1195]);
        [$baseline] = $this->prepared($records);
        $this->fixtures->mapping(90, false);
        $target = $this->fixtures->projection();
        [$code, $report, $stdout] = $this->invoke($baseline, $target);
        $this->assertSame(2, $code, $stdout);
        $reasons = array_merge(...array_column($report['relationships']['platform_access'], 'reasons'));
        foreach (['ambiguous_scoped_mapping', 'source_page_missing', 'source_owner_missing', 'inactive_or_missing_target', 'blocked_page_1195'] as $reason) {
            $this->assertContains($reason, $reasons);
        }
    }

    public function test_money_drift_and_wrong_local_parent_links_are_detected_even_with_unchanged_fingerprints(): void
    {
        [$baseline] = $this->prepared();
        DB::table('legacy_user_orders')->update(['amount_minor' => 30]);
        DB::table('legacy_user_order_items')->update(['legacy_user_order_id' => null, 'cost_minor' => -2]);
        $target = $this->fixtures->projection();
        [$code, $report, $stdout] = $this->invoke($baseline, $target);
        $this->assertSame(2, $code, $stdout);
        $this->assertFalse($report['money']['totals_equal']);
        $this->assertSame(['amount_minor'], $report['core_reconciliation']['order']['projected_field_drift'][0]['fields']);
        $this->assertContains('archive_order_link_mismatch', $report['relationships']['order_item'][0]['reasons']);
        $this->assertSame(30, $report['money']['archive']['order.amount_minor']['minor_units']);
        $this->assertSame(29, $report['money']['baseline']['order.amount_minor']['minor_units']);
    }

    public function test_immutable_connection_rejects_actual_mutation_and_restores_default_connection(): void
    {
        [, $target] = $this->prepared();
        $hash = hash_file('sha256', $target);
        $before = config('database');
        try {
            app(ReadOnlyProjection::class)->read($target, $hash, 'fixture-target', function (): void {
                DB::table('users')->update(['migration_hold' => 0]);
            });
            $this->fail('Read-only SQLite accepted a mutation.');
        } catch (QueryException $error) {
            $this->assertStringContainsString('readonly', strtolower($error->getMessage()));
        }
        $this->assertSame($before, config('database'));
        $this->assertSame($hash, hash_file('sha256', $target));
        $this->assertFileDoesNotExist($target.'-journal');
    }

    public function test_invalid_targets_lineage_and_report_destinations_fail_without_default_database_fallback(): void
    {
        [$baseline, $target] = $this->prepared();
        $comparison = $this->fixtures->bundle(Fixtures::records());
        $existing = $this->fixtures->root.'/reports/existing.json';
        $this->fixtures->write($existing, '{"sentinel":true}');
        $link = $this->fixtures->root.'/linked.sqlite';
        symlink($target, $link);
        $cases = [
            ['--target-sha256' => str_repeat('0', 64)],
            ['--expect-target' => 'wrong'],
            ['--expect-env' => 'production'],
            ['--target' => $link],
            ['--target' => $this->fixtures->root.'/absent.sqlite', '--target-sha256' => str_repeat('a', 64)],
            ['--report' => $existing],
            ['--report' => $baseline.'/new-report.json'],
            ['--report' => base_path('../docs/private-report.json')],
            ['--comparison-directory' => $comparison, '--comparison-snapshot' => 'baseline'],
            ['--comparison-directory' => $comparison],
        ];
        foreach ($cases as $options) {
            [$code, , $stdout] = $this->invoke($baseline, $target, $options);
            $this->assertSame(1, $code, $stdout);
            $this->assertSame('failed', json_decode($stdout, true)['status']);
        }
        $this->assertSame('{"sentinel":true}', file_get_contents($existing));
        $this->assertFileDoesNotExist($this->fixtures->root.'/absent.sqlite');
        $this->fixtures->write($target.'-wal', '');
        [$code] = $this->invoke($baseline, $target);
        $this->assertSame(1, $code);
    }

    public function test_corrupt_json_checksums_duplicate_ids_ownership_and_float_money_fail_with_safe_errors(): void
    {
        [, $target] = $this->prepared();
        foreach (['manifest', 'checksum', 'duplicate', 'bad_owner', 'float'] as $case) {
            $records = Fixtures::records();
            if ($case === 'duplicate') {
                $records['order'][] = $records['order'][0];
            } elseif ($case === 'bad_owner') {
                $records['order'][0]['source_member_id'] = 999;
                $records['order'][0]['row']['memberId'] = 999;
            } elseif ($case === 'float') {
                $records['order'][0]['row']['amount'] = 0.29;
            }
            $baseline = $this->fixtures->bundle($records);
            if ($case === 'manifest') {
                $this->fixtures->write($baseline.'/manifest.json', '{"secret":"CANARY_TOKEN https://private.invalid/token",broken');
            } elseif ($case === 'checksum') {
                $this->fixtures->write($baseline.'/order.jsonl', "CANARY_CONTACT@example.test\n");
            }
            [$code, , $stdout] = $this->invoke($baseline, $target);
            $this->assertSame(1, $code, $case.': '.$stdout);
            foreach (['CANARY', 'private.invalid', '@example.test', 'SQLSTATE'] as $secret) {
                $this->assertStringNotContainsString($secret, $stdout);
            }
        }
    }

    public function test_observation_duplicate_ids_unknown_fields_and_false_complete_orders_are_rejected(): void
    {
        [$baseline, $target] = $this->prepared();
        foreach (['duplicate', 'unknown', 'complete_orders', 'wrong_lineage'] as $case) {
            $scopes = [
                'accesses' => ['coverage' => 'partial', 'rows' => [], 'row_count' => 0],
                'members' => ['coverage' => 'complete', 'rows' => $case === 'duplicate' ? [13, 13] : [13], 'row_count' => $case === 'duplicate' ? 2 : 1],
                'pages' => ['coverage' => 'complete', 'rows' => [90], 'row_count' => 1],
                'orders' => ['coverage' => $case === 'complete_orders' ? 'complete' : 'partial', 'rows' => [], 'row_count' => 0],
            ];
            $path = $this->fixtures->observation($baseline, $scopes);
            $data = json_decode(file_get_contents($path), true);
            if ($case === 'unknown') {
                $data['raw_grantedBy'] = 'CANARY_CONTACT@example.test';
            } elseif ($case === 'wrong_lineage') {
                $data['baseline_manifest_sha256'] = str_repeat('0', 64);
            }
            $this->fixtures->write($path, json_encode($data));
            [$code, , $stdout] = $this->invoke($baseline, $target, ['--observations' => $path, '--observations-sha256' => hash_file('sha256', $path)]);
            $this->assertSame(1, $code, $case.': '.$stdout);
            $this->assertStringNotContainsString('CANARY', $stdout);
        }
    }

    public function test_aggregate_money_overflow_fails_before_preview_or_report(): void
    {
        [, $target] = $this->prepared();
        $records = Fixtures::records();
        $records['order'] = [];
        for ($id = 5; $id < 15; $id++) {
            $records['order'][] = Fixtures::envelope($id, 13, 'linked', ['memberId' => 13, 'amount' => '9999999999999999.99', 'status' => 3]);
        }
        $baseline = $this->fixtures->bundle($records);
        [$code, , $stdout, $path] = $this->invoke($baseline, $target);
        $this->assertSame(1, $code, $stdout);
        $this->assertSame('input_validation', json_decode($stdout, true)['category']);
        $this->assertFileDoesNotExist($path);
    }

    public function test_invalid_status_and_untrusted_manifest_labels_never_enter_reports(): void
    {
        [, $target] = $this->prepared();
        foreach (['status', 'label'] as $case) {
            $records = Fixtures::records();
            if ($case === 'status') {
                $records['order'][0]['row']['status'] = 'CANARY_CONTACT@example.test';
            }
            $baseline = $this->fixtures->bundle($records);
            $manifest = json_decode(file_get_contents($baseline.'/manifest.json'), true);
            $manifest['tables']['order']['dispositions']['CANARY_LABEL@example.test'] = 0;
            $this->fixtures->write($baseline.'/manifest.json', json_encode($manifest));
            [$code, , $stdout, $path] = $this->invoke($baseline, $target);
            $this->assertSame($case === 'status' ? 1 : 2, $code);
            $this->assertStringNotContainsString('CANARY', $stdout.(is_file($path) ? file_get_contents($path) : ''));
        }
    }

    public function test_access_grant_order_must_belong_to_the_same_source_member(): void
    {
        $records = Fixtures::records();
        $records['member'][] = Fixtures::envelope(20, 20, 'identity', ['email' => 'second@example.test', 'phone' => '+79990000020', 'gender' => '']);
        $records['platform_access'][0]['source_member_id'] = 20;
        $records['platform_access'][0]['row']['memberId'] = 20;
        [$baseline, $target] = $this->prepared($records);
        [$code, $report, $stdout] = $this->invoke($baseline, $target);
        $this->assertSame(2, $code, $stdout);
        $this->assertContains('grant_order_owner_mismatch', $report['relationships']['platform_access'][0]['reasons']);
    }

    public function test_missing_truncated_count_mismatched_and_nonprivate_source_files_fail(): void
    {
        [, $target] = $this->prepared();
        foreach (['missing', 'truncated', 'count', 'permissions'] as $case) {
            $baseline = $this->fixtures->bundle(Fixtures::records());
            if ($case === 'missing') {
                unlink($baseline.'/order.jsonl');
            } elseif ($case === 'truncated') {
                $this->fixtures->write($baseline.'/order.jsonl', '{');
            } elseif ($case === 'count') {
                $manifest = json_decode(file_get_contents($baseline.'/manifest.json'), true);
                $manifest['tables']['order']['exported_rows']++;
                $manifest['exported_rows']++;
                $this->fixtures->write($baseline.'/manifest.json', json_encode($manifest));
            } else {
                chmod($baseline.'/order.jsonl', 0644);
            }
            [$code, , $stdout, $path] = $this->invoke($baseline, $target);
            $this->assertSame(1, $code, $case.': '.$stdout);
            $this->assertFileDoesNotExist($path);
        }
    }

    public function test_invalid_projection_schema_content_duplicates_and_permissions_fail_privately(): void
    {
        [$baseline] = $this->prepared();
        foreach (['schema', 'content', 'duplicate', 'permissions'] as $case) {
            $target = $this->fixtures->projection();
            if ($case === 'permissions') {
                chmod($target, 0644);
            } else {
                $pdo = new \PDO('sqlite:'.$target);
                $pdo->exec(match ($case) {
                    'schema' => 'CREATE VIEW secret_view AS SELECT email FROM users',
                    'content' => "UPDATE users SET email = 'CANARY_CONTACT@example.test'",
                    'duplicate' => 'INSERT INTO legacy_user_orders SELECT * FROM legacy_user_orders',
                });
                $pdo = null;
            }
            [$code, , $stdout, $path] = $this->invoke($baseline, $target);
            $this->assertSame(1, $code, $case.': '.$stdout);
            $this->assertStringNotContainsString('CANARY', $stdout);
            $this->assertStringNotContainsString('SQLSTATE', $stdout);
            $this->assertFileDoesNotExist($path);
        }
    }

    public function test_unheld_imported_user_and_existing_grant_are_findings_without_mutation(): void
    {
        [$baseline] = $this->prepared();
        $users = DB::table('users')->get(['id', 'email', 'phone', 'migration_hold', 'password_state'])->map(fn (object $row): array => (array) $row)->all();
        $users[0]['migration_hold'] = 0;
        $users[0]['password_state'] = 'set';
        $target = $this->fixtures->projection(['users' => $users,
            'user_entitlement_grants' => [['id' => 1, 'user_id' => $users[0]['id'], 'status' => 'active']]]);
        $hash = hash_file('sha256', $target);
        [$code, $report, $stdout] = $this->invoke($baseline, $target);
        $this->assertSame(2, $code, $stdout);
        $this->assertSame(1, $report['imported_user_state']['not_held']);
        $this->assertSame(['active' => 1], $report['imported_user_state']['grant_status_counts']);
        $this->assertContains('existing_imported_user_grants', array_column($report['findings'], 'code'));
        $this->assertSame($hash, hash_file('sha256', $target));
        $this->assertSame(1, DB::table('users')->value('migration_hold'));
        $this->assertDatabaseCount('user_entitlement_grants', 0);
    }
}
