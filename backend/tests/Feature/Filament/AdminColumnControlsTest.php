<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\FinancialReport;
use App\Filament\Pages\Operations\Orders;
use App\Filament\Pages\Operations\Promos;
use App\Filament\Pages\Operations\ShortLinks;
use App\Filament\Pages\Operations\SupportTickets;
use App\Filament\Pages\Operations\Tasks;
use App\Filament\Pages\Reviews\Reviews;
use App\Filament\Pages\Users;
use App\Models\AdminShortLink;
use App\Models\AdminSupportTicket;
use App\Models\AdminTask;
use App\Models\Order;
use App\Models\PromoCode;
use App\Models\Review;
use App\Models\ReviewEvidence;
use App\Models\ReviewMedia;
use App\Models\User;
use App\Services\AdminFinancialReportService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminColumnControlsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create(['role' => 'admin_full']), 'web');
    }

    public function test_tasks_sort_before_pagination_keep_nulls_last_and_combine_literal_search_with_choices(): void
    {
        for ($day = 1; $day <= 27; $day++) {
            AdminTask::query()->create(['title' => "Task {$day}", 'priority' => 'normal', 'status' => 'open', 'due_at' => sprintf('2026-10-%02d 12:00:00', $day), 'created_by' => auth()->id()]);
        }
        AdminTask::query()->create(['title' => 'Undated', 'priority' => 'high', 'status' => 'done', 'created_by' => auth()->id()]);
        $literal = AdminTask::query()->create(['title' => 'Fix 100%_issue', 'priority' => 'high', 'status' => 'open', 'due_at' => '2026-10-28 12:00:00', 'created_by' => auth()->id()]);
        $page = Livewire::test(Tasks::class)->call('sortColumn', 'due_at');
        $this->assertSame('Task 1', $page->instance()->getTableRecords()->first()->title);
        $page->call('gotoPage', 2);
        $this->assertSame('Task 26', $page->instance()->getTableRecords()->first()->title);
        $page->call('chooseColumnFilter', 'priority', 'high')->assertSet('paginators.page', 1)
            ->filterTable('title', ['value' => '%_'])->assertCanSeeTableRecords([$literal]);
        $this->assertSame(1, $page->instance()->getTableRecords()->total());
        $page->call('chooseColumnFilter', 'status', 'done');
        $this->assertSame(0, $page->instance()->getTableRecords()->total());
        $page->call('clearColumnFilters', 'title')->assertSee('Undated');
        $page->call('chooseColumnFilter', 'priority', 'unexpected')->assertSet('tableFilters.priority.value', 'high');
    }

    public function test_support_and_short_links_apply_their_own_column_conditions(): void
    {
        $ticket = AdminSupportTicket::query()->create(['subject' => 'Invoice 100%', 'description' => 'Private', 'priority' => 'high', 'status' => 'open']);
        AdminSupportTicket::query()->create(['subject' => 'Invoice other', 'description' => 'Private', 'priority' => 'normal', 'status' => 'closed']);
        $page = Livewire::test(SupportTickets::class)->filterTable('subject', ['value' => '100%'])
            ->call('chooseColumnFilter', 'priority', 'high')->assertCanSeeTableRecords([$ticket]);
        $this->assertSame(1, $page->instance()->getTableRecords()->total());

        $link = AdminShortLink::query()->create(['slug' => 'anatomy', 'target_path' => '/courses/anatomy', 'is_active' => false, 'created_by' => auth()->id()]);
        AdminShortLink::query()->create(['slug' => 'biology', 'target_path' => '/courses/biology', 'is_active' => true, 'created_by' => auth()->id()]);
        $page = Livewire::test(ShortLinks::class)->call('chooseColumnFilter', 'is_active', '0')
            ->filterTable('target_path', ['value' => 'anatomy'])->assertCanSeeTableRecords([$link]);
        $this->assertSame(1, $page->instance()->getTableRecords()->total());
        $page->call('chooseColumnFilter', 'is_active', null)->call('clearColumnFilters', 'target_path')->call('sortColumn', 'slug');
        $this->assertSame('anatomy', $page->instance()->getTableRecords()->first()->slug);
    }

    public function test_order_sort_search_and_date_range_preserve_full_amounts(): void
    {
        $buyer = User::factory()->create(['name' => 'Column buyer']);
        for ($amount = 1; $amount <= 27; $amount++) {
            $this->order($buyer, $amount * 100);
        }
        $page = Livewire::test(Orders::class)->call('sortColumn', 'amount_final');
        $this->assertSame(100, $page->instance()->getTableRecords()->first()['amount_final']);
        $page->call('gotoPage', 2);
        $this->assertSame(2600, $page->instance()->getTableRecords()->first()['amount_final']);
        $page->filterTable('customer', ['value' => 'Column buyer'])->call('chooseColumnFilter', 'access_status', 'granted');
        $this->assertSame(27, $page->instance()->getTableRecords()->total());
        $page->filterTable('dates', ['from' => '2026-10-20', 'to' => '2026-10-01']);
        $this->assertSame(0, $page->instance()->getTableRecords()->total());
    }

    public function test_user_column_filters_preserve_scope_and_paid_order_sort_uses_count(): void
    {
        $first = User::factory()->create(['name' => 'Alpha', 'role' => 'user_no_purchase']);
        $second = User::factory()->create(['name' => 'Beta', 'role' => 'user_no_purchase']);
        $this->order($first, 90000);
        $this->order($second, 100);
        $this->order($second, 100);
        $page = Livewire::test(Users::class)->call('sortColumn', 'paid_orders');
        $this->assertSame($first->id, $page->instance()->getTableRecords()->first()['id']);
        $page->call('sortColumn', 'paid_orders');
        $this->assertSame($second->id, $page->instance()->getTableRecords()->first()['id']);
        $page->filterTable('contact', ['value' => $first->email]);
        $this->assertSame(1, $page->instance()->getTableRecords()->total());

        $manager = User::factory()->create(['role' => 'manager']);
        $first->update(['manager_id' => $manager->id]);
        $scoped = Livewire::actingAs($manager, 'web')->test(Users::class)
            ->filterTable('contact', ['value' => $second->email]);
        $this->assertSame(0, $scoped->instance()->getTableRecords()->total());
        $scoped->filterTable('contact', ['value' => $first->email])->assertSee($first->email)
            ->set('tableFilters.scope.value', 'staff')->assertForbidden();
    }

    public function test_promo_numeric_sort_and_type_filter_do_not_expose_code_hash(): void
    {
        foreach ([30, 10, 20] as $limit) {
            PromoCode::query()->create([
                'code_hash' => hash('sha256', "COLUMN-{$limit}"), 'status' => 'active', 'discount_kind' => 'percent_bps',
                'discount_bps' => 1000, 'currency' => 'RUB', 'rules_version' => 'test',
                'expires_at' => now()->addDay(), 'activation_limit' => $limit, 'per_user_limit' => 1,
            ]);
        }
        $page = Livewire::test(Promos::class)->call('sortColumn', 'activation_limit');
        $this->assertSame(10, $page->instance()->getTableRecords()->first()['activation_limit']);
        $page->call('sortColumn', 'usage_count')->assertDontSee(hash('sha256', 'COLUMN-10'))
            ->call('chooseColumnFilter', 'discount_kind', 'fixed_minor');
        $this->assertSame(0, $page->instance()->getTableRecords()->total());
    }

    public function test_author_photo_and_screenshot_filters_are_independent(): void
    {
        $avatar = Review::query()->create(['display_name' => 'Avatar only', 'text' => 'Avatar text', 'author_photo_path' => 'review-authors/avatar.webp', 'media_managed' => true]);
        $screen = Review::query()->create(['display_name' => 'Screenshot only', 'text' => 'Screenshot text', 'media_managed' => true]);
        ReviewMedia::query()->create(['review_id' => $screen->id, 'type' => 'attachment', 'url' => '/storage/review-screenshots/screen.webp']);
        $evidence = Review::query()->create(['display_name' => 'Legacy screen', 'text' => 'Legacy text', 'media_managed' => false]);
        ReviewEvidence::query()->create(['review_id' => $evidence->id, 'type' => 'screenshot', 'is_public' => false]);
        $page = Livewire::test(Reviews::class)->call('chooseColumnFilter', 'author_photo_path', 'yes');
        $this->assertSame([$avatar->id], $page->instance()->getTableRecords()->pluck('id')->all());
        $page->call('chooseColumnFilter', 'media_count', 'yes');
        $this->assertSame(0, $page->instance()->getTableRecords()->total());
        $page->call('chooseColumnFilter', 'author_photo_path', 'no');
        $this->assertEqualsCanonicalizing([$screen->id, $evidence->id], $page->instance()->getTableRecords()->pluck('id')->all());
        $page->assertDontSee('/storage/review-screenshots/screen.webp');
    }

    public function test_report_orders_sort_entire_selection_and_export_matches_filtered_rows_without_changing_summary(): void
    {
        $buyer = User::factory()->create(['name' => 'Report buyer']);
        for ($amount = 1; $amount <= 28; $amount++) {
            $this->order($buyer, $amount * 100);
        }
        $other = User::factory()->create(['name' => 'Other buyer']);
        $this->order($other, 99900);
        $service = app(AdminFinancialReportService::class);
        $base = ['view' => 'workspace', 'source' => 'current', 'from' => now()->subDay()->toDateString(), 'to' => now()->addDay()->toDateString()];
        $normal = $service->report($base);
        $controls = ['order_customer' => 'Report buyer', 'order_sort' => 'amount:asc', 'page' => 2];
        $filtered = $service->report($base + $controls);
        $this->assertSame($normal['summary'], $filtered['summary']);
        $this->assertSame(28, $filtered['orders']['total']);
        $this->assertSame([2600, 2700, 2800], array_column($filtered['orders']['rows'], 'amount'));
        $export = $service->report($base + $controls + ['export' => 'orders']);
        $this->assertCount(28, $export['rows']);
        $this->assertSame(100, $export['rows'][0]['amount']);
        $byEmail = $service->report($base + ['order_customer' => $buyer->email]);
        $this->assertSame(28, $byEmail['orders']['total']);
        $page = Livewire::test(FinancialReport::class)->set('columnFilters.orders.customer', 'Report buyer')
            ->call('sortColumn', 'orders', 'amount')->assertSee('order_sort=amount%3Aasc', false);
        $page->call('goToPage', 2)->call('chooseColumnFilter', 'orders', 'status', 'paid')->assertSet('ordersPage', 1);
        $page->call('sortColumn', 'periods', 'bucket')->assertSet('columnSorts.periods', 'bucket:desc');
        Livewire::withQueryParams(['report' => ['from' => '2026-09-01', 'to' => '2026-09-30', 'source' => 'current'], 'ordersPage' => 2])
            ->test(FinancialReport::class)->assertSet('filters.from', '2026-09-01')->assertSet('ordersPage', 2);
    }

    public function test_previous_user_search_is_visible_and_can_be_cleared_without_losing_column_filters(): void
    {
        User::factory()->create(['name' => 'Anna previous', 'role' => 'user_no_purchase']);
        $boris = User::factory()->create(['name' => 'Boris current', 'role' => 'user_no_purchase']);
        $page = Livewire::withQueryParams(['q' => 'Anna previous'])->test(Users::class)
            ->assertSee('Search: Anna previous')
            ->filterTable('contact', ['value' => $boris->email]);
        $this->assertSame(0, $page->instance()->getTableRecords()->total());
        $page->call('resetTableSearch')->assertSet('q', '')->assertSet('tableSearch', '')
            ->assertSet('tableFilters.contact.value', $boris->email)->assertDontSee('Search: Anna previous');
        $this->assertSame($boris->id, $page->instance()->getTableRecords()->first()['id']);
    }

    public function test_native_tables_restore_header_conditions_and_sort_from_the_url(): void
    {
        foreach ([30, 10, 20] as $limit) {
            PromoCode::query()->create([
                'code_hash' => hash('sha256', 'URL-'.$limit), 'status' => $limit === 20 ? 'revoked' : 'active',
                'discount_kind' => 'percent_bps', 'discount_bps' => 1000, 'currency' => 'RUB',
                'rules_version' => 'test', 'expires_at' => now()->addDay(),
                'activation_limit' => $limit, 'per_user_limit' => 1,
            ]);
        }
        $promos = Livewire::withQueryParams(['filters' => ['status' => ['value' => 'active']], 'sort' => 'activation_limit:asc'])
            ->test(Promos::class)->assertSet('tableFilters.status.value', 'active')->assertSet('tableSort', 'activation_limit:asc');
        $this->assertSame([10, 30], $promos->instance()->getTableRecords()->pluck('activation_limit')->all());

        $user = User::factory()->create(['name' => 'URL user', 'role' => 'user_no_purchase']);
        User::factory()->create(['role' => 'user_no_purchase']);
        $users = Livewire::withQueryParams(['filters' => ['contact' => ['value' => $user->email]], 'sort' => 'name:asc'])
            ->test(Users::class)->assertSet('tableFilters.contact.value', $user->email)->assertSet('tableSort', 'name:asc');
        $this->assertSame([$user->id], $users->instance()->getTableRecords()->pluck('id')->all());
    }

    public function test_previous_report_search_is_visible_and_clearable_including_its_export_query(): void
    {
        $this->order(User::factory()->create(['name' => 'Previous report buyer']), 100);
        $this->order(User::factory()->create(['name' => 'Other report buyer']), 200);
        Livewire::withQueryParams(['q' => 'Previous report buyer'])->test(FinancialReport::class)
            ->assertSee('Сбросить общий поиск')
            ->assertViewHas('report', fn ($report) => $report['orders']['total'] === 1)
            ->call('clearPreviousSearch')->assertSet('filters.q', '')->assertSet('ordersPage', 1)
            ->assertDontSee('Сбросить общий поиск')
            ->assertViewHas('report', fn ($report) => $report['orders']['total'] === 2)
            ->assertViewHas('exportUrl', fn ($url) => ! str_contains($url, 'q='));
    }

    private function order(User $user, int $amount): Order
    {
        return Order::query()->create([
            'user_id' => $user->id, 'currency' => 'RUB', 'amount_total' => $amount, 'amount_discount' => 0,
            'amount_final' => $amount, 'amount_paid' => $amount, 'amount_refunded' => 0, 'payment_status' => 'paid', 'access_status' => 'granted',
        ]);
    }
}
