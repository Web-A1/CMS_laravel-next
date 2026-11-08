<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\LegacyUsers\LegacyUserBundle;
use App\Services\LegacyUsers\LegacyUserImporter;
use App\Services\LegacyUsers\LegacyUserRecordMapper;
use App\Services\LegacyUsers\LegacyUserSourcePolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class LegacyUserImportTest extends TestCase
{
    use RefreshDatabase;

    private array $directories = [];

    protected function tearDown(): void
    {
        foreach ($this->directories as $directory) {
            foreach (glob($directory.'/*') as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
        parent::tearDown();
    }

    public function test_preview_is_read_only_and_import_preserves_finance_without_auth_or_active_grants(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        Mail::fake();
        Queue::fake();
        $existing = User::factory()->create(['id' => 13, 'email' => 'existing@example.test']);
        $before = (array) DB::table('users')->where('id', $existing->id)->first();
        $bundle = $this->bundle($this->records());
        $importer = app(LegacyUserImporter::class);
        $report = $importer->preview($bundle);
        $this->assertSame(['count' => 1, 'amount_minor' => 29], $report['orders'][3]);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('legacy_user_import_runs', 0);
        $this->assertDatabaseCount('legacy_user_profiles', 0);

        $result = $importer->apply($bundle, $report, $this->reportHash($report));
        $this->assertSame('completed', $result['status']);
        $this->assertSame($before, (array) DB::table('users')->where('id', $existing->id)->first());
        $profile = DB::table('legacy_user_profiles')->where('source_member_id', 13)->first();
        $user = User::findOrFail($profile->user_id);
        $this->assertNotSame(13, $user->id);
        $this->assertTrue($user->blocksAuthenticationForMigration());
        $this->assertSame('user_no_purchase', $user->role);
        $this->assertNull($user->manager_id);
        $this->assertNull($user->phone);
        $this->assertNull($user->email_verified_at);
        $this->assertStringStartsWith('!migration-held:', $user->getRawOriginal('password'));
        $this->assertDatabaseHas('user_profiles', ['user_id' => $user->id, 'school' => 'Example School']);
        $this->assertDatabaseHas('legacy_user_orders', ['source_order_id' => 5, 'amount_minor' => 29, 'source_status' => 3]);
        $this->assertDatabaseHas('legacy_user_order_items', ['source_item_id' => 6, 'cost_minor' => -1]);
        $this->assertDatabaseHas('legacy_user_accesses', ['source_access_id' => 7, 'mapping_status' => 'blocked_page_1195', 'candidate_lesson_id' => null]);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('user_entitlement_grants', 0);
        $this->assertDatabaseCount('password_reset_tokens', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseMissing('legacy_user_records', ['source_table' => 'chat_msg']);
        $this->assertStringNotContainsString('must-not-enter-history', $profile->payload);
        $again = $importer->preview($bundle);
        foreach ($again['changes'] as $counts) {
            $this->assertArrayNotHasKey('new', $counts);
            $this->assertArrayNotHasKey('changed', $counts);
        }
        $this->assertSame('already_completed', $importer->apply($bundle, $report, $this->reportHash($report))['status']);
        $this->assertDatabaseCount('legacy_user_record_versions', 0);
        Http::assertNothingSent();
        Mail::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_failed_run_resumes_committed_chunks_and_source_refresh_preserves_manual_edits(): void
    {
        config(['legacy_users.chunk_size' => 1]);
        $records = $this->records();
        $bundle = $this->bundle($records);
        $importer = app(LegacyUserImporter::class);
        $report = $importer->preview($bundle);
        try {
            $importer->apply($bundle, $report, $this->reportHash($report), function (): void {
                throw new RuntimeException('Synthetic interruption after a committed chunk.');
            });
            $this->fail('Expected interruption.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Import stopped', $e->getMessage());
        }
        $this->assertDatabaseCount('legacy_user_profiles', 1);
        $this->assertDatabaseCount('legacy_import_mappings', 1);
        $this->assertSame('completed', $importer->apply($bundle, $report, $this->reportHash($report))['status']);
        $this->assertDatabaseCount('users', 2);
        $profile = DB::table('legacy_user_profiles')->where('source_member_id', 13)->first();
        DB::table('users')->where('id', $profile->user_id)->update(['name' => 'Edited by administrator']);
        DB::table('user_profiles')->where('user_id', $profile->user_id)->update(['school' => 'Manual school', 'revision' => 3]);
        $records['member'][0]['row']['firstname'] = 'Source update';
        $records['member_data_full'][0]['row']['university'] = 'Source school update';
        $records['order'][0]['row']['amount'] = '0.31';
        $updated = $this->bundle($records);
        $next = $importer->preview($updated);
        $this->assertSame(1, $next['changes']['member']['changed']);
        $importer->apply($updated, $next, $this->reportHash($next));
        $this->assertDatabaseHas('users', ['id' => $profile->user_id, 'name' => 'Edited by administrator']);
        $this->assertDatabaseHas('user_profiles', ['user_id' => $profile->user_id, 'school' => 'Manual school']);
        $this->assertDatabaseHas('legacy_user_orders', ['source_order_id' => 5, 'amount_minor' => 31]);
        $this->assertDatabaseHas('legacy_user_record_versions', ['record_type' => 'legacy_user_profiles', 'record_id' => $profile->id]);
        $issues = json_decode(DB::table('legacy_user_profiles')->where('id', $profile->id)->value('issues'), true);
        $this->assertContains(['field' => 'name', 'reason' => 'manual_change_preserved'], $issues);
        $this->assertContains(['field' => 'school', 'reason' => 'manual_change_preserved'], $issues);
        foreach ($importer->preview($updated)['changes'] as $counts) {
            $this->assertArrayNotHasKey('changed', $counts);
        }
    }

    public function test_contact_conflict_keeps_source_identity_separate_and_stale_approval_refuses_writes(): void
    {
        $existing = User::factory()->create(['email' => 'source13@example.test']);
        $bundle = $this->bundle($this->records());
        $importer = app(LegacyUserImporter::class);
        $report = $importer->preview($bundle);
        $this->assertSame(1, $report['identities']['historical_only']);
        DB::table('users')->where('id', $existing->id)->update(['name' => 'Target changed']);
        try {
            $importer->apply($bundle, $report, $this->reportHash($report));
            $this->fail('Stale report was accepted.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('target changed', $e->getMessage());
        }
        $this->assertDatabaseCount('legacy_user_import_runs', 0);
        $report = $importer->preview($bundle);
        $importer->apply($bundle, $report, $this->reportHash($report));
        $this->assertDatabaseHas('legacy_user_profiles', ['source_member_id' => 13, 'user_id' => null, 'mapping_status' => 'historical_only']);
        $this->assertFalse(User::findOrFail($existing->id)->blocksAuthenticationForMigration());
    }

    private function records(): array
    {
        $envelope = fn (int $id, ?int $member, string $state, array $row): array => ['source_id' => $id, 'source_member_id' => $member, 'ownership_status' => $state, 'row' => ['id' => $id] + $row];

        return [
            'member' => [
                $envelope(13, 13, 'identity', ['email' => 'source13@example.test', 'phone' => '89990000001', 'firstname' => 'Source', 'gender' => '', 'isAdmin' => 1, 'managerId' => 13]),
                $envelope(20, 20, 'identity', ['email' => 'source20@example.test', 'phone' => '+79990000001', 'firstname' => 'Another', 'gender' => '']),
            ],
            'member_data_full' => [$envelope(2, 13, 'linked', ['memberId' => 13, 'university' => 'Example School'])],
            'order' => [$envelope(5, 13, 'linked', ['memberId' => 13, 'amount' => '0.29', 'status' => 3])],
            'order_item' => [$envelope(6, 13, 'linked', ['order_id' => 5, 'product_id' => 10, 'name' => 'Example', 'quantity' => 1, 'priceSum' => '0.29', 'cost' => '-0.01'])],
            'platform_access' => [$envelope(7, 13, 'linked', ['memberId' => 13, 'pageId' => 1195])],
            'MemberHistory' => [$envelope(3, 13, 'linked', ['memberId' => 13, 'text' => 'An administrator note'])],
        ];
    }

    public function test_bundle_rejects_missing_fields_bad_owners_and_hidden_extra_fields(): void
    {
        foreach (['missing_field', 'extra_secret', 'invalid_owner', 'missing_member'] as $case) {
            $bundle = $this->bundle($this->records());
            $directory = end($this->directories);
            $table = in_array($case, ['missing_field', 'extra_secret'], true) ? 'member' : 'order';
            $path = $directory.'/'.$table.'.jsonl';
            $rows = array_map(fn (string $line): array => json_decode($line, true), file($path, FILE_IGNORE_NEW_LINES));
            if ($case === 'missing_field') {
                unset($rows[0]['row']['firstname']);
            }
            if ($case === 'extra_secret') {
                $rows[0]['row']['auth_key'] = 'must-not-enter-history';
            }
            if ($case === 'invalid_owner') {
                $rows[0]['ownership_status'] = 'reference';
            }
            if ($case === 'missing_member') {
                $rows[0]['source_member_id'] = 999;
                $rows[0]['row']['memberId'] = 999;
            }
            $body = implode('', array_map(fn (array $row): string => LegacyUserRecordMapper::json($row)."\n", $rows));
            file_put_contents($path, $body);
            $manifest = $bundle->manifest;
            $manifest['tables'][$table]['sha256'] = hash('sha256', $body);
            $manifest['tables'][$table]['dispositions'] = array_count_values(array_column($rows, 'ownership_status'));
            file_put_contents($directory.'/manifest.json', LegacyUserRecordMapper::json($manifest));
            try {
                $bad = new LegacyUserBundle($directory, app(LegacyUserSourcePolicy::class));
                iterator_to_array($bad->rows($table));
                $this->fail('Reader accepted '.$case);
            } catch (InvalidArgumentException) {
                $this->assertDatabaseCount('legacy_user_profiles', 0);
            }
        }
    }

    public function test_bundle_accepts_local_indirect_ownership_proofs_when_parent_links_match(): void
    {
        $bundle = $this->bundle($this->ownershipRecords(), $this->ownershipProofs());

        foreach (['order_item', 'ticket_msg', 'task', 'task_comment', 'pollAnswerValue', 'MailListReceiver', 'fingerprint_action', 'lottery_ticket', 'roulette_prize', 'cart', 'tags'] as $table) {
            $this->assertNotEmpty(iterator_to_array($bundle->rows($table)), $table);
        }
    }

    public function test_bundle_rejects_tampered_indirect_ownership_before_import_writes(): void
    {
        $cases = [
            'order child points at foreign order' => ['order_item', function (array &$records): void {
                $records['order_item'][0]['row']['order_id'] = 8;
            }],
            'ticket message points at foreign ticket' => ['ticket_msg', function (array &$records): void {
                $records['ticket_msg'][0]['row']['ticketId'] = 32;
            }],
            'task comment points at foreign task' => ['task_comment', function (array &$records): void {
                $records['task_comment'][0]['row']['taskId'] = 44;
            }],
            'poll answer value points at foreign answer' => ['pollAnswerValue', function (array &$records): void {
                $records['pollAnswerValue'][0]['row']['answer_id'] = 52;
            }],
            'email-owned row uses another unique member email' => ['MailListReceiver', function (array &$records): void {
                $records['MailListReceiver'][0]['row']['email'] = 'source20@example.test';
            }],
            'fingerprint-owned row points at foreign fingerprint' => ['fingerprint_action', function (array &$records): void {
                $records['fingerprint_action'][0]['row']['fingerprintId'] = 601;
            }],
            'member_or_order has conflicting direct member and order owner' => ['lottery_ticket', function (array &$records): void {
                $records['lottery_ticket'][0]['row']['orderId'] = 8;
            }],
            'order cart envelope names a foreign member' => ['cart', function (array &$records): void {
                $records['cart'][0]['source_member_id'] = 20;
            }],
        ];

        foreach ($cases as $case => [$table, $tamper]) {
            $records = $this->ownershipRecords();
            $tamper($records);
            $bundle = $this->bundle($records, $this->ownershipProofs());

            try {
                iterator_to_array($bundle->rows($table));
                $this->fail('Reader accepted tampered ownership: '.$case);
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('source ownership', $e->getMessage(), $case);
                $this->assertDatabaseCount('legacy_user_profiles', 0);
                $this->assertDatabaseCount('legacy_user_orders', 0);
                $this->assertDatabaseCount('legacy_user_records', 0);
            }
        }
    }

    public function test_survey_answers_validate_question_membership_in_the_source_poll(): void
    {
        $records = $this->records();
        $records['pollAnswer'] = [['source_id' => 9, 'source_member_id' => 13, 'ownership_status' => 'linked',
            'row' => ['id' => 9, 'memberId' => 13, 'poll_id' => 1, 'arr_values' => '{"poll_id":1,"q_2":"Yes","q_2_other":"Other answer"}']]];
        $bundle = $this->bundle($records);
        $record = iterator_to_array($bundle->rows('pollAnswer'))[9];
        $mapper = app(LegacyUserRecordMapper::class);
        $valid = $mapper->attributes('pollAnswer', $record, [13 => 1], [], [], [2 => 1]);
        $this->assertSame('Other answer', json_decode($valid['payload'], true)['arr_values']['q_2_other']);
        foreach ([[], [2 => 3]] as $questions) {
            $invalid = $mapper->attributes('pollAnswer', $record, [13 => 1], [], [], $questions);
            $this->assertArrayNotHasKey('arr_values', json_decode($invalid['payload'], true));
            $this->assertContains(['field' => 'arr_values', 'reason' => 'unknown_or_cross_poll_question'], json_decode($invalid['issues'], true));
        }
    }

    private function ownershipRecords(): array
    {
        $envelope = fn (int $id, ?int $member, string $state, array $row): array => ['source_id' => $id, 'source_member_id' => $member, 'ownership_status' => $state, 'row' => ['id' => $id] + $row];

        return [
            'member' => [
                $envelope(13, 13, 'identity', ['email' => 'source13@example.test', 'phone' => '+79990000013', 'firstname' => 'Source']),
                $envelope(20, 20, 'identity', ['email' => 'source20@example.test', 'phone' => '+79990000020', 'firstname' => 'Foreign']),
            ],
            'order' => [
                $envelope(5, 13, 'linked', ['memberId' => 13, 'amount' => '0.29', 'status' => 3, 'cartId' => 80]),
                $envelope(8, 20, 'linked', ['memberId' => 20, 'amount' => '0.41', 'status' => 3, 'cartId' => 81]),
            ],
            'order_item' => [$envelope(6, 13, 'linked', ['order_id' => 5, 'product_id' => 10, 'name' => 'Example', 'quantity' => 1, 'priceSum' => '0.29', 'cost' => '-0.01'])],
            'ticket' => [
                $envelope(30, 13, 'linked', ['memberId' => 13, 'text' => 'Ticket']),
                $envelope(32, 20, 'linked', ['memberId' => 20, 'text' => 'Foreign ticket']),
            ],
            'ticket_msg' => [$envelope(31, 13, 'linked', ['ticketId' => 30, 'text' => 'Ticket message'])],
            'task' => [
                $envelope(40, 13, 'linked', ['name' => 'Member task', 'itemType' => 'member', 'itemId' => 13]),
                $envelope(43, 13, 'linked', ['name' => 'Chat-owned task', 'itemType' => 'chat', 'itemId' => 700]),
                $envelope(44, 20, 'linked', ['name' => 'Foreign task', 'itemType' => 'member', 'itemId' => 20]),
                $envelope(45, null, 'unlinked', ['name' => 'Orphan chat task', 'itemType' => 'chat', 'itemId' => 701]),
                $envelope(46, 13, 'linked', ['name' => 'Lead-owned task', 'itemType' => 'leadMagnetContact', 'itemId' => 800]),
            ],
            'task_comment' => [$envelope(41, 13, 'linked', ['taskId' => 40, 'text' => 'Task comment'])],
            'task_linked_item' => [$envelope(42, 13, 'linked', ['taskId' => 40, 'itemType' => 'order', 'itemId' => 5])],
            'pollAnswer' => [
                $envelope(50, 13, 'linked', ['memberId' => 13, 'poll_id' => 1]),
                $envelope(52, 20, 'linked', ['memberId' => 20, 'poll_id' => 1]),
            ],
            'pollAnswerValue' => [$envelope(51, 13, 'linked', ['answer_id' => 50, 'question_id' => 101, 'poll_id' => 1, 'question_text' => 'Question', 'value_text' => 'Answer'])],
            'MailListReceiver' => [$envelope(60, 13, 'linked', ['listId' => 1, 'email' => 'source13@example.test'])],
            'fingerprint_member' => [
                $envelope(70, 13, 'linked', ['fingerprintId' => 600, 'memberId' => 13]),
                $envelope(71, 20, 'linked', ['fingerprintId' => 601, 'memberId' => 20]),
            ],
            'fingerprint_action' => [$envelope(72, 13, 'linked', ['fingerprintId' => 600, 'type' => 'visit'])],
            'lottery_ticket' => [$envelope(90, 13, 'linked', ['eventId' => 1, 'orderId' => 5, 'memberId' => 13, 'text' => 'Lottery'])],
            'roulette_prize' => [$envelope(91, 13, 'linked', ['prize' => 'Prize', 'memberId' => 13, 'buyAfterId' => 5])],
            'cart' => [$envelope(80, 13, 'linked', [])],
            'tags' => [$envelope(100, 13, 'linked', ['itemType' => 'member', 'itemId' => 13, 'tags' => 'legacy-tag'])],
        ];
    }

    private function ownershipProofs(): array
    {
        return [
            'chat_member_ids' => [
                '700' => 13,
                '701' => null,
            ],
            'lead_magnet_contact_member_ids' => [
                '800' => 13,
            ],
        ];
    }

    private function bundle(array $records, array $ownerProofs = []): LegacyUserBundle
    {
        $directory = sys_get_temp_dir().'/cms-legacy-import-test-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $this->directories[] = $directory;
        $policy = app(LegacyUserSourcePolicy::class);
        $manifest = ['format' => 'legacy_user_records_v1', 'status' => 'complete', 'snapshot_id' => basename($directory),
            'snapshot_sha256' => str_repeat('a', 64), 'policy_sha256' => hash_file('sha256', resource_path('legacy-users/source-policy-v1.json')),
            'source_database' => 'main_school_db', 'mapping_version' => 1, 'binary_files' => false, 'messenger_history' => false,
            'source_rows' => 0, 'exported_rows' => 0, 'tables' => []];
        if ($ownerProofs !== []) {
            $manifest['owner_proofs'] = $ownerProofs;
        }
        foreach ($policy->tables() as $table => $definition) {
            $rows = $records[$table] ?? [];
            foreach ($rows as &$row) {
                $defaults = array_fill_keys(array_keys(array_filter($definition['fields'], fn (array $field): bool => $field['mode'] !== 'archive')), null);
                $row['row'] += $defaults;
            }
            unset($row);
            $counts = array_count_values(array_column($rows, 'ownership_status'));
            $entry = ['source_rows' => count($rows), 'exported_rows' => count($rows), 'dispositions' => $counts, 'file' => null, 'sha256' => null];
            if ($definition['kind'] !== 'archive') {
                $body = implode('', array_map(fn (array $row): string => LegacyUserRecordMapper::json($row)."\n", $rows));
                file_put_contents($directory.'/'.$table.'.jsonl', $body);
                $entry['file'] = $table.'.jsonl';
                $entry['sha256'] = hash('sha256', $body);
            }
            $manifest['tables'][$table] = $entry;
            $manifest['source_rows'] += count($rows);
            $manifest['exported_rows'] += count($rows);
        }
        file_put_contents($directory.'/manifest.json', LegacyUserRecordMapper::json($manifest));

        return new LegacyUserBundle($directory, $policy);
    }

    private function reportHash(array $report): string
    {
        return hash('sha256', LegacyUserRecordMapper::json($report));
    }
}
