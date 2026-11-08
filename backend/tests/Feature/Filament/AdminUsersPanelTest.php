<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\UserDetail;
use App\Filament\Pages\Users;
use App\Filament\Pages\Watermark;
use App\Http\Requests\AdminUserMutationRequest;
use App\Models\AdminUserEvent;
use App\Models\Order;
use App\Models\User;
use App\Models\VideoWatermarkSession;
use App\Services\AdminUserManagement;
use App\Support\ImpersonationContext;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Js;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Finder\Finder;
use Livewire\Livewire;
use Livewire\Mechanisms\HandleRequests\EndpointResolver;
use Tests\TestCase;

class AdminUsersPanelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.frontend_url' => 'http://localhost:3300']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_staff_lists_and_direct_detail_requests_preserve_scope(): void
    {
        $manager = User::factory()->create(['role' => 'manager']);
        $own = User::factory()->create(['role' => 'user_no_purchase', 'manager_id' => $manager->id, 'name' => 'Assigned synthetic']);
        $other = User::factory()->create(['role' => 'user_no_purchase', 'name' => 'Unassigned synthetic']);
        $this->actingAs($manager, 'web')->get('/admin/users')->assertOk()->assertSee('Assigned synthetic')->assertDontSee('Unassigned synthetic');
        $this->get('/admin/users/'.$own->id)->assertOk();
        $this->get('/admin/users/'.$other->id)->assertForbidden();
        Livewire::test(Users::class)->set('scope', 'staff')->assertForbidden();
        foreach (['admin_full', 'admin_limited'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]), 'web');
            Livewire::test(Users::class)->set('scope', 'staff')->assertSee($manager->name);
        }
    }

    public function test_list_context_survives_detail_tabs_and_independent_history_pages(): void
    {
        $actor = User::factory()->create(['role' => 'admin_full']);
        $students = User::factory()->count(21)->create(['role' => 'user_no_purchase', 'name' => 'Context student']);
        $target = $students->first();
        $this->actingAs($actor, 'web');
        $context = ['q' => 'Context student', 'scope' => 'users', 'page' => 2];
        $query = http_build_query($context);
        Livewire::withQueryParams($context)->test(Users::class)
            ->assertSee('/admin/users/'.$target->id.'?'.$query);
        $detail = Livewire::withQueryParams($context + ['orders_page' => 2, 'access_page' => 3, 'courses_page' => 4, 'events_page' => 5])
            ->test(UserDetail::class, ['userId' => (string) $target->id])
            ->assertSee('/admin/users?'.$query);
        foreach (['orders', 'access', 'events', 'profile'] as $tab) {
            $detail->set('tab', $tab)->assertSee('/admin/users?'.$query)
                ->assertSet('q', 'Context student')->assertSet('scope', 'users')->assertSet('page', 2)
                ->assertSet('ordersPage', 2)->assertSet('accessPage', 3)->assertSet('coursesPage', 4)->assertSet('eventsPage', 5);
        }
        $staff = User::factory()->create(['role' => 'manager']);
        $staffContext = ['q' => 'Context manager', 'scope' => 'staff', 'page' => 3];
        Livewire::withQueryParams($staffContext)->test(UserDetail::class, ['userId' => (string) $staff->id])
            ->set('tab', 'events')->assertSee('/admin/users?'.http_build_query($staffContext))->assertSee('Сотрудники');
    }

    public function test_return_link_uses_only_bounded_local_list_fields(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin_full']), 'web');
        $target = User::factory()->create();
        Livewire::withQueryParams([
            'q' => 'Safe search', 'scope' => 'https://attacker.example', 'page' => 0,
            'return_url' => 'https://attacker.example',
        ])->test(UserDetail::class, ['userId' => (string) $target->id])
            ->assertSee('/admin/users?'.http_build_query(['q' => 'Safe search', 'scope' => 'users', 'page' => 1]))
            ->assertDontSee('href="https://attacker.example', false);
    }

    public function test_native_search_scope_and_pagination_keep_list_context(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin_full']), 'web');
        $students = User::factory()->count(21)->create(['role' => 'user_no_purchase', 'name' => 'Native student']);
        $manager = User::factory()->create(['role' => 'manager', 'name' => 'Native manager']);
        $component = Livewire::withQueryParams(['q' => 'Native student', 'page' => 2])->test(Users::class)
            ->assertSet('tableSearch', 'Native student')
            ->assertSee('/admin/users/'.$students->first()->id.'?'.http_build_query(['q' => 'Native student', 'scope' => 'users', 'page' => 2]));
        $this->assertSame((string) $students->first()->id, $component->instance()->getTableRecords()->first()['__key']);
        $component->searchTable('Native')->assertSet('q', 'Native')->assertSet('paginators.page', 1)
            ->filterTable('scope', 'staff')->assertSet('scope', 'staff')->assertSee('Native manager')->assertDontSee('Native student')
            ->assertSee('/admin/users/'.$manager->id.'?'.http_build_query(['q' => 'Native', 'scope' => 'staff', 'page' => 1]));
        $component->removeTableFilter('scope')->assertSet('scope', 'users')->assertSee('Native student');
        $component->searchTable(str_repeat('x', 256))->assertHasErrors('q')->assertDontSee('Native student');
        $component->searchTable('Native student')->assertHasNoErrors()->assertSee('Native student');
    }

    public function test_deletion_remains_in_card_with_confirmation_and_cancel(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin_full']), 'web');
        $students = User::factory()->count(21)->create(['role' => 'user_no_purchase', 'name' => 'Modal student']);
        $target = $students->first();
        Livewire::test(Users::class)->assertTableActionDoesNotExist('deleteTestAccount');
        $component = Livewire::test(UserDetail::class, ['userId' => (string) $target->id])
            ->assertSee('Удалить тестовый аккаунт')->call('prepareDeletion', $target->id)->assertSet('deleteTarget', $target->id);
        $key = $component->get('deletionKey');
        $this->assertTrue(Str::isUuid($key));
        $component->set('deleteConfirmation', 'wrong')->call('deleteTestAccount')->assertHasErrors()
            ->assertSet('deleteTarget', $target->id)->assertSet('deletionKey', $key)
            ->call('cancelDeletion')->assertSet('deleteTarget', null)->assertSet('deleteConfirmation', '');
        $this->assertDatabaseHas('users', ['id' => $target->id]);
        $component->call('prepareDeletion', $target->id);
        $this->assertNotSame($key, $component->get('deletionKey'));
        $component->set('deleteConfirmation', 'УДАЛИТЬ')->call('deleteTestAccount')->assertRedirect('/admin/users');
        $this->assertDatabaseMissing('users', ['id' => $target->id]);
        $this->assertDatabaseHas('users', ['id' => $students->last()->id]);
    }

    public function test_card_delete_conflict_keeps_confirmation_and_key(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin_full']), 'web');
        $target = User::factory()->create(['role' => 'user_no_purchase']);
        AdminUserEvent::create(['user_id' => $target->id, 'actor_id' => auth('web')->id(), 'actor_name' => 'Test admin',
            'type' => 'comment', 'changes' => ['comment' => 'Protected history'], 'created_at' => now()]);
        $component = Livewire::test(UserDetail::class, ['userId' => (string) $target->id])->call('prepareDeletion', $target->id);
        $key = $component->get('deletionKey');
        $component->set('deleteConfirmation', 'УДАЛИТЬ')->call('deleteTestAccount')
            ->assertNoRedirect()->assertSet('deleteTarget', $target->id)->assertSet('deletionKey', $key)
            ->assertSet('deleteConfirmation', 'УДАЛИТЬ');
        $this->assertDatabaseHas('users', ['id' => $target->id]);
    }

    public function test_native_filters_cannot_expose_staff_to_manager(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'manager']), 'web');
        Livewire::test(Users::class)->set('tableFilters.scope.value', 'staff')->assertForbidden();
    }

    public function test_direct_lk_action_preserves_visibility_permissions(): void
    {
        $actor = User::factory()->create(['role' => 'admin_full']);
        $students = User::factory()->count(21)->create(['role' => 'user_no_purchase', 'name' => 'Direct action student']);
        $target = $students->first();
        $this->actingAs($actor, 'web');
        Livewire::withQueryParams(['q' => 'Direct action student', 'page' => 2])->test(Users::class)
            ->assertTableActionVisible('impersonate', (string) $target->id)->assertTableActionDoesNotExist('deleteTestAccount');
        $this->actingAs(User::factory()->create(['role' => 'admin_limited']), 'web');
        Livewire::withQueryParams(['q' => 'Direct action student', 'page' => 2])->test(Users::class)
            ->assertTableActionHidden('impersonate', (string) $target->id);
    }

    public function test_list_distinguishes_paid_orders_from_missing_permission_and_staff_roles(): void
    {
        $actor = User::factory()->create(['role' => 'admin_full']);
        $target = User::factory()->create(['role' => 'user_no_purchase']);
        $this->actingAs($actor, 'web');
        Livewire::test(Users::class)->assertSee('Нет оплаченных заказов');
        foreach ([['RUB', 125000, 'paid'], ['USD', 355, 'paid'], ['RUB', 999999, 'pending']] as [$currency, $amount, $status]) {
            Order::query()->create(['user_id' => $target->id, 'currency' => $currency, 'amount_total' => $amount,
                'amount_paid' => $status === 'paid' ? $amount : 0, 'payment_status' => $status]);
        }
        Livewire::test(Users::class)->assertSee('1 250 ₽ · 1 заказ')->assertSee('3,55 USD · 1 заказ')
            ->assertDontSee('9 999,99')->assertDontSee('Нет оплаченных заказов')
            ->filterTable('scope', 'staff')->assertSee('Администратор')->assertDontSee('Оплаченные заказы');

        config(['rbac.permissions.admin_full' => ['admin.access', 'users.read']]);
        Livewire::test(Users::class)->assertSee($target->email)->assertDontSee('Оплаченные заказы')
            ->assertDontSee('Нет оплаченных заказов')->assertDontSee('1 250 ₽');
    }

    public function test_native_initial_pagination_rejects_invalid_url_values(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin_full']), 'web');
        User::factory()->create(['role' => 'user_no_purchase', 'name' => 'No fallback student']);
        foreach (['0', '-1', 'bogus', '1.5'] as $page) {
            Livewire::withQueryParams(['page' => $page])->test(Users::class)
                ->assertHasErrors('page')->assertSee('role="alert"', false)->assertDontSee('No fallback student');
        }
        Livewire::withQueryParams(['q' => str_repeat('x', 256)])->test(Users::class)
            ->assertHasErrors('q')->assertSee('role="alert"', false)->assertDontSee('No fallback student');
    }

    public function test_permission_revocation_and_reassignment_after_mount_deny_reads_and_writes(): void
    {
        $actor = User::factory()->create(['role' => 'manager']);
        $target = User::factory()->create(['manager_id' => $actor->id, 'name' => 'Unchanged']);
        $this->actingAs($actor, 'web');
        $component = Livewire::test(UserDetail::class, ['userId' => (string) $target->id])->set('profileData.name', 'Forbidden');
        $target->update(['manager_id' => null]);
        $component->call('saveProfile')->assertForbidden();
        $this->assertSame('Unchanged', $target->fresh()->name);
        $this->assertDatabaseCount('admin_user_events', 0);

        $target->update(['manager_id' => $actor->id]);
        $component = Livewire::test(UserDetail::class, ['userId' => (string) $target->id]);
        $actor->update(['role' => 'user_no_purchase']);
        $component->call('reloadEditor')->assertForbidden();
    }

    public function test_profile_uses_captured_revision_and_writes_existing_audit_events(): void
    {
        $actor = User::factory()->create(['role' => 'admin_full']);
        $target = User::factory()->create(['name' => 'Before']);
        $this->actingAs($actor, 'web');
        $stale = Livewire::test(UserDetail::class, ['userId' => (string) $target->id]);
        Livewire::test(UserDetail::class, ['userId' => (string) $target->id])
            ->set('profileData.name', 'After')->call('saveProfile')->assertHasNoErrors();
        $stale->set('profileData.name', 'Stale')->call('saveProfile');
        $this->assertSame('After', $target->fresh()->name);
        $this->assertDatabaseCount('admin_user_events', 1);
        $this->assertDatabaseHas('admin_user_events', ['user_id' => $target->id, 'actor_id' => $actor->id, 'type' => 'profile']);
    }

    public function test_profile_strict_fields_and_comment_retry_are_preserved(): void
    {
        $actor = User::factory()->create(['role' => 'admin_full']);
        $target = User::factory()->create();
        $this->actingAs($actor, 'web');
        try {
            AdminUserMutationRequest::validateFor('updateProfile', [
                'revision' => AdminUserManagement::revisionFor($target, 0), 'name' => 'Safe', 'role' => 'admin_full',
            ]);
            $this->fail('Unexpected field accepted');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('request', $exception->errors());
        }
        $component = Livewire::test(UserDetail::class, ['userId' => (string) $target->id])
            ->set('commentData.comment', 'Synthetic comment')->call('addComment')->call('addComment');
        $this->assertSame(1, AdminUserEvent::where('type', 'comment')->count());
        $component->set('commentData.comment', 'Different comment')->call('addComment');
        $this->assertSame(1, AdminUserEvent::where('type', 'comment')->count());
        $component->call('newComment')->set('commentData.comment', 'Second comment')->call('addComment');
        $this->assertSame(2, AdminUserEvent::where('type', 'comment')->count());
    }

    public function test_manager_assignment_and_tags_use_domain_mutations(): void
    {
        $actor = User::factory()->create(['role' => 'admin_full']);
        $target = User::factory()->create();
        $manager = User::factory()->create(['role' => 'manager']);
        $this->actingAs($actor, 'web');
        Livewire::test(UserDetail::class, ['userId' => (string) $target->id])
            ->set('managerData.manager_id', $manager->id)->call('saveManager')->assertHasNoErrors()
            ->set('tagsData.tags', 'Подготовка, Экзамен')->call('saveTags')->assertHasNoErrors();
        $this->assertSame($manager->id, $target->fresh()->manager_id);
        $this->assertSame(['подготовка', 'экзамен'], $target->tags()->orderBy('name')->pluck('name')->all());
        $this->assertDatabaseHas('admin_user_events', ['user_id' => $target->id, 'type' => 'manager']);
        $this->assertDatabaseHas('admin_user_events', ['user_id' => $target->id, 'type' => 'tags']);
    }

    public function test_parameter_ack_and_manual_save_keep_all_neighbor_drafts(): void
    {
        $actor = User::factory()->create(['role' => 'admin_full']);
        $target = User::factory()->create(['name' => 'Stored name']);
        $manager = User::factory()->create(['role' => 'manager']);
        $this->actingAs($actor, 'web');
        $page = Livewire::test(UserDetail::class, ['userId' => (string) $target->id]);
        $opening = $page->get('savingOpening');
        $revision = $page->get('revision');
        $page->set('profileData.name', '')->set('profileData.school', 'Manual school')
            ->set('tagsData.tags', 'Manual tags')->set('managerData.manager_id', $manager->id)
            ->set('commentData.comment', 'Command draft')->set('profileData.gender', 'other')
            ->call('saveProfileParameters', ['profileData.gender' => 'other'], $revision, $opening)
            ->assertHasNoErrors()->assertSet('profileData.name', '')->assertSet('profileData.school', 'Manual school')
            ->assertSet('tagsData.tags', 'Manual tags')->assertSet('managerData.manager_id', $manager->id)
            ->assertSet('commentData.comment', 'Command draft')
            ->assertSet('savingOpening', $opening);
        $this->assertSame('Stored name', $page->get('savingBaseline')['profileData.name']);
        $this->assertSame('Stored name', $target->fresh()->name);
        $this->assertNull($target->fresh()->manager_id);
        $this->assertSame(0, AdminUserEvent::where('type', 'comment')->count());
        $page->set('profileData.name', 'Saved name')->call('saveProfile')->assertHasNoErrors()
            ->assertSet('tagsData.tags', 'Manual tags')->assertSet('managerData.manager_id', $manager->id)
            ->assertSet('commentData.comment', 'Command draft');
        $page->call('saveTags')->assertHasNoErrors()->assertSet('commentData.comment', 'Command draft')
            ->assertSet('managerData.manager_id', $manager->id);
        $this->assertNull($target->fresh()->manager_id);
        $this->assertDatabaseCount('admin_user_events', 3);
        $latestRevision = $page->get('revision');
        $page->call('saveProfileParameters', ['profileData.gender' => 'female'], $revision, $opening)
            ->assertReturned(['ok' => false, 'terminal' => true, 'message' => 'Карточка уже изменена. Обновите данные и повторите правку.'])
            ->assertSet('revision', $latestRevision)->assertSet('commentData.comment', 'Command draft');
        $this->assertDatabaseCount('admin_user_events', 3);
    }

    public function test_ordinary_guard_save_never_executes_command_drafts_and_validation_preserves_them(): void
    {
        $actor = User::factory()->create(['role' => 'admin_full']);
        $target = User::factory()->create(['name' => 'Stored name']);
        $manager = User::factory()->create(['role' => 'manager']);
        $this->actingAs($actor, 'web');
        $page = Livewire::test(UserDetail::class, ['userId' => (string) $target->id])
            ->set('profileData.name', '')->set('tagsData.tags', 'Tag')
            ->set('managerData.manager_id', $manager->id)->set('commentData.comment', 'Unsent comment')
            ->call('saveOrdinary')->assertHasErrors()->assertSet('managerData.manager_id', $manager->id)
            ->assertSet('commentData.comment', 'Unsent comment');
        $this->assertDatabaseCount('admin_user_events', 0);
        $page->set('profileData.name', 'Saved ordinary')->call('saveOrdinary')->assertHasNoErrors()
            ->assertSet('commentData.comment', 'Unsent comment')->assertSet('managerData.manager_id', $manager->id);
        $this->assertSame('Saved ordinary', $target->fresh()->name);
        $this->assertNull($target->fresh()->manager_id);
        $this->assertSame(['tag'], $target->tags()->pluck('name')->all());
        $this->assertDatabaseCount('admin_user_events', 2);
        $page->call('saveOrdinary')->assertHasNoErrors();
        $this->assertDatabaseCount('admin_user_events', 2);
    }

    public function test_aggregate_owner_renders_all_manual_hints_and_explicit_native_command_forms(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin_full']), 'web');
        $target = User::factory()->create();
        $page = Livewire::test(UserDetail::class, ['userId' => (string) $target->id])
            ->assertSee('Для интерфейса аккаунта. Авторы статей редактируются в отдельном справочнике.')
            ->assertSeeHtml('wire:ignore.self')->assertSeeHtml('form="user-new-comment"')
            ->assertSeeHtml('wire:submit="saveOrdinary"');
        $config = $page->instance()->savingConfig();
        $this->assertSame(['profileData.birth_date', 'profileData.gender'], $config['parameters']);
        $this->assertSame(['commentData.comment', 'managerData.manager_id'], $config['commands']);
        $html = html_entity_decode($page->html(), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $this->assertSame(1, substr_count($html, 'x-data="adminFieldSaving('));
        $this->assertSame(count($config['manual']), substr_count($html, 'data-ui="field-saving.hint"'));
        foreach ($config['manual'] as $field) {
            $this->assertStringContainsString("changed('{$field}')", $html);
        }
    }

    public function test_ordinary_save_validates_tags_before_writing_profile_and_recovers_in_the_same_draft(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin_full']), 'web');
        $target = User::factory()->create(['name' => 'Stored']);
        $page = Livewire::test(UserDetail::class, ['userId' => (string) $target->id]);
        $revision = $page->get('revision');
        $page->set('profileData.name', 'Pending profile')->set('tagsData.tags', implode(', ', range(1, 21)))
            ->set('commentData.comment', 'Unsent')->call('saveOrdinary')->assertHasErrors('tagsData.tags')
            ->assertSet('revision', $revision)->assertSet('profileData.name', 'Pending profile')
            ->assertSet('commentData.comment', 'Unsent');
        $this->assertSame('Stored', $target->fresh()->name);
        $this->assertDatabaseCount('admin_user_events', 0);
        $page->set('tagsData.tags', 'Valid')->call('saveOrdinary')->assertHasNoErrors()
            ->assertSet('commentData.comment', 'Unsent');
        $this->assertSame('Pending profile', $target->fresh()->name);
        $this->assertDatabaseCount('admin_user_events', 2);
    }

    public function test_discard_tab_transition_resets_both_draft_types_without_writing_and_rotates_opening(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin_full']), 'web');
        $target = User::factory()->create(['name' => 'Stored']);
        $page = Livewire::test(UserDetail::class, ['userId' => (string) $target->id]);
        $opening = $page->get('savingOpening');
        $page->set('profileData.name', 'Discard')->set('tagsData.tags', 'Discard')
            ->set('commentData.comment', 'Do not post')->call('openSavingTab', 'events')
            ->assertHasNoErrors()->assertSet('tab', 'events')->assertSet('profileData.name', 'Stored')
            ->assertSet('tagsData.tags', '')->assertSet('commentData.comment', '');
        $this->assertNotSame($opening, $page->get('savingOpening'));
        $this->assertDatabaseCount('admin_user_events', 0);
    }

    public function test_tab_handlers_render_encoded_targets_without_uncompiled_blade_directives(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin_full']), 'web');
        $target = User::factory()->create();
        $page = Livewire::test(UserDetail::class, ['userId' => (string) $target->id]);
        $html = html_entity_decode($page->html(), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        preg_match_all('/x-on:click="([^\"]*user-saving-tab[^\"]*)"/u', $html, $matches);
        $this->assertCount(4, $matches[1]);
        foreach (['profile', 'orders', 'access', 'events'] as $index => $tab) {
            $this->assertSame('$dispatch(\'user-saving-tab\', { tab: '.Js::from($tab)->toHtml().' })', $matches[1][$index]);
            $this->assertStringNotContainsString('@js', $matches[1][$index]);
        }
    }

    public function test_test_account_deletion_requires_confirmation_and_domain_protection(): void
    {
        $actor = User::factory()->create(['role' => 'admin_full']);
        $target = User::factory()->create(['role' => 'user_no_purchase']);
        $this->actingAs($actor, 'web');
        Livewire::test(Users::class)->call('prepareDeletion', $target->id)->call('deleteTestAccount')
            ->assertHasErrors(['deleteConfirmation'])
            ->set('deleteConfirmation', 'УДАЛИТЬ')->call('deleteTestAccount')->assertRedirect('/admin/users');
        $this->assertDatabaseMissing('users', ['id' => $target->id]);
        $this->assertDatabaseHas('admin_operation_events', ['resource_id' => $target->id, 'operation' => 'delete_test_account']);
        Livewire::test(Users::class)->call('prepareDeletion', $actor->id)
            ->set('deleteConfirmation', 'УДАЛИТЬ')->call('deleteTestAccount')->assertForbidden();
        $this->assertDatabaseHas('users', ['id' => $actor->id]);
    }

    public function test_impersonation_keeps_actor_and_redirects_to_lk(): void
    {
        $actor = User::factory()->create(['role' => 'admin_full']);
        $students = User::factory()->count(21)->create(['role' => 'user_no_purchase', 'name' => 'Direct action student']);
        $target = $students->first();
        $this->actingAs($actor, 'web');
        $html = $this->get('/admin/users?q=Direct%20action%20student&page=2')->assertOk()->getContent();
        preg_match_all('/wire:snapshot="([^"]+)"/', $html, $matches);
        [, $componentName] = app(Finder::class)->parseNamespaceAndName(Users::class);
        $snapshot = null;
        foreach ($matches[1] as $encoded) {
            $candidate = html_entity_decode($encoded, ENT_QUOTES);
            if (json_decode($candidate, true, flags: JSON_THROW_ON_ERROR)['memo']['name'] === $componentName) {
                $snapshot = $candidate;
            }
        }
        $this->assertNotNull($snapshot);
        $this->withHeader('X-Livewire', 'true')->postJson(EndpointResolver::updatePath(), [
            'components' => [[
                'snapshot' => $snapshot, 'updates' => [],
                'calls' => [['path' => '', 'method' => 'mountAction', 'params' => ['impersonate', [], ['table' => true, 'recordKey' => (string) $target->id]]]],
            ]],
        ])->assertOk()->assertJsonPath('components.0.effects.redirect', '/lk');
        $this->assertSame($actor->id, auth('web')->id());
        $this->assertSame($target->id, session(ImpersonationContext::SESSION_KEY.'.subject_id'));
        $this->assertSame('/admin/users?'.http_build_query(['q' => 'Direct action student', 'scope' => 'users', 'page' => 2]), session(ImpersonationContext::SESSION_KEY.'.return_path'));
        $this->assertDatabaseHas('admin_impersonation_events', ['actor_id' => $actor->id, 'subject_id' => $target->id, 'event' => 'started']);
    }

    public function test_watermark_projection_audit_empty_state_throttle_and_revocation(): void
    {
        $actor = User::factory()->create(['role' => 'admin_full']);
        $target = User::factory()->create(['name' => 'Watermark synthetic', 'email' => 'private@example.test']);
        VideoWatermarkSession::create(['id' => (string) Str::uuid(), 'code' => 'CMS-7K4M-92QF',
            'auth_session_hash' => hash('sha256', 'private-session'), 'user_id' => $target->id,
            'started_at' => now()->subMinute(), 'last_used_at' => now()]);
        $this->actingAs($actor, 'web');
        $component = Livewire::test(Watermark::class)->set('data.code', 'CMS 7K4M 92QF')->call('lookup')
            ->assertSee('Watermark synthetic')->assertDontSee('private@example.test')->assertDontSee(hash('sha256', 'private-session'));
        $this->assertDatabaseHas('video_watermark_lookup_audit_events', ['actor_user_id' => $actor->id, 'result' => 'found']);
        $component->set('data.code', 'not found')->call('lookup')->assertSee('По этому коду ничего не найдено.');
        $this->assertDatabaseCount('video_watermark_lookup_audit_events', 2);
        for ($i = 0; $i < 30; $i++) {
            RateLimiter::hit('filament:users:watermark:'.$actor->id, 60);
        }
        $component->call('lookup')->assertStatus(429);
        $actor->update(['role' => 'admin_limited']);
        $this->get('/admin/video-watermarks')->assertForbidden();
        $this->assertDatabaseCount('video_watermark_lookup_audit_events', 2);
    }
}
