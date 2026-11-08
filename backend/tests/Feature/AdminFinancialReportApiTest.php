<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class AdminFinancialReportApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_returns_private_canonical_aggregates_and_source_state(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin_full']));

        $response = $this->getJson('/api/v1/admin/reports/financial')->assertOk();

        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $response
            ->assertJsonPath('meta.status', 'available')
            ->assertJsonPath('meta.report_version', 'admin-reports-v1')
            ->assertJsonPath('meta.source', 'canonical_new_schema')
            ->assertJsonPath('meta.timezone', 'Europe/Moscow')
            ->assertJsonPath('sections.sales.orders', 0)
            ->assertJsonPath('sections.global.registrations', 1)
            ->assertJsonPath('sections.lead_magnets.status', 'unavailable');
    }

    public function test_only_full_admin_can_read_financial_report_contract(): void
    {
        $this->getJson('/api/v1/admin/reports/financial')->assertUnauthorized();

        foreach (['admin_limited', 'manager', 'teacher', 'user_with_purchase'] as $role) {
            Sanctum::actingAs(User::factory()->create(['role' => $role]));
            $this->getJson('/api/v1/admin/reports/financial')->assertForbidden();
        }
    }

    public function test_report_series_serializes_money_as_numbers(): void
    {
        $user = User::factory()->create();
        Order::query()->create(['user_id' => $user->id, 'currency' => 'RUB', 'amount_total' => 10000, 'amount_discount' => 100, 'amount_final' => 9900, 'amount_paid' => 0, 'amount_refunded' => 0, 'payment_status' => 'pending_payment', 'access_status' => 'not_granted']);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin_full']));

        $response = $this->getJson('/api/v1/admin/reports/financial')->assertOk();

        $this->assertIsInt($response->json('series.0.amount'));
    }

    public function test_workspace_view_returns_unified_report_contract(): void
    {
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-09-15 12:00:00', 'UTC'));
        $user = User::factory()->create(['name' => 'Workspace buyer']);
        Order::query()->create(['user_id' => $user->id, 'currency' => 'RUB', 'amount_total' => 15000, 'amount_discount' => 500, 'amount_final' => 14500, 'amount_paid' => 14500, 'amount_refunded' => 0, 'payment_status' => 'paid', 'access_status' => 'granted']);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin_full']));

        $response = $this->getJson('/api/v1/admin/reports/financial?view=workspace&from=2026-09-01&to=2026-09-30');

        $response->assertOk()->assertJsonPath('meta.report_version', 'admin-reports-v2')->assertJsonPath('meta.timezone', 'Europe/Moscow')
            ->assertJsonPath('summary.orders', 1)->assertJsonPath('summary.paid_orders', 1)->assertJsonPath('summary.sold_courses', 0)
            ->assertJsonPath('comparison.has_data', false)
            ->assertJsonStructure(['meta', 'summary', 'comparison' => ['from', 'to', 'orders', 'sold_courses', 'paid_orders', 'paid_amount', 'has_data'], 'periods', 'orders' => ['rows'], 'breakdowns' => ['products' => ['rows']], 'leads' => ['rows'], 'options']);
    }

    public function test_workspace_orders_export_is_csv_and_formula_safe(): void
    {
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-09-15 12:00:00', 'UTC'));
        $user = User::factory()->create(['name' => '=Formula']);
        Order::query()->create(['user_id' => $user->id, 'currency' => 'RUB', 'amount_total' => 15000, 'amount_discount' => 0, 'amount_final' => 15000, 'amount_paid' => 15000, 'amount_refunded' => 0, 'payment_status' => 'paid', 'access_status' => 'granted']);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin_full']));

        $response = $this->get('/api/v1/admin/reports/financial?view=workspace&export=orders&from=2026-09-01&to=2026-09-30');

        $response->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        self::assertStringContainsString("'=Formula", $response->streamedContent());
    }

    public function test_workspace_uses_an_exclusive_moscow_end_boundary(): void
    {
        $user = User::factory()->create();
        $order = Order::query()->create(['user_id' => $user->id, 'currency' => 'RUB', 'amount_total' => 12000, 'amount_discount' => 0, 'amount_final' => 12000, 'amount_paid' => 12000, 'amount_refunded' => 0, 'payment_status' => 'paid', 'access_status' => 'granted']);
        DB::table('orders')->where('id', $order->id)->update(['created_at' => '2026-09-30 21:00:00']);
        DB::table('users')->where('id', $user->id)->update(['created_at' => '2026-09-30 21:00:00']);
        $admin = User::factory()->create(['role' => 'admin_full']);
        DB::table('users')->where('id', $admin->id)->update(['created_at' => '2026-01-01 00:00:00']);
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/admin/reports/financial?view=workspace&from=2026-09-01&to=2026-09-30')
            ->assertOk()->assertJsonPath('summary.orders', 0)->assertJsonPath('summary.registrations', 0);
        $this->getJson('/api/v1/admin/reports/financial?view=workspace&from=2026-10-01&to=2026-10-01')
            ->assertOk()->assertJsonPath('summary.orders', 1)->assertJsonPath('summary.registrations', 1);
    }

    public function test_workspace_leads_export_works_without_a_legacy_scope(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin_full']));

        $this->get('/api/v1/admin/reports/financial?view=workspace&source=current&export=leads')
            ->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
    }

    public function test_report_uses_moscow_boundaries_for_utc_stored_timestamps(): void
    {
        $user = User::factory()->create();
        $order = Order::query()->create([
            'user_id' => $user->id,
            'currency' => 'RUB',
            'amount_total' => 10000,
            'amount_discount' => 0,
            'amount_final' => 10000,
            'amount_paid' => 0,
            'amount_refunded' => 0,
            'payment_status' => 'pending_payment',
            'access_status' => 'not_granted',
        ]);
        DB::table('orders')->where('id', $order->id)->update(['created_at' => '2026-09-30 21:00:00']);

        Sanctum::actingAs(User::factory()->create(['role' => 'admin_full']));

        $response = $this->getJson('/api/v1/admin/reports/financial?from=2026-10-01&to=2026-10-01')->assertOk();

        $response
            ->assertJsonPath('meta.timezone', 'Europe/Moscow')
            ->assertJsonPath('meta.filters.from', '2026-10-01')
            ->assertJsonPath('sections.sales.orders', 1)
            ->assertJsonPath('series.0.bucket', '2026-10-01');
    }

    public function test_report_keeps_imported_legacy_history_in_a_separate_section(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin_full']));
        $runId = (string) Str::uuid();
        DB::table('legacy_user_import_runs')->insert([
            'id' => $runId, 'source_database' => 'main_school_db', 'snapshot_id' => 'snapshot-test', 'mapping_version' => 1,
            'manifest_sha256' => str_repeat('a', 64), 'approved_report_sha256' => str_repeat('b', 64), 'target_environment' => 'testing',
            'target_database' => ':memory:', 'status' => 'completed', 'checkpoints' => '{}', 'report' => '{}',
            'started_at' => '2026-09-30 00:00:00', 'finished_at' => '2026-09-30 01:00:00',
        ]);
        DB::table('legacy_user_profiles')->insert([
            'source_member_id' => 7001, 'display_name' => 'Исторический профиль', 'source_email' => 'history@example.test',
            'source_phone' => null, 'source_manager_id' => null, 'mapping_status' => 'historical_only', 'source_created_at' => '2026-09-30 21:00:00+00',
            'payload' => '{}', 'managed_values' => '{}', 'issues' => '{}', 'source_fingerprint' => str_repeat('c', 64),
            'first_import_run_id' => $runId, 'last_import_run_id' => $runId, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $profileId = (int) DB::getPdo()->lastInsertId();
        DB::table('legacy_user_orders')->insert([
            'source_order_id' => 8801, 'legacy_user_profile_id' => $profileId, 'source_member_id' => 7001, 'ownership_status' => 'linked',
            'source_status' => 3, 'amount_minor' => 279000, 'amount_decimal' => '2790.00', 'currency' => 'RUB', 'currency_basis' => 'legacy_cart_ui_rub',
            'recorded_at' => '2026-09-30 21:00:00+00', 'payload' => '{}', 'issues' => '{}', 'source_fingerprint' => str_repeat('d', 64),
            'first_import_run_id' => $runId, 'last_import_run_id' => $runId, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $orderId = (int) DB::getPdo()->lastInsertId();
        DB::table('legacy_user_order_items')->insert([
            'source_item_id' => 9901, 'source_order_id' => 8801, 'legacy_user_order_id' => $orderId, 'legacy_user_profile_id' => $profileId,
            'source_member_id' => 7001, 'ownership_status' => 'linked', 'source_product_id' => 77, 'title' => 'Курс', 'quantity' => 2,
            'unit_amount_minor' => 279000, 'coupon_unit_amount_minor' => null, 'cost_minor' => 279000, 'payload' => '{}', 'issues' => '{}',
            'source_fingerprint' => str_repeat('e', 64), 'first_import_run_id' => $runId, 'last_import_run_id' => $runId, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('legacy_user_records')->insert([
            'source_table' => 'LeadMagnetContact', 'source_id' => 10001, 'legacy_user_profile_id' => $profileId, 'source_member_id' => 7001,
            'source_order_id' => null, 'source_actor_id' => null, 'legacy_user_order_id' => null, 'ownership_status' => 'linked', 'category' => 'communications',
            'recorded_at' => '2026-09-30 21:00:00+00', 'payload' => '{}', 'issues' => '{}', 'source_fingerprint' => str_repeat('f', 64),
            'first_import_run_id' => $runId, 'last_import_run_id' => $runId, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->getJson('/api/v1/admin/reports/financial?from=2026-10-01&to=2026-10-01')
            ->assertOk()
            ->assertJsonPath('sections.legacy.status', 'partial')
            ->assertJsonPath('sections.legacy.orders.count', 1)
            ->assertJsonPath('sections.legacy.orders.unique_profiles', 1)
            ->assertJsonPath('sections.legacy.orders.amount_minor', 279000)
            ->assertJsonPath('sections.legacy.orders.by_status.0.label', 'Выдан')
            ->assertJsonPath('sections.legacy.items.quantity', 2)
            ->assertJsonPath('sections.legacy.registrations', 1)
            ->assertJsonPath('sections.legacy.lead_magnets.status', 'contacts_only')
            ->assertJsonPath('sections.legacy.lead_magnets.contacts', 1);

        $this->getJson('/api/v1/admin/reports/financial?view=workspace&from=2026-10-01&to=2026-10-01')
            ->assertOk()->assertJsonPath('meta.report_version', 'admin-reports-v2')
            ->assertJsonPath('summary.orders', 1)->assertJsonPath('summary.items', 2)->assertJsonPath('summary.sold_courses', 2)
            ->assertJsonPath('summary.lead_contacts', 1)->assertJsonPath('orders.rows.0.source', 'legacy')->assertJsonPath('orders.rows.0.product', 'Курс');

        $buyer = User::factory()->create(['name' => 'Current buyer']);
        $current = Order::query()->create([
            'user_id' => $buyer->id, 'currency' => 'RUB', 'amount_total' => 1000,
            'amount_discount' => 0, 'amount_final' => 1000, 'amount_paid' => 1000,
            'amount_refunded' => 0, 'payment_status' => 'paid', 'access_status' => 'granted',
        ]);
        DB::table('orders')->where('id', $current->id)->update(['created_at' => '2026-10-01 12:00:00']);
        $url = '/api/v1/admin/reports/financial?view=workspace&from=2026-10-01&to=2026-10-01&order_sort=amount:asc&per_page=1';
        $this->getJson($url)->assertOk()->assertJsonPath('orders.total', 2)
            ->assertJsonPath('orders.rows.0.source', 'current')->assertJsonPath('orders.rows.0.amount', 1000);
        $this->getJson($url.'&page=2')->assertOk()->assertJsonPath('orders.rows.0.source', 'legacy');
        $this->getJson($url.'&order_customer=history%40example.test')->assertOk()
            ->assertJsonPath('orders.total', 1)->assertJsonPath('orders.rows.0.source', 'legacy');
        $this->getJson($url.'&order_product=missing')->assertOk()->assertJsonPath('orders.total', 0);
        $this->getJson($url.'&order_sort=unexpected:desc')->assertUnprocessable();
    }
}
