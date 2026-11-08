<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\StaffProfile;
use App\Http\Middleware\AdminPanelResponse;
use App\Http\Middleware\AdminPanelUrls;
use App\Models\User;
use App\Models\VideoWatermarkSession;
use App\Services\AdminImpersonationService;
use App\Support\ImpersonationContext;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Livewire\Mechanisms\HandleRequests\EndpointResolver;
use RuntimeException;
use Tests\TestCase;

class AdminPanelFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.frontend_url' => 'http://localhost:3300']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_guest_return_is_local_and_errors_are_private(): void
    {
        $response = $this->get('/admin/settings?next=https://attacker.test')
            ->assertRedirect('http://localhost:3300/auth?mode=login&next=%2Fadmin%2Fsettings');
        $this->assertPrivate($response);
        $this->assertPrivate($this->get('/admin/missing')->assertNotFound());
    }

    public function test_column_resizing_is_shared_by_table_pages_and_scoped_to_the_staff_user(): void
    {
        $actor = User::factory()->create(['role' => 'admin_full']);
        $this->actingAs($actor, 'web');

        foreach (['users', 'reviews', 'reports/financial', 'assessment-reviews', 'orders', 'support', 'tasks', 'promos', 'short-links'] as $page) {
            $response = $this->get('/admin/'.$page)->assertOk();
            $response->assertSee('data-admin-table-resizing data-user-id="'.$actor->id.'"', false)
                ->assertSee('data-ui="admin.table.resetWidths"', false)
                ->assertSee('admin.table.resizeColumn', false);
            $this->assertSame(1, substr_count($response->getContent(), 'data-admin-table-resizing'));
        }
    }

    public function test_old_admin_addresses_resolve_inside_authenticated_panel(): void
    {
        $actor = User::factory()->create(['role' => 'admin_full']);
        $this->actingAs($actor, 'web');
        $this->get('/admin')->assertRedirect('http://localhost:3300/admin/users');
        $this->assertPrivate($this->get('/admin/profile')->assertRedirect('http://localhost:3300/admin/settings'));
        $this->get('/admin/users/legacy?q=fixture')->assertRedirect('http://localhost:3300/admin/legacy-users?q=fixture');
        $this->get('/admin/users/legacy/99001?tab=orders')->assertRedirect('http://localhost:3300/admin/legacy-users/99001?tab=orders');
        $this->get('/admin/users/legacy/not-a-number')->assertNotFound();
    }

    public function test_all_four_staff_roles_share_the_web_guard_and_customers_are_denied(): void
    {
        foreach (['admin_full', 'admin_limited', 'manager', 'teacher'] as $role) {
            $actor = User::factory()->create(['role' => $role]);
            $this->assertTrue($actor->canAccessPanel(Filament::getPanel('admin')));
            $this->assertPrivate($this->actingAs($actor, 'web')->get('/admin/settings')->assertOk());
        }
        foreach (['user_no_purchase', 'user_with_purchase', 'guest'] as $role) {
            $actor = User::factory()->create(['role' => $role]);
            $this->assertFalse($actor->canAccessPanel(Filament::getPanel('admin')));
            $this->assertPrivate($this->actingAs($actor, 'web')->get('/admin/settings')->assertForbidden());
        }
    }

    public function test_rendered_links_and_assets_use_configured_origin_not_backend_or_forwarded_host(): void
    {
        $actor = User::factory()->create(['role' => 'admin_full']);
        $response = $this->actingAs($actor, 'web')
            ->withHeaders(['X-Forwarded-Host' => 'attacker.test', 'X-Forwarded-Proto' => 'https'])
            ->get('http://127.0.0.1:8300/admin/settings')->assertOk();
        $response->assertSee('http://localhost:3300/admin/settings', false)
            ->assertSee('http://localhost:3300/admin/logout', false)
            ->assertDontSee('http://127.0.0.1:8300', false)
            ->assertDontSee('attacker.test', false);
        $this->assertSame('http://127.0.0.1:8300', URL::getRequest()->getSchemeAndHttpHost());
    }

    public function test_url_scope_restores_generator_on_exception_and_leaves_api_request_untouched(): void
    {
        $original = URL::getRequest();
        $request = Request::create('http://127.0.0.1:8300/admin/settings');
        try {
            app(AdminPanelUrls::class)->handle($request, function (Request $actual) {
                $this->assertSame('127.0.0.1', $actual->getHost());
                $this->assertSame('http://localhost:3300', URL::to('/'));
                throw new RuntimeException('expected');
            });
            $this->fail('Expected exception was not thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('expected', $exception->getMessage());
        }
        $this->assertSame($original, URL::getRequest());
        app(AdminPanelResponse::class)->handle(Request::create('/api/v1/auth/me'), function () use ($original) {
            $this->assertSame($original, URL::getRequest());

            return response('ok');
        });
    }

    public function test_existing_web_session_can_update_only_own_name_via_real_livewire_endpoint(): void
    {
        $actor = User::factory()->create(['role' => 'manager', 'name' => 'Before']);
        $other = User::factory()->create(['name' => 'Other']);
        $snapshot = $this->profileSnapshot($actor);
        $this->assertPrivate($this->update($snapshot, ['data.name' => '  После  '])->assertOk());
        $this->assertSame('После', $actor->fresh()->name);
        $this->assertSame('Other', $other->fresh()->name);
    }

    public function test_unknown_fields_are_rejected_without_changing_role_or_email(): void
    {
        $actor = User::factory()->create(['role' => 'manager', 'name' => 'Before']);
        Livewire::actingAs($actor, 'web')->test(StaffProfile::class)
            ->set('data', ['name' => 'Changed', 'role' => 'admin_full'])
            ->call('save')->assertHasErrors(['request']);
        $this->assertSame('manager', $actor->fresh()->role);
        $this->assertSame('Before', $actor->fresh()->name);
    }

    public function test_permission_revocation_after_mount_denies_real_livewire_update(): void
    {
        $actor = User::factory()->create(['role' => 'manager', 'name' => 'Before']);
        $snapshot = $this->profileSnapshot($actor);
        User::query()->whereKey($actor->id)->update(['role' => 'user_no_purchase']);
        $this->assertPrivate($this->update($snapshot, ['data.name' => 'Wrong'])->assertForbidden());
        $this->assertSame('Before', $actor->fresh()->name);
        $this->assertFalse($actor->canAccessPanel(Filament::getPanel('admin')));
    }

    public function test_profile_permission_is_rechecked_after_mount(): void
    {
        $actor = User::factory()->create(['role' => 'manager', 'name' => 'Before']);
        $snapshot = $this->profileSnapshot($actor);
        config(['rbac.permissions.manager' => ['admin.access', 'admin.self.view']]);
        $this->assertPrivate($this->update($snapshot, ['data.name' => 'Wrong'])->assertForbidden());
        $this->assertSame('Before', $actor->fresh()->name);
    }

    public function test_migration_hold_after_mount_invalidates_session_and_denies_update(): void
    {
        $actor = User::factory()->create(['role' => 'manager', 'name' => 'Before']);
        $snapshot = $this->profileSnapshot($actor);
        User::query()->whereKey($actor->id)->update(['migration_hold' => true]);
        $this->assertPrivate($this->update($snapshot, ['data.name' => 'Wrong'])->assertForbidden());
        $this->assertGuest('web');
        $this->assertSame('Before', $actor->fresh()->name);
    }

    public function test_active_impersonation_blocks_update_even_for_snapshot_of_get(): void
    {
        $actor = User::factory()->create(['role' => 'admin_full', 'name' => 'Before']);
        $subject = User::factory()->create(['role' => 'user_no_purchase']);
        $snapshot = $this->profileSnapshot($actor);
        $this->withSession([ImpersonationContext::SESSION_KEY => $this->context($actor, $subject)]);
        $this->assertPrivate($this->update($snapshot, ['data.name' => 'Wrong'])->assertForbidden());
        $this->assertSame('Before', $actor->fresh()->name);
        $this->get('/admin/settings')->assertRedirect('http://localhost:3300/lk');
        $this->postJson('/api/v1/impersonation/stop')->assertOk();
    }

    public function test_expired_impersonation_rejects_update_and_logout_remains_reachable(): void
    {
        $actor = User::factory()->create(['role' => 'admin_full', 'name' => 'Before']);
        $subject = User::factory()->create(['role' => 'user_no_purchase']);
        $snapshot = $this->profileSnapshot($actor);
        $context = $this->context($actor, $subject);
        $context['started_at'] = now()->subHour()->toIso8601String();
        $context['expires_at'] = now()->subMinute()->toIso8601String();
        $this->withSession([ImpersonationContext::SESSION_KEY => $context]);
        $this->assertPrivate($this->update($snapshot, ['data.name' => 'Wrong'])->assertGone());
        $this->post('/admin/logout')->assertRedirect('http://localhost:3300/auth?mode=login');
        $this->assertGuest('web');
    }

    public function test_profile_save_preserves_thirty_per_minute_limit(): void
    {
        $actor = User::factory()->create(['role' => 'manager', 'name' => 'Before']);
        $snapshot = $this->profileSnapshot($actor);
        for ($i = 0; $i < 30; $i++) {
            RateLimiter::hit('filament:staff-profile:'.$actor->id, 60);
        }
        $this->assertPrivate($this->update($snapshot, ['data.name' => 'Wrong'])->assertStatus(429));
        $this->assertSame('Before', $actor->fresh()->name);
    }

    public function test_logout_still_invalidates_session_when_both_cleanup_steps_fail(): void
    {
        $actor = User::factory()->create(['role' => 'admin_full']);
        $this->actingAs($actor, 'web');
        Log::spy();
        $this->mock(AdminImpersonationService::class)->shouldReceive('stop')->once()
            ->andThrow(new RuntimeException('impersonation failure'));
        DB::shouldReceive('transaction')->once()->andThrow(new RuntimeException('watermark failure'));
        // Avoid another database lookup in route middleware for this failure injection.
        $this->withoutMiddleware([
            \App\Http\Middleware\EnsureMigrationAccountActive::class,
            \App\Http\Middleware\EnsureFilamentStaff::class,
            \App\Http\Middleware\EnsureFilamentImpersonation::class,
        ]);
        $response = $this->withSession(['video_watermark_session_id' => (string) Str::uuid(), 'sentinel' => 'old'])
            ->post('/admin/logout')->assertRedirect('http://localhost:3300/auth?mode=login');
        $this->assertPrivate($response);
        $this->assertGuest('web');
        $response->assertSessionMissing('sentinel');
        Log::shouldHaveReceived('warning')->with('Admin impersonation context close failed during logout.', ['exception' => RuntimeException::class])->once();
        Log::shouldHaveReceived('warning')->with('Video watermark session close failed during logout.', ['exception' => RuntimeException::class])->once();
    }

    public function test_panel_logout_closes_watermark_and_impersonation_before_forgetting_session(): void
    {
        $actor = User::factory()->create(['role' => 'admin_full']);
        $subject = User::factory()->create(['role' => 'user_no_purchase']);
        $watermark = VideoWatermarkSession::query()->create([
            'id' => (string) Str::uuid(),
            'code' => 'CMS-6T7V-WXYZ',
            'auth_session_hash' => hash('sha256', 'synthetic-session'),
            'user_id' => $actor->id,
            'started_at' => now(),
            'last_used_at' => now(),
        ]);
        $response = $this->actingAs($actor, 'web')->withSession([
            'video_watermark_session_id' => $watermark->id,
            ImpersonationContext::SESSION_KEY => $this->context($actor, $subject),
        ])->post('/admin/logout')->assertRedirect('http://localhost:3300/auth?mode=login');
        $this->assertGuest('web');
        $response->assertSessionMissing(ImpersonationContext::SESSION_KEY);
        $this->assertSame(VideoWatermarkSession::END_REASON_LOGOUT, $watermark->fresh()->end_reason);
        $this->assertNotNull($watermark->fresh()->ended_at);
        $this->assertDatabaseHas('admin_impersonation_events', [
            'actor_id' => $actor->id,
            'subject_id' => $subject->id,
            'event' => 'stopped',
        ]);
    }

    private function profileSnapshot(User $actor): string
    {
        $html = $this->actingAs($actor, 'web')->get('/admin/settings')->assertOk()->getContent();
        preg_match_all('/wire:snapshot="([^"]+)"/', $html, $matches);
        foreach ($matches[1] as $encoded) {
            $snapshot = html_entity_decode($encoded, ENT_QUOTES);
            $decoded = json_decode($snapshot, true, flags: JSON_THROW_ON_ERROR);
            [, $componentName] = app(\Livewire\Finder\Finder::class)->parseNamespaceAndName(StaffProfile::class);
            if ($decoded['memo']['name'] === $componentName) {
                return $snapshot;
            }
        }
        $this->fail('Staff profile Livewire snapshot was not rendered.');
    }

    private function update(string $snapshot, array $updates): TestResponse
    {
        return $this->withHeader('X-Livewire', 'true')->postJson(EndpointResolver::updatePath(), [
            'components' => [[
                'snapshot' => $snapshot,
                'updates' => $updates,
                'calls' => [['path' => '', 'method' => 'save', 'params' => []]],
            ]],
        ]);
    }

    private function context(User $actor, User $subject): array
    {
        return [
            'version' => (int) config('impersonation.version', 1),
            'context_id' => (string) Str::uuid(),
            'state' => 'active',
            'actor_id' => $actor->id,
            'subject_id' => $subject->id,
            'started_at' => now()->toIso8601String(),
            'expires_at' => now()->addMinutes(30)->toIso8601String(),
            'return_path' => '/admin/users/'.$subject->id,
        ];
    }

    private function assertPrivate(TestResponse $response): void
    {
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $response->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }
}
