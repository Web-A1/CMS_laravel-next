<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\UserDetail;
use App\Models\User;
use App\Models\Order;
use App\Models\AdminUserEvent;
use App\Models\UserEntitlementGrant;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class AdminUserCardPresentationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create(['role' => 'admin_full']), 'web');
    }

    public function test_order_card_displays_only_frozen_tariff_label_and_support_terms(): void
    {
        $target = User::factory()->create();
        $order = Order::query()->create(['user_id' => $target->id, 'currency' => 'RUB', 'amount_total' => 859000, 'amount_final' => 859000, 'payment_status' => 'paid']);
        $order->items()->create(['sellable_kind' => 'course', 'sellable_id' => 1, 'purchased_entity_type' => 'course', 'purchased_entity_id' => 1, 'display_title' => 'Course', 'currency' => 'RUB', 'quantity' => 1, 'unit_amount' => 859000, 'subtotal_amount' => 859000, 'final_amount' => 859000, 'catalog_snapshot' => ['tariff' => ['version' => 1, 'code' => 'extended', 'label' => 'Расширенный', 'supportDescription' => 'Сохранённый объём личной работы преподавателя.']], 'price_snapshot' => [], 'benefit_snapshot' => [], 'grant_snapshot' => [], 'snapshot_hash' => str_repeat('a', 64)]);
        Livewire::withQueryParams(['tab' => 'orders'])->test(UserDetail::class, ['userId' => (string) $target->id])->assertSee('Расширенный')->assertSee('Сохранённый объём личной работы преподавателя.');
        $order->items()->update(['catalog_snapshot' => json_encode(['sellable' => ['title' => 'Расширенный']])]);
        Livewire::withQueryParams(['tab' => 'orders'])->test(UserDetail::class, ['userId' => (string) $target->id])->assertDontSee('Сохранённый объём личной работы преподавателя.')->assertDontSee('Расширенный');
    }

    public function test_switching_tabs_preserves_unsaved_forms_and_operation_tokens(): void
    {
        $target = User::factory()->create(['name' => 'Saved name']);
        $component = Livewire::withQueryParams(['q' => 'Saved', 'scope' => 'users', 'page' => 2])
            ->test(UserDetail::class, ['userId' => (string) $target->id]);
        $revision = $component->get('revision');
        $commentKey = $component->get('commentKey');
        $component->set('profileData.name', 'Unsaved name')
            ->set('tagsData.tags', 'Unsaved tag')
            ->set('commentData.comment', 'Unsaved comment');

        foreach (['orders', 'access', 'events', 'profile'] as $tab) {
            $component->set('tab', $tab)
                ->assertSet('profileData.name', 'Unsaved name')
                ->assertSet('tagsData.tags', 'Unsaved tag')
                ->assertSet('commentData.comment', 'Unsaved comment')
                ->assertSet('revision', $revision)->assertSet('commentKey', $commentKey)
                ->assertSee('/admin/users?'.http_build_query(['q' => 'Saved', 'scope' => 'users', 'page' => 2]));
        }

        $this->assertSame('Saved name', $target->fresh()->name);
        $this->assertDatabaseCount('admin_user_events', 0);
    }

    public function test_read_only_permissions_hide_card_mutations(): void
    {
        config(['rbac.permissions.admin_limited' => ['admin.access', 'users.read', 'audit.read']]);
        $this->actingAs(User::factory()->create(['role' => 'admin_limited']), 'web');
        $target = User::factory()->create(['name' => 'Read only student']);

        Livewire::test(UserDetail::class, ['userId' => (string) $target->id])
            ->assertSee('Read only student')
            ->assertDontSee('Сохранить анкету')->assertDontSee('Сохранить назначение')
            ->assertDontSee('Сохранить метки')->assertDontSee('Добавить комментарий')
            ->assertDontSee('Удалить тестовый аккаунт')->assertDontSee('Открыть ЛК пользователя')
            ->set('tab', 'events')->assertSee('История')
            ->assertDontSee('Добавить комментарий');
    }

    public function test_footer_submit_buttons_are_associated_with_their_forms(): void
    {
        $target = User::factory()->create();
        $component = Livewire::test(UserDetail::class, ['userId' => (string) $target->id]);
        $document = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$component->html());
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new \DOMXPath($document);

        foreach (['Сохранить анкету' => 'user-profile', 'Добавить комментарий' => 'user-comment'] as $label => $formId) {
            $button = $xpath->query('//button[contains(., "'.$label.'")]')->item(0);
            $this->assertInstanceOf(\DOMElement::class, $button);
            $this->assertSame('submit', $button->getAttribute('type'));
            $this->assertSame($formId, $button->getAttribute('form'));
            $this->assertSame(1, $xpath->query('//form[@id="'.$formId.'"]')->length);
        }
    }

    public function test_expired_raw_active_and_revoked_grants_have_distinct_effective_labels(): void
    {
        $target = User::factory()->create();
        foreach (['active', 'revoked'] as $index => $status) {
            UserEntitlementGrant::query()->create([
                'user_id' => $target->id, 'atom_kind' => 'lesson', 'atom_id' => $index + 1,
                'status' => $status, 'source_type' => 'admin_grant', 'source_id' => (string) Str::uuid(),
                'idempotency_key' => (string) Str::uuid(), 'granted_at' => now()->subDays(2),
                'expires_at' => $status === 'active' ? now()->subDay() : null,
                'revoked_at' => $status === 'revoked' ? now()->subDay() : null,
            ]);
        }

        Livewire::withQueryParams(['tab' => 'access'])->test(UserDetail::class, ['userId' => (string) $target->id])
            ->assertSee('Срок истёк')->assertSee('Отозван')->assertDontSee('Не действует · active');

        $this->assertDatabaseHas('user_entitlement_grants', ['user_id' => $target->id, 'status' => 'active']);
        $this->assertDatabaseCount('user_entitlement_grants', 2);
    }

    public function test_order_payment_and_access_states_are_readable_without_losing_amounts(): void
    {
        $target = User::factory()->create();
        foreach (['pending', 'paid', 'failed', 'refunded'] as $index => $status) {
            Order::query()->create([
                'user_id' => $target->id, 'currency' => 'RUB', 'amount_total' => 12345,
                'amount_final' => 12345, 'amount_paid' => $status === 'paid' ? 12345 : 0,
                'payment_status' => $status,
                'access_status' => ['pending_payment', 'granted', 'grant_failed', 'not_granted'][$index],
            ]);
        }

        Livewire::withQueryParams(['tab' => 'orders'])->test(UserDetail::class, ['userId' => (string) $target->id])
            ->assertSee('Ожидает оплаты')->assertSee('Оплачен')->assertSee('Ошибка оплаты')->assertSee('Возвращён')
            ->assertSee('Выдан')->assertSee('Ошибка выдачи доступа')->assertSee('Не выдан')->assertSee('123,45 RUB');
        $this->assertDatabaseCount('orders', 4);
    }

    public function test_audit_text_remains_literal_text_with_distinct_change_lines(): void
    {
        $target = User::factory()->create();
        $markup = '<img src="x" onerror="alert(1)">';
        $comment = "<script>alert(1)</script>\nВторая строка";
        AdminUserEvent::query()->create([
            'user_id' => $target->id, 'actor_id' => auth('web')->id(), 'actor_name' => 'Тестовый администратор',
            'type' => 'profile', 'comment' => $comment, 'created_at' => now(),
            'changes' => ['name' => ['before' => $markup, 'after' => 'Новое имя']],
        ]);

        Livewire::withQueryParams(['tab' => 'events'])->test(UserDetail::class, ['userId' => (string) $target->id])
            ->assertSee($markup)->assertSee('<script>alert(1)</script>')->assertSee('Вторая строка')
            ->assertSee('Имя → До:')->assertSee('Имя → После: Новое имя')
            ->assertDontSee('<img src="x"', false)->assertDontSee('<script>alert(1)</script>', false);
    }
}
