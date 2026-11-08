<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\FinancialReport;
use App\Models\Order;
use App\Models\User;
use App\Services\AdminFinancialReportService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminReportsPanelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_financial_page_is_only_available_with_report_permission(): void
    {
        foreach (['admin_limited', 'manager', 'teacher', 'user_no_purchase'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]), 'web')->get('/admin/reports/financial')->assertForbidden();
        }
        $this->actingAs(User::factory()->create(['role' => 'admin_full']), 'web')->get('/admin/reports/financial')->assertOk()->assertSee('Экспорт заказов CSV');
    }

    public function test_revocation_after_mount_blocks_report_refresh(): void
    {
        $actor = User::factory()->create(['role' => 'admin_full']);
        $page = Livewire::actingAs($actor, 'web')->test(FinancialReport::class);
        $actor->update(['role' => 'admin_limited']);
        $page->call('applyFilters')->assertForbidden();
    }

    public function test_report_filters_and_csv_url_preserve_shared_api_contract(): void
    {
        $actor = User::factory()->create(['role' => 'admin_full']);
        $buyer = User::factory()->create(['name' => 'Synthetic report buyer']);
        Order::query()->create(['user_id' => $buyer->id, 'currency' => 'RUB', 'amount_total' => 15000, 'amount_discount' => 500, 'amount_final' => 14500, 'amount_paid' => 14500, 'amount_refunded' => 0, 'payment_status' => 'paid', 'access_status' => 'granted']);
        $filters = ['source' => 'current', 'status' => 'paid', 'from' => now()->subDay()->toDateString(), 'to' => now()->addDay()->toDateString(), 'granularity' => 'day', 'q' => 'Synthetic report buyer'];
        Livewire::actingAs($actor, 'web')->test(FinancialReport::class)->set('filters', $filters)->call('applyFilters')
            ->assertSee('Synthetic report buyer')->assertSee('145,00 RUB')->assertSee('/backend/api/v1/admin/reports/financial?', false)->assertSee('export=orders', false);
        foreach ([[], ['view' => 'workspace']] as $version) {
            $query = [...$filters, ...$version];
            $expected = json_decode(json_encode(app(AdminFinancialReportService::class)->report($query)), true);
            $this->actingAs($actor, 'web')->getJson('/api/v1/admin/reports/financial?'.http_build_query($query))->assertOk()->assertExactJson($expected);
        }
        $this->actingAs($actor, 'web')->get('/api/v1/admin/reports/financial?'.http_build_query([...$filters, 'view' => 'workspace', 'export' => 'orders']))->assertOk()->assertDownload('report-orders.csv');
    }
}
