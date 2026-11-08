<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminLegacyUserApiTest extends TestCase
{
    use RefreshDatabase;

    private ?string $runId = null;

    private function actor(string $role = 'admin_full'): User
    {
        $actor = User::factory()->create(['role' => $role]);
        Sanctum::actingAs($actor);

        return $actor;
    }

    public function test_legacy_identity_is_visible_on_current_user_card_and_search_respects_scope(): void
    {
        $manager = $this->actor('manager');
        $own = User::factory()->create(['name' => 'Новая Анна', 'manager_id' => $manager->id, 'phone' => null]);
        User::query()->whereKey($own->id)->update(['migration_hold' => true]);
        $own->refresh();
        $otherManager = User::factory()->create(['role' => 'manager']);
        $other = User::factory()->create(['name' => 'Чужой ученик', 'manager_id' => $otherManager->id]);

        $this->legacyProfile($own, [
            'source_member_id' => 9001,
            'display_name' => 'Legacy Анна',
            'source_email' => 'legacy-anna@example.test',
            'source_phone' => '+79995550123',
        ]);
        $this->legacyProfile($other, [
            'source_member_id' => 9002,
            'display_name' => 'Legacy чужой',
            'source_email' => 'legacy-other@example.test',
            'source_phone' => '+79995550999',
        ]);

        $this->getJson('/api/v1/admin/users?'.http_build_query(['q' => '9001']))
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $own->id)
            ->assertJsonPath('data.0.phone', null)
            ->assertJsonPath('data.0.migration_hold', true)
            ->assertJsonPath('data.0.legacy_profile.source_member_id', '9001')
            ->assertJsonPath('data.0.legacy_profile.phone', '+79995550123');

        $this->getJson('/api/v1/admin/users?'.http_build_query(['q' => '+7 (999) 555-01-23']))
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $own->id);
        $this->getJson('/api/v1/admin/users?'.http_build_query(['q' => '+79995550999']))
            ->assertOk()
            ->assertJsonPath('meta.total', 0);

        $this->getJson("/api/v1/admin/users/{$own->id}")
            ->assertOk()
            ->assertJsonPath('migration_hold', true)
            ->assertJsonPath('legacy_profile.source_member_id', '9001')
            ->assertJsonPath('legacy_profile.phone', '+79995550123');

        $this->getJson('/api/v1/admin/legacy-users?'.http_build_query(['q' => 'legacy-anna@example.test']))
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.source_member_id', '9001');
        $this->getJson('/api/v1/admin/legacy-users?unmapped_only=1')->assertForbidden();
        $this->getJson('/api/v1/admin/legacy-users/9002')->assertForbidden();
    }

    public function test_history_only_and_unresolved_legacy_records_are_admin_full_only(): void
    {
        $profile = $this->legacyProfile(null, [
            'source_member_id' => 9901,
            'display_name' => 'Legacy without canonical',
            'source_email' => 'legacy-only@example.test',
            'mapping_status' => 'history_only',
        ]);
        $this->legacyRecord($profile, [
            'source_table' => 'ticket',
            'source_id' => 501,
            'legacy_user_profile_id' => null,
            'source_member_id' => null,
            'ownership_status' => 'unlinked',
            'category' => 'support',
        ]);

        $this->actor('admin_limited');
        $this->getJson('/api/v1/admin/legacy-users?'.http_build_query(['q' => 'legacy-only@example.test']))
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/admin/legacy-users/9901')->assertForbidden();
        $this->getJson('/api/v1/admin/legacy-users/unresolved')->assertForbidden();

        $this->actor('admin_full');
        $this->getJson('/api/v1/admin/legacy-users?'.http_build_query(['unmapped_only' => 1]))
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.source_member_id', '9901')
            ->assertJsonPath('data.0.migration_hold', true);
        $this->getJson('/api/v1/admin/legacy-users/9901')
            ->assertOk()
            ->assertJsonPath('user_id', null)
            ->assertJsonPath('migration_hold', true);
        $this->getJson('/api/v1/admin/legacy-users/unresolved')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.source_table', 'ticket');
    }

    public function test_legacy_history_finance_access_records_and_redaction_are_scoped(): void
    {
        $this->actor('admin_full');
        $manager = User::factory()->create(['role' => 'manager']);
        $user = User::factory()->create(['manager_id' => $manager->id]);
        $profile = $this->legacyProfile($user, [
            'source_member_id' => 9101,
            'source_manager_id' => 77,
            'payload' => ['bonus_balance' => 1000, 'profile_url' => 'https://legacy.example.test/u/9101', 'safe_note' => 'ok'],
        ]);
        $this->legacyRecord($profile, [
            'source_table' => 'user',
            'source_id' => 77,
            'source_member_id' => null,
            'payload' => ['name' => 'Legacy manager'],
            'category' => 'profile',
        ]);
        $orderId = $this->legacyOrder($profile, [
            'source_order_id' => 7001,
            'source_status' => 2,
            'amount_minor' => 9007199254740993,
            'payload' => ['payment_url' => 'https://pay.example.test/order?token=secret', 'api_token' => 'secret', 'safe_note' => 'ok'],
        ]);
        $this->legacyOrderItem($profile, $orderId, [
            'source_item_id' => 8001,
            'source_order_id' => 7001,
            'source_product_id' => 3001,
            'cost_minor' => -100,
            'payload' => ['download_path' => '/private/files/manual.pdf', 'safe_note' => 'ok'],
        ]);
        $this->legacyAccess($profile, [
            'source_access_id' => 6001,
            'source_page_id' => 4001,
            'candidate_lesson_id' => 123,
        ]);
        $this->legacyRecord($profile, [
            'source_table' => 'platform_page',
            'source_id' => 4001,
            'category' => 'learning',
            'payload' => ['h1' => 'Legacy lesson'],
        ]);
        $this->legacyRecord($profile, [
            'source_table' => 'member_history',
            'source_id' => 5001,
            'source_actor_id' => 77,
            'category' => 'support',
            'payload' => ['comment' => 'Проверка доступа', 'receipt_url' => 'https://legacy.example.test/receipt.pdf'],
        ]);
        $this->legacyRecord($profile, [
            'source_table' => 'login_log',
            'source_id' => 5002,
            'category' => 'diagnostics',
            'payload' => ['ip_hash' => 'abcdef', 'safe_note' => 'ok'],
        ]);

        $this->getJson('/api/v1/admin/legacy-users/9101')
            ->assertOk()
            ->assertJsonPath('source_manager.name', 'Legacy manager')
            ->assertJsonPath('orders_by_status.0.currency', 'RUB')
            ->assertJsonPath('orders_by_status.0.amount_minor', '9007199254740993')
            ->assertJsonPath('access_count', 1);
        $this->getJson('/api/v1/admin/legacy-users/9101/orders')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.source_order_id', '7001')
            ->assertJsonPath('data.0.amount_minor', '9007199254740993')
            ->assertJsonPath('data.0.payload.payment_url', '[redacted-url]')
            ->assertJsonPath('data.0.payload.api_token', '[redacted]')
            ->assertJsonPath('data.0.items.0.cost_minor', '-100')
            ->assertJsonPath('data.0.items.0.payload.download_path', '[redacted-url]');
        $this->getJson('/api/v1/admin/legacy-users/9101/accesses')
            ->assertOk()
            ->assertJsonPath('data.0.source_access_id', '6001')
            ->assertJsonPath('data.0.source_page_id', '4001')
            ->assertJsonPath('data.0.title', 'Legacy lesson');
        $this->getJson('/api/v1/admin/legacy-users/9101/records?'.http_build_query(['category' => 'support']))
            ->assertOk()
            ->assertJsonPath('data.0.source_table', 'member_history')
            ->assertJsonPath('data.0.payload.receipt_url', '[redacted-url]');

        Sanctum::actingAs($manager);
        $this->getJson('/api/v1/admin/legacy-users/9101/records?'.http_build_query(['category' => 'diagnostics']))
            ->assertForbidden();
        $this->getJson('/api/v1/admin/legacy-users/unresolved')->assertForbidden();

        $permissions = array_values(array_diff(config('rbac.permissions.manager'), ['payments.read_order']));
        config()->set('rbac.permissions.manager', $permissions);
        $response = $this->getJson('/api/v1/admin/legacy-users/9101')->assertOk();
        $response->assertJsonPath('orders_by_status', null)->assertJsonMissingPath('payload.bonus_balance');
        $this->assertNotContains('orders', array_column($response->json('categories'), 'id'));
        $this->getJson('/api/v1/admin/legacy-users/9101/orders')->assertForbidden();
        $this->getJson('/api/v1/admin/legacy-users/9101/records?'.http_build_query(['category' => 'orders']))
            ->assertForbidden();
    }

    private function importRunId(): string
    {
        if ($this->runId) {
            return $this->runId;
        }

        $this->runId = (string) Str::uuid();
        DB::table('legacy_user_import_runs')->insert([
            'id' => $this->runId,
            'source_database' => 'legacy_test',
            'snapshot_id' => 'synthetic-admin-api',
            'mapping_version' => 1,
            'manifest_sha256' => str_repeat('a', 64),
            'approved_report_sha256' => str_repeat('b', 64),
            'target_environment' => 'testing',
            'target_database' => 'sqlite',
            'status' => 'applied',
            'checkpoints' => $this->encodeJson([]),
            'report' => null,
            'started_at' => now(),
            'finished_at' => now(),
        ]);

        return $this->runId;
    }

    private function revision(): array
    {
        return [
            'source_fingerprint' => str_repeat('c', 64),
            'first_import_run_id' => $this->importRunId(),
            'last_import_run_id' => $this->importRunId(),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    private function legacyProfile(?User $user, array $overrides = []): object
    {
        $row = array_merge([
            'source_member_id' => 9000 + random_int(1, 500),
            'user_id' => $user?->id,
            'display_name' => 'Legacy User',
            'source_email' => 'legacy-user@example.test',
            'source_phone' => '+79995550000',
            'source_manager_id' => null,
            'mapping_status' => $user ? 'linked' : 'history_only',
            'source_created_at' => now()->subYear(),
            'payload' => ['source' => 'legacy'],
            'managed_values' => [],
            'issues' => [],
        ], $overrides);
        $row['payload'] = $this->encodeJson($row['payload']);
        $row['managed_values'] = $this->encodeJson($row['managed_values']);
        $row['issues'] = $this->encodeJson($row['issues']);
        $id = DB::table('legacy_user_profiles')->insertGetId(array_merge($row, $this->revision()));

        return DB::table('legacy_user_profiles')->where('id', $id)->first();
    }

    private function legacyOrder(object $profile, array $overrides = []): int
    {
        $row = array_merge([
            'source_order_id' => 7000 + random_int(1, 500),
            'legacy_user_profile_id' => $profile->id,
            'source_member_id' => $profile->source_member_id,
            'ownership_status' => 'linked',
            'source_status' => 1,
            'amount_minor' => 12345,
            'amount_decimal' => '123.45',
            'currency' => 'RUB',
            'currency_basis' => 'legacy minor units',
            'recorded_at' => now()->subMonth(),
            'payload' => [],
            'issues' => [],
        ], $overrides);
        $row['payload'] = $this->encodeJson($row['payload']);
        $row['issues'] = $this->encodeJson($row['issues']);

        return (int) DB::table('legacy_user_orders')->insertGetId(array_merge($row, $this->revision()));
    }

    private function legacyOrderItem(object $profile, int $legacyOrderId, array $overrides = []): void
    {
        $row = array_merge([
            'source_item_id' => 8000 + random_int(1, 500),
            'source_order_id' => 7000,
            'legacy_user_order_id' => $legacyOrderId,
            'legacy_user_profile_id' => $profile->id,
            'source_member_id' => $profile->source_member_id,
            'ownership_status' => 'linked',
            'source_product_id' => null,
            'title' => 'Legacy item',
            'quantity' => 1,
            'unit_amount_minor' => 12345,
            'coupon_unit_amount_minor' => null,
            'cost_minor' => 12345,
            'payload' => [],
            'issues' => [],
        ], $overrides);
        $row['payload'] = $this->encodeJson($row['payload']);
        $row['issues'] = $this->encodeJson($row['issues']);
        DB::table('legacy_user_order_items')->insert(array_merge($row, $this->revision()));
    }

    private function legacyAccess(object $profile, array $overrides = []): void
    {
        $row = array_merge([
            'source_access_id' => 6000 + random_int(1, 500),
            'legacy_user_profile_id' => $profile->id,
            'source_member_id' => $profile->source_member_id,
            'ownership_status' => 'linked',
            'source_page_id' => null,
            'source_subject_id' => null,
            'candidate_lesson_id' => null,
            'mapping_status' => 'candidate',
            'recorded_at' => now()->subMonth(),
            'payload' => [],
            'issues' => [],
        ], $overrides);
        $row['payload'] = $this->encodeJson($row['payload']);
        $row['issues'] = $this->encodeJson($row['issues']);
        DB::table('legacy_user_accesses')->insert(array_merge($row, $this->revision()));
    }

    private function legacyRecord(object $profile, array $overrides = []): void
    {
        $row = array_merge([
            'source_table' => 'member_history',
            'source_id' => 5000 + random_int(1, 500),
            'legacy_user_profile_id' => $profile->id,
            'source_member_id' => $profile->source_member_id,
            'ownership_status' => 'linked',
            'source_order_id' => null,
            'source_actor_id' => null,
            'legacy_user_order_id' => null,
            'category' => 'support',
            'recorded_at' => now()->subWeek(),
            'payload' => [],
            'issues' => [],
        ], $overrides);
        $row['payload'] = $this->encodeJson($row['payload']);
        $row['issues'] = $this->encodeJson($row['issues']);
        DB::table('legacy_user_records')->insert(array_merge($row, $this->revision()));
    }

    private function encodeJson(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }
}
