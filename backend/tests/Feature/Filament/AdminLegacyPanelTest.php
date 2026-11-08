<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\LegacyUserDetail;
use App\Filament\Pages\LegacyUsers;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class AdminLegacyPanelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.frontend_url' => 'http://localhost:3300']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_imported_history_is_scoped_read_only_and_redacted(): void
    {
        $manager = User::factory()->create(['role' => 'manager']);
        $own = User::factory()->create(['manager_id' => $manager->id]);
        $other = User::factory()->create();
        $this->profile($own, '9101', 'Assigned legacy');
        $this->profile($other, '9102', 'Other legacy');
        $this->actingAs($manager, 'web');
        Livewire::test(LegacyUsers::class)->assertSee('Assigned legacy')->assertDontSee('Other legacy');
        Livewire::test(LegacyUserDetail::class, ['sourceMemberId' => '9101'])
            ->assertSee('Только чтение')->assertSee('Visible synthetic note')->assertSee('[redacted]')
            ->assertDontSee('synthetic-secret-value')->assertDontSee('https://private.example.test');
        $this->get('/admin/legacy-users/9102')->assertForbidden();
        Livewire::test(LegacyUsers::class)->set('unmappedOnly', true)->assertForbidden();
    }

    public function test_unmapped_history_is_full_admin_only_and_scope_is_rechecked_after_mount(): void
    {
        $actor = User::factory()->create(['role' => 'admin_full']);
        $this->profile(null, '9201', 'Unmapped synthetic');
        $this->actingAs($actor, 'web');
        Livewire::test(LegacyUsers::class)->set('unmappedOnly', true)->assertSee('Unmapped synthetic');
        $page = Livewire::test(LegacyUserDetail::class, ['sourceMemberId' => '9201']);
        $actor->update(['role' => 'admin_limited']);
        $page->set('tab', 'records')->assertForbidden();
        Livewire::test(LegacyUsers::class)->assertDontSee('Unmapped synthetic');
    }

    public function test_legacy_record_categories_do_not_bypass_permissions_on_livewire_updates(): void
    {
        $teacher = User::factory()->create(['role' => 'teacher']);
        $this->actingAs($teacher, 'web')->get('/admin/legacy-users')->assertForbidden();
        $teacher = User::factory()->create(['role' => 'manager']);
        $user = User::factory()->create(['manager_id' => $teacher->id]);
        $this->profile($user, '9301', 'Assigned synthetic history');
        $this->actingAs($teacher, 'web');
        Livewire::test(LegacyUserDetail::class, ['sourceMemberId' => '9301'])
            ->set('tab', 'records')->set('category', 'diagnostics')->assertForbidden();
        $page = Livewire::test(LegacyUserDetail::class, ['sourceMemberId' => '9301']);
        $user->update(['manager_id' => null]);
        $page->set('tab', 'records')->assertForbidden();
    }

    public function test_invalid_source_id_and_queries_are_rejected(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin_full']), 'web');
        $this->get('/admin/legacy-users/not-an-id')->assertNotFound();
        Livewire::test(LegacyUsers::class)->set('q', str_repeat('x', 256))->assertHasErrors(['q'])
            ->set('q', '')->call('search')->assertHasNoErrors();
    }

    public function test_column_search_mapping_and_dates_preserve_imported_history_scope(): void
    {
        $actor = User::factory()->create(['role' => 'admin_full']);
        $this->profile(User::factory()->create(), '9401', 'Linked history');
        $this->profile(null, '9402', 'Unmapped history');
        $page = Livewire::actingAs($actor, 'web')->test(LegacyUsers::class)
            ->set('columnFilters.legacy.contact', '9402@example.test')
            ->call('chooseColumnFilter', 'legacy', 'mapping', '0')
            ->assertSee('Unmapped history')->assertDontSee('Linked history');
        $page->call('clearColumnFilter', 'legacy', 'contact')
            ->call('chooseColumnFilter', 'legacy', 'mapping', '1')
            ->call('sortColumn', 'legacy', 'registered')
            ->assertSee('Linked history')->assertDontSee('Unmapped history');
        $page->set('columnFilters.legacy.registered.from', now()->toDateString())->assertDontSee('Linked history');
        Livewire::actingAs(User::factory()->create(['role' => 'manager']), 'web')->test(LegacyUsers::class)
            ->set('columnFilters.legacy.mapping', '0')->assertForbidden();
    }

    public function test_previous_url_conditions_are_visible_and_mapping_changes_replace_the_old_condition(): void
    {
        $this->profile(User::factory()->create(), '9501', 'Linked previous');
        $this->profile(null, '9502', 'Unmapped previous');
        Livewire::actingAs(User::factory()->create(['role' => 'admin_full']), 'web')
            ->withQueryParams(['q' => 'Unmapped previous', 'unmapped_only' => '1'])
            ->test(LegacyUsers::class)->assertSee('Сбросить общий поиск')
            ->assertSet('columnFilters.legacy.mapping', '0')->assertSet('unmappedOnly', false)
            ->assertSee('Unmapped previous')->assertDontSee('Linked previous')
            ->call('clearPreviousSearch')->assertSet('q', '')->assertDontSee('Сбросить общий поиск')
            ->call('chooseColumnFilter', 'legacy', 'mapping', '1')
            ->assertSee('Linked previous')->assertDontSee('Unmapped previous')
            ->call('chooseColumnFilter', 'legacy', 'mapping', '')
            ->assertSee('Linked previous')->assertSee('Unmapped previous');
    }

    private function profile(?User $user, string $sourceId, string $name): void
    {
        $run = (string) Str::uuid();
        DB::table('legacy_user_import_runs')->insert([
            'id' => $run, 'source_database' => 'synthetic', 'snapshot_id' => 'panel-fixture-'.$sourceId,
            'mapping_version' => 1, 'manifest_sha256' => str_repeat('a', 64),
            'approved_report_sha256' => str_repeat('b', 64), 'target_environment' => 'testing',
            'target_database' => 'sqlite', 'status' => 'applied', 'checkpoints' => '[]',
            'report' => null, 'started_at' => now(), 'finished_at' => now(),
        ]);
        DB::table('legacy_user_profiles')->insert([
            'source_member_id' => $sourceId, 'user_id' => $user?->id, 'display_name' => $name,
            'source_email' => 'synthetic-'.$sourceId.'@example.test', 'source_phone' => null,
            'source_manager_id' => null, 'mapping_status' => $user ? 'linked' : 'history_only',
            'source_created_at' => now()->subYear(),
            'payload' => json_encode(['note' => 'Visible synthetic note', 'password' => 'synthetic-secret-value', 'profile_url' => 'https://private.example.test'], JSON_THROW_ON_ERROR),
            'managed_values' => '[]', 'issues' => '[]', 'source_fingerprint' => str_repeat('c', 64),
            'first_import_run_id' => $run, 'last_import_run_id' => $run, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
