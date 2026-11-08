<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Lesson;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderSettlement;
use App\Models\OutboxEvent;
use App\Models\PaymentAttempt;
use App\Models\PaymentAuditLog;
use App\Models\ProviderEvent;
use App\Models\Refund;
use App\Models\SellableEntity;
use App\Models\SellableGrantRule;
use App\Models\SideEffectExecution;
use App\Models\Subject;
use App\Models\User;
use App\Models\UserEntitlementGrant;
use App\Services\AccessResolver;
use App\Services\EntitlementGrantService;
use App\Services\OrderItemSnapshotService;
use App\Services\OrderSettlementService;
use App\Services\Payments\CloudpaymentsReconciliationService;
use App\Services\Payments\CloudpaymentsRefundService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PaymentsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_additional_methods_require_enabled_backend_configuration(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'user_no_purchase']));
        config(['yandex_split.enabled' => false, 'robokassa.enabled' => false, 'podeli.enabled' => false]);
        $this->getJson('/api/v1/payments/methods')->assertOk()->assertExactJson(['additional_methods' => []]);
        config(['yandex_split.enabled' => true, 'yandex_split.merchant_id' => 'merchant-test', 'yandex_split.sandbox' => true]);
        $this->getJson('/api/v1/payments/methods')->assertOk()->assertExactJson(['additional_methods' => ['yandex-split']]);
    }

    public function test_podeli_customer_validation_precedes_payment_reservation(): void
    {
        [$order] = $this->createPendingOrderWithAttempt();
        Sanctum::actingAs($order->user);
        Http::preventStrayRequests();
        $this->postJson('/api/v1/payments/orders/'.$order->id.'/attempts', [
            'provider' => 'podeli', 'return_url' => 'https://merchant.test/return', 'cancel_url' => 'https://merchant.test/cancel',
        ])->assertUnprocessable()->assertJsonValidationErrors(['client_info.first_name', 'client_info.last_name']);
        $this->assertSame(1, $order->paymentAttempts()->count());
        Http::assertNothingSent();
    }

    public function test_order_creation_is_rate_limited_per_authenticated_actor(): void
    {
        $user = User::factory()->create(['role' => 'user_no_purchase']);
        $course = $this->createCheckoutCourse(priceCents: 150000);
        Sanctum::actingAs($user);
        RateLimiter::clear("payment-create-order:actor:{$user->id}");

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->postJson('/api/v1/payments/orders', ['course_id' => $course->id])
                ->assertCreated();
        }

        $this->postJson('/api/v1/payments/orders', ['course_id' => $course->id])
            ->assertTooManyRequests();

        $otherUser = User::factory()->create(['role' => 'user_no_purchase']);
        Sanctum::actingAs($otherUser);
        RateLimiter::clear("payment-create-order:actor:{$otherUser->id}");

        $this->postJson('/api/v1/payments/orders', ['course_id' => $course->id])
            ->assertCreated();
    }

    public function test_user_can_create_order(): void
    {
        $user = User::factory()->create(['role' => 'user_no_purchase']);
        $course = $this->createCheckoutCourse(priceCents: 150000);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/payments/orders', [
            'course_id' => $course->id,
            'price' => 150000,
            'currency' => 'RUB',
        ])
            ->assertCreated()
            ->assertJsonPath('payment_status', 'draft')
            ->assertJsonPath('access_status', 'not_granted');

        $this->assertDatabaseHas('orders', [
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount_total' => 150000,
            'amount_discount' => 0,
            'amount_final' => 150000,
        ]);
    }

    public function test_unverified_phone_is_required_before_payment_attempt(): void
    {
        $user = User::factory()->create(['role' => 'user_no_purchase', 'phone' => null, 'phone_verified_at' => null]);
        $course = $this->createCheckoutCourse(priceCents: 150000);
        Sanctum::actingAs($user);

        $orderId = (int) $this->postJson('/api/v1/payments/orders', ['course_id' => $course->id])
            ->assertCreated()
            ->json('order_id');

        $this->postJson("/api/v1/payments/orders/{$orderId}/attempts", [
            'provider' => 'test_provider',
            'return_url' => 'https://example.com/return',
            'cancel_url' => 'https://example.com/cancel',
        ])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'VERIFY_PHONE_REQUIRED');

        $this->assertDatabaseCount('payment_attempts', 0);
        $this->assertDatabaseHas('orders', ['id' => $orderId, 'payment_status' => 'draft']);
    }

    public function test_order_creation_uses_backend_course_price_instead_of_client_price(): void
    {
        $user = User::factory()->create(['role' => 'user_no_purchase']);
        $course = $this->createCheckoutCourse(priceCents: 150000);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/payments/orders', [
            'course_id' => $course->id,
            'price' => 1,
            'currency' => 'RUB',
        ])->assertCreated();

        $this->assertDatabaseHas('orders', [
            'course_id' => $course->id,
            'amount_total' => 150000,
            'amount_discount' => 0,
            'amount_final' => 150000,
        ]);
    }

    public function test_legacy_order_rejects_non_rub_currency_without_side_effects(): void
    {
        $user = User::factory()->create(['role' => 'user_no_purchase']);
        $course = $this->createCheckoutCourse(priceCents: 150000);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/payments/orders', [
            'course_id' => $course->id,
            'currency' => 'USD',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'PAYMENT_CURRENCY_MISMATCH');

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);
        $this->assertDatabaseCount('payment_attempts', 0);
        $this->assertDatabaseCount('payment_audit_log', 0);
        $this->assertDatabaseCount('provider_events', 0);
    }

    public function test_legacy_order_and_attempt_use_server_owned_rub_without_client_currency(): void
    {
        $user = User::factory()->create([
            'role' => 'user_no_purchase',
            'phone_verified_at' => now(),
        ]);
        $course = $this->createCheckoutCourse(priceCents: 150000);
        Sanctum::actingAs($user);

        $orderId = (int) $this->postJson('/api/v1/payments/orders', [
            'course_id' => $course->id,
        ])->assertCreated()->json('order_id');

        $this->postJson("/api/v1/payments/orders/{$orderId}/attempts", [
            'provider' => 'test_provider',
            'return_url' => 'https://example.com/return',
            'cancel_url' => 'https://example.com/cancel',
        ])->assertCreated();

        $this->assertDatabaseHas('orders', ['id' => $orderId, 'currency' => 'RUB']);
        $this->assertDatabaseHas('order_items', ['order_id' => $orderId, 'currency' => 'RUB']);
        $this->assertDatabaseHas('payment_attempts', ['order_id' => $orderId, 'currency' => 'RUB']);
    }

    public function test_cloudpayments_attempt_returns_backend_owned_widget_payload(): void
    {
        config([
            'services.payments.providers.cloudpayments.webhook_secret' => 'cp-secret',
            'services.payments.providers.cloudpayments.public_id' => 'pk_test_123',
        ]);

        $user = User::factory()->create([
            'role' => 'user_no_purchase',
            'phone_verified_at' => now(),
        ]);
        $course = $this->createCheckoutCourse(priceCents: 150000);
        Sanctum::actingAs($user);

        $orderId = (int) $this->postJson('/api/v1/payments/orders', [
            'course_id' => $course->id,
        ])->assertCreated()->json('order_id');

        $this->postJson("/api/v1/payments/orders/{$orderId}/attempts", [
            'provider' => 'cloudpayments',
            'return_url' => 'https://example.com/return',
            'cancel_url' => 'https://example.com/cancel',
        ])
            ->assertCreated()
            ->assertJsonPath('provider', 'cloudpayments')
            ->assertJsonPath('amount', 150000)
            ->assertJsonPath('currency', 'RUB')
            ->assertJsonPath('provider_payload.mode', 'widget')
            ->assertJsonPath('provider_payload.public_id', 'pk_test_123')
            ->assertJsonPath('provider_payload.invoice_id', fn ($value) => preg_match('/^cp-'.$orderId.'-[1-9][0-9]*$/', $value) === 1)
            ->assertJsonPath('provider_payload.amount', '1500.00');

        $this->assertDatabaseHas('payment_attempts', [
            'order_id' => $orderId,
            'provider' => 'cloudpayments',
            'provider_payment_id' => null,
        ]);
    }

    public function test_order_creation_ignores_client_discount_amount_until_backend_quote_exists(): void
    {
        $user = User::factory()->create(['role' => 'user_no_purchase']);
        $course = $this->createCheckoutCourse(priceCents: 150000);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/payments/orders', [
            'course_id' => $course->id,
            'currency' => 'RUB',
            'discount_context' => [
                'amount_discount' => 150000,
            ],
        ])->assertCreated();

        $this->assertDatabaseHas('orders', [
            'course_id' => $course->id,
            'amount_total' => 150000,
            'amount_discount' => 0,
            'amount_final' => 150000,
        ]);
    }

    public function test_order_creation_rejects_inactive_course(): void
    {
        $user = User::factory()->create(['role' => 'user_no_purchase']);
        $course = $this->createCheckoutCourse(priceCents: 150000, isActive: false);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/payments/orders', [
            'course_id' => $course->id,
            'currency' => 'RUB',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'PAYMENT_COURSE_NOT_FOUND');
    }

    public function test_user_can_create_order_from_sellable_sku(): void
    {
        $user = User::factory()->create(['role' => 'user_no_purchase']);
        [$course, $sellable] = $this->createSellableCheckoutCourse(priceCents: 180000, currency: 'USD');
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/payments/orders', [
            'sellable_sku' => $sellable->sku,
            'price' => 1,
            'currency' => 'USD',
            'discount_context' => [
                'amount_discount' => 180000,
            ],
        ])
            ->assertCreated()
            ->assertJsonPath('payment_status', 'draft')
            ->assertJsonPath('access_status', 'not_granted');

        $orderId = (int) $response->json('order_id');
        /** @var OrderItem $item */
        $item = OrderItem::query()->where('order_id', $orderId)->firstOrFail();

        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'user_id' => $user->id,
            'course_id' => $course->id,
            'currency' => 'USD',
            'amount_total' => 180000,
            'amount_discount' => 0,
            'amount_final' => 180000,
            'payment_status' => 'draft',
            'access_status' => 'not_granted',
        ]);
        $this->assertSame($sellable->id, $item->sellable_id);
        $this->assertSame($sellable->sku, $item->sellable_sku);
        $this->assertSame('USD', $item->currency);
        $this->assertSame(180000, (int) $item->final_amount);
        $this->assertSame(UserEntitlementGrant::ATOM_LESSON, $item->grant_snapshot['atoms'][0]['kind']);
        $this->assertSame(0, UserEntitlementGrant::query()->count());

        $this->postJson("/api/v1/payments/orders/{$orderId}/attempts", [
            'provider' => 'test_provider',
            'return_url' => 'https://example.com/return',
            'cancel_url' => 'https://example.com/cancel',
        ])->assertCreated();

        $this->assertDatabaseHas('payment_attempts', [
            'order_id' => $orderId,
            'currency' => 'USD',
        ]);
    }

    public function test_sellable_order_rejects_client_currency_mismatch_without_side_effects(): void
    {
        $user = User::factory()->create(['role' => 'user_no_purchase']);
        [, $sellable] = $this->createSellableCheckoutCourse(priceCents: 180000, currency: 'USD');
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/payments/orders', [
            'sellable_sku' => $sellable->sku,
            'currency' => 'RUB',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'PAYMENT_CURRENCY_MISMATCH');

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);
        $this->assertDatabaseCount('payment_attempts', 0);
        $this->assertDatabaseCount('payment_audit_log', 0);
        $this->assertDatabaseCount('provider_events', 0);
    }

    public function test_sellable_order_creation_requires_authentication(): void
    {
        [, $sellable] = $this->createSellableCheckoutCourse(priceCents: 180000);

        $this->postJson('/api/v1/payments/orders', [
            'sellable_sku' => $sellable->sku,
        ])->assertUnauthorized();

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);
    }

    public function test_sellable_order_creation_requires_create_order_permission(): void
    {
        $teacher = User::factory()->create(['role' => 'teacher']);
        [, $sellable] = $this->createSellableCheckoutCourse(priceCents: 180000);
        Sanctum::actingAs($teacher);

        $this->postJson('/api/v1/payments/orders', [
            'sellable_sku' => $sellable->sku,
        ])->assertForbidden();

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);
    }

    public function test_order_creation_rejects_unknown_sellable_sku(): void
    {
        $user = User::factory()->create(['role' => 'user_no_purchase']);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/payments/orders', [
            'sellable_sku' => 'missing:sku',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'PAYMENT_SELLABLE_NOT_FOUND');

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);
    }

    public function test_sellable_order_can_create_payment_attempt_after_snapshot_is_saved(): void
    {
        $user = User::factory()->create(['role' => 'user_no_purchase']);
        [, $sellable] = $this->createSellableCheckoutCourse(priceCents: 180000);
        Sanctum::actingAs($user);

        $orderId = (int) $this->postJson('/api/v1/payments/orders', [
            'sellable_sku' => $sellable->sku,
        ])
            ->assertCreated()
            ->json('order_id');

        $this->postJson("/api/v1/payments/orders/{$orderId}/attempts", [
            'provider' => 'test_provider',
            'return_url' => 'https://example.com/return',
            'cancel_url' => 'https://example.com/cancel',
        ])
            ->assertCreated()
            ->assertJsonPath('payment_status', 'pending_payment');
    }

    public function test_order_creation_requires_exactly_one_checkout_source(): void
    {
        $user = User::factory()->create(['role' => 'user_no_purchase']);
        $course = $this->createCheckoutCourse(priceCents: 150000);
        [, $sellable] = $this->createSellableCheckoutCourse(priceCents: 180000);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/payments/orders', [
            'course_id' => $course->id,
            'sellable_sku' => $sellable->sku,
        ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'PAYMENT_ORDER_INPUT_AMBIGUOUS');

        $this->postJson('/api/v1/payments/orders', [])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'PAYMENT_ORDER_INPUT_AMBIGUOUS');

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_order_creation_rejects_inactive_sellable(): void
    {
        $user = User::factory()->create(['role' => 'user_no_purchase']);
        [, $sellable] = $this->createSellableCheckoutCourse(
            priceCents: 180000,
            status: SellableEntity::STATUS_DRAFT
        );
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/payments/orders', [
            'sellable_sku' => $sellable->sku,
        ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'PAYMENT_SELLABLE_NOT_FOUND');

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_order_creation_rolls_back_sellable_without_grant_rules(): void
    {
        $user = User::factory()->create(['role' => 'user_no_purchase']);
        $course = $this->createCheckoutCourse(priceCents: 180000);
        $sellable = SellableEntity::query()->create([
            'sellable_kind' => OrderItem::KIND_COURSE,
            'entity_type' => OrderItem::KIND_COURSE,
            'entity_id' => $course->id,
            'sku' => 'course:empty:'.Str::uuid(),
            'title' => $course->title,
            'currency' => 'RUB',
            'price_cents' => 180000,
            'price_version' => 1,
            'status' => SellableEntity::STATUS_ACTIVE,
            'metadata' => ['slug' => $course->slug],
        ]);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/payments/orders', [
            'sellable_sku' => $sellable->sku,
        ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'PAYMENT_SELLABLE_NOT_GRANTABLE');

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);
    }

    public function test_order_creation_rolls_back_sellable_with_invalid_grant_rule(): void
    {
        $user = User::factory()->create(['role' => 'user_no_purchase']);
        $course = $this->createCheckoutCourse(priceCents: 180000);
        $sellable = SellableEntity::query()->create([
            'sellable_kind' => OrderItem::KIND_COURSE,
            'entity_type' => OrderItem::KIND_COURSE,
            'entity_id' => $course->id,
            'sku' => 'course:invalid-grant:'.Str::uuid(),
            'title' => $course->title,
            'currency' => 'RUB',
            'price_cents' => 180000,
            'price_version' => 1,
            'status' => SellableEntity::STATUS_ACTIVE,
            'metadata' => ['slug' => $course->slug],
        ]);
        SellableGrantRule::query()->create([
            'sellable_entity_id' => $sellable->id,
            'atom_kind' => 'legacy_product',
            'atom_id' => 1,
            'grant_snapshot_version' => 1,
            'sort_order' => 1,
        ]);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/payments/orders', [
            'sellable_sku' => $sellable->sku,
        ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'PAYMENT_SELLABLE_NOT_GRANTABLE');

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);
    }

    public function test_user_can_create_attempt_and_order_moves_to_pending_payment(): void
    {
        $user = User::factory()->create(['role' => 'user_no_purchase']);
        $course = $this->createCheckoutCourse(priceCents: 150000);
        $order = Order::query()->create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'currency' => 'RUB',
            'amount_total' => 150000,
            'amount_final' => 150000,
            'amount_paid' => 0,
            'amount_refunded' => 0,
            'payment_status' => 'draft',
            'access_status' => 'not_granted',
        ]);
        app(OrderItemSnapshotService::class)->createCourseItem($order, $course);
        Sanctum::actingAs($user);

        $this->postJson("/api/v1/payments/orders/{$order->id}/attempts", [
            'provider' => 'test_provider',
            'return_url' => 'https://example.com/return',
            'cancel_url' => 'https://example.com/cancel',
        ])
            ->assertCreated()
            ->assertJsonPath('payment_status', 'pending_payment');
    }

    public function test_user_cannot_create_attempt_for_order_without_items(): void
    {
        $user = User::factory()->create(['role' => 'user_no_purchase']);
        $order = Order::query()->create([
            'user_id' => $user->id,
            'course_id' => 10,
            'currency' => 'RUB',
            'amount_total' => 150000,
            'amount_final' => 150000,
            'amount_paid' => 0,
            'amount_refunded' => 0,
            'payment_status' => 'draft',
            'access_status' => 'not_granted',
        ]);
        Sanctum::actingAs($user);

        $this->postJson("/api/v1/payments/orders/{$order->id}/attempts", [
            'provider' => 'test_provider',
            'return_url' => 'https://example.com/return',
            'cancel_url' => 'https://example.com/cancel',
        ])
            ->assertConflict()
            ->assertJsonPath('error.code', 'PAYMENT_ORDER_REQUIRES_ITEMS');

        $this->assertDatabaseCount('payment_attempts', 0);
        $this->assertSame('draft', (string) $order->fresh()->payment_status);
    }

    public function test_user_cannot_create_attempt_for_another_users_order(): void
    {
        $owner = User::factory()->create(['role' => 'user_no_purchase']);
        $otherUser = User::factory()->create(['role' => 'user_no_purchase']);
        $order = Order::query()->create([
            'user_id' => $owner->id,
            'course_id' => 10,
            'currency' => 'RUB',
            'amount_total' => 150000,
            'amount_final' => 150000,
            'amount_paid' => 0,
            'amount_refunded' => 0,
            'payment_status' => 'draft',
            'access_status' => 'not_granted',
        ]);
        Sanctum::actingAs($otherUser);

        $this->postJson("/api/v1/payments/orders/{$order->id}/attempts", [
            'provider' => 'test_provider',
            'return_url' => 'https://example.com/return',
            'cancel_url' => 'https://example.com/cancel',
        ])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'PAYMENT_ORDER_FORBIDDEN');

        $this->assertDatabaseCount('payment_attempts', 0);
    }

    public function test_user_cannot_read_another_users_order(): void
    {
        $owner = User::factory()->create(['role' => 'user_no_purchase']);
        $otherUser = User::factory()->create(['role' => 'user_no_purchase']);
        $order = Order::query()->create([
            'user_id' => $owner->id,
            'course_id' => 10,
            'currency' => 'RUB',
            'amount_total' => 150000,
            'amount_final' => 150000,
            'amount_paid' => 0,
            'amount_refunded' => 0,
            'payment_status' => 'draft',
            'access_status' => 'not_granted',
        ]);
        Sanctum::actingAs($otherUser);

        $this->getJson("/api/v1/payments/orders/{$order->id}")
            ->assertForbidden()
            ->assertJsonPath('error.code', 'PAYMENT_ORDER_FORBIDDEN');
    }

    public function test_user_cannot_retry_another_users_order(): void
    {
        $owner = User::factory()->create(['role' => 'user_no_purchase']);
        $otherUser = User::factory()->create(['role' => 'user_no_purchase']);
        $order = Order::query()->create([
            'user_id' => $owner->id,
            'course_id' => 10,
            'currency' => 'RUB',
            'amount_total' => 150000,
            'amount_final' => 150000,
            'amount_paid' => 0,
            'amount_refunded' => 0,
            'payment_status' => 'pending_payment',
            'access_status' => 'not_granted',
        ]);
        Sanctum::actingAs($otherUser);

        $this->postJson("/api/v1/payments/orders/{$order->id}/retry", [
            'provider' => 'test_provider',
            'return_url' => 'https://example.com/return',
            'cancel_url' => 'https://example.com/cancel',
        ])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'PAYMENT_ORDER_FORBIDDEN');

        $this->assertDatabaseCount('payment_attempts', 0);
    }

    public function test_manager_can_read_order_of_assigned_user(): void
    {
        $manager = User::factory()->create(['role' => 'manager']);
        $owner = User::factory()->create([
            'role' => 'user_no_purchase',
            'manager_id' => $manager->id,
        ]);
        $order = Order::query()->create([
            'user_id' => $owner->id,
            'course_id' => 10,
            'currency' => 'RUB',
            'amount_total' => 150000,
            'amount_final' => 150000,
            'amount_paid' => 0,
            'amount_refunded' => 0,
            'payment_status' => 'draft',
            'access_status' => 'not_granted',
        ]);
        Sanctum::actingAs($manager);

        $this->getJson("/api/v1/payments/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('order_id', $order->id);
    }

    public function test_manager_cannot_read_or_mutate_unassigned_users_order(): void
    {
        config([
            'rbac.permissions.manager' => [
                ...config('rbac.permissions.manager'),
                'payments.create_attempt',
                'payments.retry_attempt',
            ],
        ]);

        $manager = User::factory()->create(['role' => 'manager']);
        $otherManager = User::factory()->create(['role' => 'manager']);
        $owner = User::factory()->create([
            'role' => 'user_no_purchase',
            'manager_id' => $otherManager->id,
        ]);
        $order = Order::query()->create([
            'user_id' => $owner->id,
            'course_id' => 10,
            'currency' => 'RUB',
            'amount_total' => 150000,
            'amount_final' => 150000,
            'amount_paid' => 0,
            'amount_refunded' => 0,
            'payment_status' => 'draft',
            'access_status' => 'not_granted',
        ]);
        Sanctum::actingAs($manager);

        $this->getJson("/api/v1/payments/orders/{$order->id}")
            ->assertForbidden()
            ->assertJsonPath('error.code', 'PAYMENT_ORDER_FORBIDDEN')
            ->assertJsonMissingPath('order_id');

        $this->postJson("/api/v1/payments/orders/{$order->id}/attempts", [
            'provider' => 'test_provider',
            'return_url' => 'https://example.com/return',
            'cancel_url' => 'https://example.com/cancel',
        ])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'PAYMENT_ORDER_FORBIDDEN');

        $this->postJson("/api/v1/payments/orders/{$order->id}/retry", [
            'provider' => 'test_provider',
            'return_url' => 'https://example.com/return',
            'cancel_url' => 'https://example.com/cancel',
        ])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'PAYMENT_ORDER_FORBIDDEN');

        $this->assertDatabaseCount('payment_attempts', 0);
        $this->assertSame('draft', (string) $order->fresh()->payment_status);
    }

    public function test_admin_full_can_read_order(): void
    {
        $admin = User::factory()->create(['role' => 'admin_full']);
        $order = Order::query()->create([
            'payment_status' => 'pending_payment',
            'access_status' => 'not_granted',
            'currency' => 'RUB',
            'amount_total' => 150000,
            'amount_final' => 150000,
            'amount_paid' => 0,
            'amount_refunded' => 0,
        ]);
        Sanctum::actingAs($admin);

        $this->getJson("/api/v1/payments/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('payment_status', 'pending_payment');
    }

    public function test_webhook_is_idempotent_by_v1_tuple(): void
    {
        config(['queue.default' => 'sync']);
        $this->configureTestProviderWebhook();

        [$order, $attempt] = $this->createPendingOrderWithAttempt();

        $payload = [
            'event_id' => 'evt-100',
            'event_type' => 'payment.succeeded',
            'status' => 'paid',
            'order_id' => $order->id,
            'attempt_id' => $attempt->id,
            'provider_payment_id' => $attempt->provider_payment_id,
            'amount' => 150000,
            'currency' => 'RUB',
        ];

        $this->postSignedTestProviderWebhook($payload)
            ->assertOk()
            ->assertJsonPath('status', 'accepted')
            ->assertJsonPath('duplicate', false);

        $this->postSignedTestProviderWebhook($payload)
            ->assertOk()
            ->assertJsonPath('status', 'accepted')
            ->assertJsonPath('duplicate', true);

        $this->assertSame('paid', (string) $order->fresh()->payment_status);
        $this->assertSame('granted', (string) $order->fresh()->access_status);
        $this->assertSame(150000, (int) $order->fresh()->amount_paid);
        $this->assertSame('completed', (string) $attempt->fresh()->status);
        $this->assertSame(1, ProviderEvent::query()->count());
        $this->assertSame(1, $order->fresh()->items()->count());
        $this->assertDatabaseCount('user_entitlement_grants', 1);
        $this->assertSame(1, SideEffectExecution::query()->where('handler', 'entitlement.grant')->count());
        $this->assertSame(1, SideEffectExecution::query()->where('handler', 'receipt.income.issue')->count());
    }

    public function test_webhook_rejects_missing_source_allowlist(): void
    {
        config([
            'services.payments.providers.test_provider.webhook_secret' => 'top-secret',
            'services.payments.providers.test_provider.webhook_allowed_ips' => [],
        ]);

        [$order, $attempt] = $this->createPendingOrderWithAttempt();

        $this->postSignedTestProviderWebhook([
            'event_id' => 'evt-no-allowlist',
            'event_type' => 'payment.succeeded',
            'status' => 'paid',
            'order_id' => $order->id,
            'attempt_id' => $attempt->id,
            'amount' => 150000,
            'currency' => 'RUB',
        ])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'PAYMENT_WEBHOOK_SOURCE_POLICY_MISSING');

        $this->assertSame('pending_payment', (string) $order->fresh()->payment_status);
        $this->assertDatabaseCount('provider_events', 0);
    }

    public function test_webhook_rejects_unknown_provider_even_when_secret_is_configured(): void
    {
        config([
            'services.payments.providers.unknown_provider.webhook_secret' => 'top-secret',
            'services.payments.providers.unknown_provider.webhook_allowed_ips' => ['127.0.0.1'],
        ]);

        [$order, $attempt] = $this->createPendingOrderWithAttempt();

        $this->postSignedTestProviderWebhook([
            'event_id' => 'evt-unknown-provider',
            'event_type' => 'payment.succeeded',
            'status' => 'paid',
            'order_id' => $order->id,
            'attempt_id' => $attempt->id,
            'amount' => 150000,
            'currency' => 'RUB',
        ], provider: 'unknown_provider')
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'PAYMENT_PROVIDER_UNKNOWN');

        $this->assertSame('pending_payment', (string) $order->fresh()->payment_status);
        $this->assertDatabaseCount('provider_events', 0);
    }

    public function test_webhook_rejects_missing_event_type(): void
    {
        $this->configureTestProviderWebhook();

        [$order, $attempt] = $this->createPendingOrderWithAttempt();

        $this->postSignedTestProviderWebhook([
            'event_id' => 'evt-missing-type',
            'status' => 'paid',
            'order_id' => $order->id,
            'attempt_id' => $attempt->id,
            'amount' => 150000,
            'currency' => 'RUB',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'PAYMENT_VALIDATION_FAILED');

        $this->assertSame('pending_payment', (string) $order->fresh()->payment_status);
        $this->assertDatabaseCount('provider_events', 0);
    }

    public function test_webhook_rejects_amount_or_currency_mismatch_without_granting_access(): void
    {
        $this->configureTestProviderWebhook();

        [$order, $attempt] = $this->createPendingOrderWithAttempt();

        $this->postSignedTestProviderWebhook([
            'event_id' => 'evt-amount-mismatch',
            'event_type' => 'payment.succeeded',
            'status' => 'paid',
            'order_id' => $order->id,
            'attempt_id' => $attempt->id,
            'amount' => 149999,
            'currency' => 'RUB',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'PAYMENT_VALIDATION_FAILED');

        $this->postSignedTestProviderWebhook([
            'event_id' => 'evt-currency-mismatch',
            'event_type' => 'payment.succeeded',
            'status' => 'paid',
            'order_id' => $order->id,
            'attempt_id' => $attempt->id,
            'amount' => 150000,
            'currency' => 'USD',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'PAYMENT_VALIDATION_FAILED');

        $this->assertSame('pending_payment', (string) $order->fresh()->payment_status);
        $this->assertSame('not_granted', (string) $order->fresh()->access_status);
        $this->assertSame(0, (int) $order->fresh()->amount_paid);
        $this->assertDatabaseCount('provider_events', 0);
    }

    public function test_webhook_rejects_attempt_that_does_not_belong_to_order(): void
    {
        $this->configureTestProviderWebhook();

        [$order] = $this->createPendingOrderWithAttempt();
        [, $otherAttempt] = $this->createPendingOrderWithAttempt();

        $this->postSignedTestProviderWebhook([
            'event_id' => 'evt-wrong-attempt',
            'event_type' => 'payment.succeeded',
            'status' => 'paid',
            'order_id' => $order->id,
            'attempt_id' => $otherAttempt->id,
            'amount' => 150000,
            'currency' => 'RUB',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'PAYMENT_VALIDATION_FAILED');

        $this->assertSame('pending_payment', (string) $order->fresh()->payment_status);
        $this->assertSame('pending', (string) $otherAttempt->fresh()->status);
        $this->assertDatabaseCount('provider_events', 0);
    }

    public function test_webhook_rejects_idempotency_drift_for_same_provider_event_id(): void
    {
        config(['queue.default' => 'sync']);
        $this->configureTestProviderWebhook();

        [$order, $attempt] = $this->createPendingOrderWithAttempt();

        $this->postSignedTestProviderWebhook([
            'event_id' => 'evt-drift',
            'event_type' => 'payment.succeeded',
            'status' => 'paid',
            'order_id' => $order->id,
            'attempt_id' => $attempt->id,
            'amount' => 150000,
            'currency' => 'RUB',
        ])->assertOk();

        $this->postSignedTestProviderWebhook([
            'event_id' => 'evt-drift',
            'event_type' => 'payment.cancelled',
            'status' => 'failed',
            'order_id' => $order->id,
            'attempt_id' => $attempt->id,
            'amount' => 150000,
            'currency' => 'RUB',
        ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'PAYMENT_WEBHOOK_IDEMPOTENCY_DRIFT');

        $this->assertDatabaseCount('provider_events', 1);
        $this->assertSame(1, SideEffectExecution::query()->where('handler', 'entitlement.grant')->count());
        $this->assertSame('paid', (string) $order->fresh()->payment_status);
        $this->assertSame('completed', (string) $attempt->fresh()->status);
    }

    public function test_webhook_does_not_cancel_paid_order_or_attempt(): void
    {
        $this->configureTestProviderWebhook();

        [$order, $attempt] = $this->createPendingOrderWithAttempt();
        $order->payment_status = 'paid';
        $order->access_status = 'granted';
        $order->amount_paid = 150000;
        $order->save();
        $attempt->status = 'completed';
        $attempt->completed_at = now();
        $attempt->save();

        $this->postSignedTestProviderWebhook([
            'event_id' => 'evt-cancel-after-paid',
            'event_type' => 'payment.failed',
            'status' => 'failed',
            'order_id' => $order->id,
            'attempt_id' => $attempt->id,
            'amount' => 150000,
            'currency' => 'RUB',
        ])
            ->assertOk()
            ->assertJsonPath('status', 'accepted')
            ->assertJsonPath('duplicate', false);

        $this->assertSame('paid', (string) $order->fresh()->payment_status);
        $this->assertSame('granted', (string) $order->fresh()->access_status);
        $this->assertSame('completed', (string) $attempt->fresh()->status);
        $this->assertDatabaseHas('payment_audit_log', [
            'order_id' => $order->id,
            'attempt_id' => $attempt->id,
            'action' => 'webhook_rejected',
            'reason' => 'INVALID_TRANSITION',
        ]);
    }

    public function test_webhook_rejects_invalid_signature_with_v1_error_model(): void
    {
        config([
            'services.payments.providers.test_provider.webhook_secret' => 'top-secret',
            'services.payments.providers.test_provider.webhook_allowed_ips' => ['127.0.0.1'],
        ]);

        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->withHeaders(['X-Payment-Signature' => 'invalid'])
            ->postJson('/api/v1/payments/webhooks/test_provider', [
                'event_id' => 'evt-bad-signature',
                'event_type' => 'payment.succeeded',
            ])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'PAYMENT_SIGNATURE_INVALID')
            ->assertJsonPath('error.request_id', fn ($value) => is_string($value) && $value !== '');
    }

    public function test_refund_requires_paid_status_and_returns_403(): void
    {
        $admin = User::factory()->create(['role' => 'admin_full']);
        $order = Order::query()->create([
            'payment_status' => 'pending_payment',
            'access_status' => 'not_granted',
            'currency' => 'RUB',
            'amount_total' => 100000,
            'amount_final' => 100000,
            'amount_paid' => 100000,
            'amount_refunded' => 0,
        ]);
        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/payments/orders/{$order->id}/refund", [
            'refund_type' => 'partial',
            'amount' => 10000,
            'reason' => 'test',
            'policy_access_action' => 'keep',
        ])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'PAYMENT_REFUND_FORBIDDEN');
    }

    public function test_full_refund_transitions_paid_to_refunded(): void
    {
        $admin = User::factory()->create(['role' => 'admin_full']);
        $order = Order::query()->create([
            'payment_status' => 'paid',
            'access_status' => 'granted',
            'currency' => 'RUB',
            'amount_total' => 100000,
            'amount_final' => 100000,
            'amount_paid' => 100000,
            'amount_refunded' => 0,
        ]);
        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/payments/orders/{$order->id}/refund", [
            'refund_type' => 'full',
            'reason' => 'test full',
            'policy_access_action' => 'revoke',
        ])
            ->assertStatus(202)
            ->assertJsonPath('payment_status', 'refunded')
            ->assertJsonPath('access_status', 'revoked');
    }

    public function test_full_refund_revoke_revokes_order_owned_grants_and_denies_lesson_access(): void
    {
        [$order, $attempt] = $this->createPendingOrderWithAttempt(amount: 100000);
        $student = $order->user()->firstOrFail();
        $student->forceFill(['phone_verified_at' => now()])->save();
        $course = Course::query()->with(['subject', 'lessons'])->findOrFail($order->course_id);
        $lesson = $course->lessons->firstOrFail();

        $this->settleOrder($order, 100000, 'refund-revoke-grant-deny', $attempt->id);

        $grant = UserEntitlementGrant::query()
            ->where('order_id', $order->id)
            ->where('atom_id', $lesson->id)
            ->firstOrFail();
        $this->assertSame(UserEntitlementGrant::STATUS_ACTIVE, (string) $grant->status);

        Sanctum::actingAs($student);
        $this->getJson($this->lessonUrl($course, $lesson))->assertOk();

        Sanctum::actingAs(User::factory()->create(['role' => 'admin_full']));
        $this->postJson("/api/v1/payments/orders/{$order->id}/refund", [
            'refund_type' => 'full',
            'reason' => 'QC26-H04 regression',
            'policy_access_action' => 'revoke',
        ])
            ->assertStatus(202)
            ->assertJsonPath('payment_status', 'refunded')
            ->assertJsonPath('access_status', 'revoked');

        $grant->refresh();
        $this->assertSame(UserEntitlementGrant::STATUS_REVOKED, (string) $grant->status);
        $this->assertNotNull($grant->revoked_at);
        $this->assertStringStartsWith('refund:', (string) $grant->revoked_reason);
        $this->assertSame('revoked', (string) $order->items()->firstOrFail()->access_status);

        $repeatRevoke = app(EntitlementGrantService::class)->revokeOrderGrants(
            (int) $student->id,
            (int) $order->id,
            [(int) $order->items()->firstOrFail()->id],
            'refund:repeat',
        );
        $this->assertSame([
            'matched' => 1,
            'revoked' => 0,
            'already_inactive' => 1,
        ], $repeatRevoke);

        $access = app(AccessResolver::class)->check($student->fresh(), AccessResolver::ENTITY_LESSON, (int) $lesson->id);
        $this->assertSame(AccessResolver::ACCESS_NONE, $access['access']);

        Sanctum::actingAs($student->fresh());
        $this->getJson($this->lessonUrl($course, $lesson))
            ->assertForbidden()
            ->assertJsonPath('error.code', 'LEARNING_ACCESS_DENIED');
    }

    public function test_refund_revoke_preserves_independent_grants_for_same_atom(): void
    {
        [$order, $attempt] = $this->createPendingOrderWithAttempt(amount: 100000);
        $student = $order->user()->firstOrFail();
        $course = Course::query()->with(['lessons'])->findOrFail($order->course_id);
        $lesson = $course->lessons->firstOrFail();

        $this->settleOrder($order, 100000, 'refund-revoke-independent-source', $attempt->id);

        $independentGrant = UserEntitlementGrant::query()->create([
            'user_id' => $student->id,
            'atom_kind' => UserEntitlementGrant::ATOM_LESSON,
            'atom_id' => $lesson->id,
            'status' => UserEntitlementGrant::STATUS_ACTIVE,
            'source_type' => UserEntitlementGrant::SOURCE_ADMIN_GRANT,
            'source_id' => 'manual-independent',
            'idempotency_key' => 'manual-independent-'.$lesson->id,
            'granted_at' => now(),
            'metadata' => ['scope' => 'QC26-H04 independent source'],
        ]);

        Sanctum::actingAs(User::factory()->create(['role' => 'admin_full']));
        $this->postJson("/api/v1/payments/orders/{$order->id}/refund", [
            'refund_type' => 'full',
            'reason' => 'QC26-H04 independent source',
            'policy_access_action' => 'revoke',
        ])->assertStatus(202);

        $orderGrant = UserEntitlementGrant::query()
            ->where('order_id', $order->id)
            ->where('source_type', UserEntitlementGrant::SOURCE_ORDER_ITEM)
            ->firstOrFail();
        $independentGrant->refresh();

        $this->assertSame(UserEntitlementGrant::STATUS_REVOKED, (string) $orderGrant->status);
        $this->assertSame(UserEntitlementGrant::STATUS_ACTIVE, (string) $independentGrant->status);
        $this->assertNull($independentGrant->order_id);
        $this->assertSame('manual-independent', (string) $independentGrant->source_id);
        $this->assertSame('manual-independent-'.$lesson->id, (string) $independentGrant->idempotency_key);

        $access = app(AccessResolver::class)->check($student, AccessResolver::ENTITY_LESSON, (int) $lesson->id);
        $this->assertSame(AccessResolver::ACCESS_FULL, $access['access']);
    }

    public function test_refund_keep_preserves_grants_and_previous_access_status(): void
    {
        [$order, $attempt] = $this->createPendingOrderWithAttempt(amount: 100000);
        $this->settleOrder($order, 100000, 'refund-keep-granted-order', $attempt->id);
        $grant = UserEntitlementGrant::query()->where('order_id', $order->id)->firstOrFail();
        $admin = User::factory()->create(['role' => 'admin_full']);

        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/payments/orders/{$order->id}/refund", [
            'refund_type' => 'full',
            'reason' => 'QC26-H04 keep full',
            'policy_access_action' => 'keep',
        ])
            ->assertStatus(202)
            ->assertJsonPath('payment_status', 'refunded')
            ->assertJsonPath('access_status', 'granted');

        $grant->refresh();
        $this->assertSame(UserEntitlementGrant::STATUS_ACTIVE, (string) $grant->status);
        $this->assertNull($grant->revoked_at);

        $partialKeepOrder = Order::query()->create([
            'payment_status' => 'paid',
            'access_status' => 'grant_failed',
            'currency' => 'RUB',
            'amount_total' => 100000,
            'amount_final' => 100000,
            'amount_paid' => 100000,
            'amount_refunded' => 0,
        ]);

        $this->postJson("/api/v1/payments/orders/{$partialKeepOrder->id}/refund", [
            'refund_type' => 'partial',
            'amount' => 10000,
            'reason' => 'QC26-H04 keep partial',
            'policy_access_action' => 'keep',
        ])
            ->assertStatus(202)
            ->assertJsonPath('payment_status', 'paid')
            ->assertJsonPath('access_status', 'grant_failed');
    }

    public function test_cloudpayments_refund_revokes_order_owned_grants(): void
    {
        config([
            'services.payments.providers.cloudpayments.webhook_secret' => 'cp-secret',
            'services.payments.providers.cloudpayments.webhook_allowed_ips' => ['127.0.0.1'],
        ]);

        [$order, $attempt] = $this->createPendingOrderWithAttempt(amount: 100000, provider: 'cloudpayments');
        $this->settleOrder($order, 100000, 'cloudpayments-refund-revoke', $attempt->id);
        $attempt->update(['provider_payment_id' => '77']);
        $grant = UserEntitlementGrant::query()->where('order_id', $order->id)->firstOrFail();

        $this->postSignedCloudpaymentsWebhook('refund', [
            'InvoiceId' => $order->id,
            'Amount' => '1000.00',
            'TransactionId' => 77,
        ])
            ->assertOk()
            ->assertJson(['code' => 0]);

        $this->assertSame('refunded', (string) $order->fresh()->payment_status);
        $this->assertSame('revoked', (string) $order->fresh()->access_status);
        $this->assertSame('revoked', (string) $order->items()->firstOrFail()->access_status);
        $this->assertSame(UserEntitlementGrant::STATUS_REVOKED, (string) $grant->fresh()->status);
        $this->assertNotNull($grant->fresh()->revoked_at);
    }

    public function test_admin_full_can_request_replay_with_reason_and_incident(): void
    {
        $admin = User::factory()->create(['role' => 'admin_full']);
        [$order] = $this->createPendingOrderWithAttempt(amount: 100000, provider: 'cloudpayments');
        $event = ProviderEvent::query()->create([
            'provider' => 'test_provider',
            'provider_event_id' => 'evt-1',
            'event_type' => 'payment.succeeded',
            'order_id' => $order->id,
            'signature_valid' => true,
            'processed' => true,
            'payload_raw' => ['foo' => 'bar'],
            'received_at' => now(),
        ]);
        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/payments/events/{$event->id}/replay", [
            'reason' => 'incident replay',
            'incident_id' => 'INC-100',
        ])
            ->assertStatus(202)
            ->assertJsonPath('status', 'accepted');
    }

    public function test_cloudpayments_check_returns_code_zero_for_valid_payload(): void
    {
        config([
            'services.payments.providers.cloudpayments.webhook_secret' => 'cp-secret',
            'services.payments.providers.cloudpayments.webhook_allowed_ips' => ['127.0.0.1'],
        ]);

        [$order] = $this->createPendingOrderWithAttempt(amount: 100000, provider: 'cloudpayments');

        $payload = ['InvoiceId' => $order->id, 'Amount' => '1000.00', 'TransactionId' => 77, 'Currency' => 'RUB', 'TestMode' => 0, 'Status' => 'Completed', 'OperationType' => 'Payment'];
        $raw = json_encode($payload, JSON_THROW_ON_ERROR);
        $signature = base64_encode(hash_hmac('sha256', $raw, 'cp-secret', true));

        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->withHeaders(['Content-HMAC' => $signature])
            ->postJson('/api/v1/payments/webhooks/cloudpayments/check', $payload)
            ->assertOk()
            ->assertJson(['code' => 0]);
    }

    public function test_cloudpayments_pay_moves_order_to_paid(): void
    {
        config(['queue.default' => 'sync']);
        config([
            'services.payments.providers.cloudpayments.webhook_secret' => 'cp-secret',
            'services.payments.providers.cloudpayments.webhook_allowed_ips' => ['127.0.0.1'],
        ]);

        [$order] = $this->createPendingOrderWithAttempt(amount: 100000, provider: 'cloudpayments');

        $payload = ['InvoiceId' => $order->id, 'Amount' => '1000.00', 'TransactionId' => 77, 'Currency' => 'RUB', 'TestMode' => 0, 'Status' => 'Completed', 'OperationType' => 'Payment'];
        $raw = json_encode($payload, JSON_THROW_ON_ERROR);
        $signature = base64_encode(hash_hmac('sha256', $raw, 'cp-secret', true));

        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->withHeaders(['Content-HMAC' => $signature])
            ->postJson('/api/v1/payments/webhooks/cloudpayments/pay', $payload)
            ->assertOk()
            ->assertJson(['code' => 0]);

        $this->assertSame('paid', (string) $order->fresh()->payment_status);
        $this->assertSame('granted', (string) $order->fresh()->access_status);
        $this->assertDatabaseCount('user_entitlement_grants', 1);
    }

    public function test_cloudpayments_webhook_rejects_missing_source_allowlist(): void
    {
        config([
            'services.payments.providers.cloudpayments.webhook_secret' => 'cp-secret',
            'services.payments.providers.cloudpayments.webhook_allowed_ips' => [],
        ]);

        [$order] = $this->createPendingOrderWithAttempt(amount: 100000, provider: 'cloudpayments');

        $this->postSignedCloudpaymentsWebhook('pay', [
            'InvoiceId' => $order->id,
            'Amount' => '1000.00',
            'TransactionId' => 77,
        ])
            ->assertOk()
            ->assertJson(['code' => 13]);

        $this->assertSame('pending_payment', (string) $order->fresh()->payment_status);
        $this->assertSame('not_granted', (string) $order->fresh()->access_status);
        $this->assertSame(0, (int) $order->fresh()->amount_paid);
        $this->assertDatabaseCount('user_entitlement_grants', 0);
    }

    public function test_cloudpayments_pay_requires_positive_matching_amount(): void
    {
        config([
            'services.payments.providers.cloudpayments.webhook_secret' => 'cp-secret',
            'services.payments.providers.cloudpayments.webhook_allowed_ips' => ['127.0.0.1'],
        ]);

        [$order] = $this->createPendingOrderWithAttempt(amount: 100000, provider: 'cloudpayments');

        $this->postSignedCloudpaymentsWebhook('pay', [
            'InvoiceId' => $order->id,
            'TransactionId' => 77,
        ])
            ->assertOk()
            ->assertJson(['code' => 12]);

        $this->postSignedCloudpaymentsWebhook('pay', [
            'InvoiceId' => $order->id,
            'Amount' => '0.00',
            'TransactionId' => 78,
        ])
            ->assertOk()
            ->assertJson(['code' => 12]);

        $this->postSignedCloudpaymentsWebhook('pay', [
            'InvoiceId' => $order->id,
            'Amount' => '999.99',
            'TransactionId' => 79,
        ])
            ->assertOk()
            ->assertJson(['code' => 12]);

        $this->assertSame('pending_payment', (string) $order->fresh()->payment_status);
        $this->assertSame('not_granted', (string) $order->fresh()->access_status);
        $this->assertSame(0, (int) $order->fresh()->amount_paid);
        $this->assertDatabaseCount('user_entitlement_grants', 0);
    }

    public function test_cloudpayments_webhook_rejects_order_with_another_active_provider(): void
    {
        config([
            'services.payments.providers.cloudpayments.webhook_secret' => 'cp-secret',
            'services.payments.providers.cloudpayments.webhook_allowed_ips' => ['127.0.0.1'],
        ]);

        [$order] = $this->createPendingOrderWithAttempt(amount: 100000, provider: 'test_provider');

        $this->postSignedCloudpaymentsWebhook('check', [
            'InvoiceId' => $order->id,
            'Amount' => '1000.00',
        ])
            ->assertOk()
            ->assertJson(['code' => 13]);

        $this->postSignedCloudpaymentsWebhook('pay', [
            'InvoiceId' => $order->id,
            'Amount' => '1000.00',
            'TransactionId' => 77,
        ])
            ->assertOk()
            ->assertJson(['code' => 13]);

        $this->assertSame('pending_payment', (string) $order->fresh()->payment_status);
        $this->assertSame('not_granted', (string) $order->fresh()->access_status);
        $this->assertDatabaseCount('user_entitlement_grants', 0);
    }

    public function test_cloudpayments_refund_requires_positive_amount_within_paid_total(): void
    {
        config([
            'services.payments.providers.cloudpayments.webhook_secret' => 'cp-secret',
            'services.payments.providers.cloudpayments.webhook_allowed_ips' => ['127.0.0.1'],
        ]);

        [$order] = $this->createPendingOrderWithAttempt(amount: 100000, provider: 'cloudpayments');
        $order->payment_status = 'paid';
        $order->access_status = 'granted';
        $order->amount_paid = 100000;
        $order->save();

        $order->activeAttempt()->update(['provider_payment_id' => '77']);

        $this->postSignedCloudpaymentsWebhook('refund', [
            'InvoiceId' => $order->id,
            'TransactionId' => 77,
        ])
            ->assertOk()
            ->assertJson(['code' => 12]);

        $this->postSignedCloudpaymentsWebhook('refund', [
            'InvoiceId' => $order->id,
            'Amount' => '1000.01',
            'TransactionId' => 78,
        ])
            ->assertOk()
            ->assertJson(['code' => 12]);

        $this->assertSame('paid', (string) $order->fresh()->payment_status);
        $this->assertSame('granted', (string) $order->fresh()->access_status);
        $this->assertSame(0, (int) $order->fresh()->amount_refunded);
    }

    public function test_cloudpayments_webhook_rejects_invalid_signature(): void
    {
        config([
            'services.payments.providers.cloudpayments.webhook_secret' => 'cp-secret',
            'services.payments.providers.cloudpayments.webhook_allowed_ips' => ['127.0.0.1'],
        ]);

        [$order] = $this->createPendingOrderWithAttempt(amount: 100000, provider: 'cloudpayments');

        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->withHeaders(['Content-HMAC' => 'bad-signature'])
            ->postJson('/api/v1/payments/webhooks/cloudpayments/pay', [
                'InvoiceId' => $order->id,
                'Amount' => '1000.00',
            ])
            ->assertOk()
            ->assertJson(['code' => 13]);
    }

    public function test_cloudpayments_pay_rejects_drift_and_never_grants_on_check(): void
    {
        config([
            'services.payments.providers.cloudpayments.webhook_secret' => 'cp-secret',
            'services.payments.providers.cloudpayments.webhook_allowed_ips' => ['127.0.0.0/8'],
            'services.payments.providers.cloudpayments.test_mode' => false,
        ]);
        [$order, $attempt] = $this->createPendingOrderWithAttempt(amount: 100000, provider: 'cloudpayments');
        $payload = ['InvoiceId' => $order->id, 'Amount' => '1000.00'];
        foreach ([
            ['Currency' => 'USD'], ['Currency' => null], ['TestMode' => 1],
            ['TestMode' => null], ['Status' => 'Authorized'], ['OperationType' => 'Refund'],
            ['TransactionId' => null], ['InvoiceId' => $order->id.'oops'], ['Amount' => '1000.001'],
        ] as $invalid) {
            $response = $this->postSignedCloudpaymentsWebhook('pay', array_merge($payload, $invalid))->assertOk();
            $this->assertNotSame(0, $response->json('code'));
        }
        $this->assertDatabaseCount('order_settlements', 0);
        $this->postSignedCloudpaymentsWebhook('check', $payload)->assertJson(['code' => 0]);
        $this->assertSame('77', $attempt->fresh()->provider_payment_id);
        $this->assertDatabaseCount('user_entitlement_grants', 0);
        $this->postSignedCloudpaymentsWebhook('check', [...$payload, 'TransactionId' => 78])->assertJson(['code' => 13]);
        $this->postSignedCloudpaymentsWebhook('pay', $payload)->assertJson(['code' => 0]);
        $this->postSignedCloudpaymentsWebhook('pay', $payload)->assertJson(['code' => 0]);
        $this->postSignedCloudpaymentsWebhook('pay', [...$payload, 'TransactionId' => 78])->assertJson(['code' => 13]);
        $this->postSignedCloudpaymentsWebhook('check', $payload)->assertJson(['code' => 13]);
        $this->assertDatabaseCount('order_settlements', 1);
        $this->assertDatabaseCount('user_entitlement_grants', 1);
        $this->assertSame(1, ProviderEvent::query()->where('provider', 'cloudpayments')->count());
        $this->assertSame(1, PaymentAuditLog::query()->where('action', 'cloudpayments_webhook_pay')->count());
    }

    public function test_cloudpayments_refund_is_bound_accumulative_and_idempotent(): void
    {
        config([
            'services.payments.providers.cloudpayments.webhook_secret' => 'cp-secret',
            'services.payments.providers.cloudpayments.webhook_allowed_ips' => ['127.0.0.1'],
        ]);
        [$order] = $this->createPendingOrderWithAttempt(amount: 100000, provider: 'cloudpayments');
        $this->postSignedCloudpaymentsWebhook('pay', ['InvoiceId' => $order->id, 'Amount' => '1000.00'])->assertJson(['code' => 0]);
        $refund = ['InvoiceId' => $order->id, 'Amount' => '400.00', 'TransactionId' => 80];
        $this->postSignedCloudpaymentsWebhook('refund', [...$refund, 'PaymentTransactionId' => 999])->assertJson(['code' => 13]);
        $this->postSignedCloudpaymentsWebhook('refund', $refund)->assertJson(['code' => 0]);
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertSame('granted', $order->fresh()->access_status);
        $this->postSignedCloudpaymentsWebhook('refund', $refund)->assertJson(['code' => 0]);
        $this->postSignedCloudpaymentsWebhook('refund', [...$refund, 'Amount' => '500.00'])->assertJson(['code' => 13]);
        $this->postSignedCloudpaymentsWebhook('refund', [...$refund, 'TransactionId' => 81, 'Amount' => '600.01'])->assertJson(['code' => 12]);
        $this->postSignedCloudpaymentsWebhook('refund', [...$refund, 'TransactionId' => 81, 'Amount' => '600.00'])->assertJson(['code' => 0]);
        $this->postSignedCloudpaymentsWebhook('refund', $refund)->assertJson(['code' => 0]);
        $this->assertSame(100000, (int) $order->fresh()->amount_refunded);
        $this->assertSame('revoked', $order->fresh()->access_status);
        $this->assertSame(2, Refund::query()->where('order_id', $order->id)->where('status', 'completed')->count());
        $this->assertSame(2, OutboxEvent::query()->where('order_id', $order->id)->whereNotNull('refund_id')->count());
    }

    public function test_cloudpayments_refund_request_waits_for_confirmation_and_is_durable_on_replay(): void
    {
        config([
            'services.payments.providers.cloudpayments.webhook_secret' => 'cp-secret',
            'services.payments.providers.cloudpayments.public_id' => 'pk_test',
            'services.payments.providers.cloudpayments.webhook_allowed_ips' => ['127.0.0.1'],
        ]);
        [$order] = $this->createPendingOrderWithAttempt(amount: 100000, provider: 'cloudpayments');
        $this->postSignedCloudpaymentsWebhook('pay', ['InvoiceId' => $order->id, 'Amount' => '1000.00'])->assertJson(['code' => 0]);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin_full']));
        Http::preventStrayRequests();
        Http::fake(['api.cloudpayments.ru/payments/refund' => Http::response(['Success' => true, 'Model' => ['TransactionId' => 800]])]);
        $key = (string) Str::uuid();
        $request = ['refund_type' => 'full', 'reason' => 'Requested by buyer', 'policy_access_action' => 'revoke'];
        $url = "/api/v1/payments/orders/{$order->id}/refund";
        $first = $this->withHeader('Idempotency-Key', $key)->postJson($url, $request)->assertAccepted()->assertJsonPath('status', 'submitted')->json('refund_id');
        $this->assertSame(0, (int) $order->fresh()->amount_refunded);
        $this->assertSame('granted', $order->fresh()->access_status);
        $this->withHeader('Idempotency-Key', $key)->postJson($url, $request)->assertAccepted()->assertJsonPath('refund_id', $first);
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson($url, $request)->assertConflict();
        Http::assertSentCount(1);
        Http::assertSent(fn ($sent) => $sent->hasHeader('X-Request-ID', $key)
            && $sent['Amount'] === '1000.00' && $sent['TransactionId'] === '77');
        $payload = ['InvoiceId' => $order->id, 'Amount' => '1000.00', 'TransactionId' => 800];
        $this->postSignedCloudpaymentsWebhook('refund', $payload)->assertJson(['code' => 0]);
        $this->postSignedCloudpaymentsWebhook('refund', $payload)->assertJson(['code' => 0]);
        $this->assertSame(100000, (int) $order->fresh()->amount_refunded);
        $this->assertSame('completed', Refund::query()->findOrFail($first)->status);
        $this->assertSame(1, Refund::query()->where('order_id', $order->id)->count());
        $this->withHeader('Idempotency-Key', $key)->postJson($url, $request)->assertAccepted()->assertJsonPath('status', 'completed');
        Http::assertSentCount(1);
    }

    public function test_trusted_system_refund_has_explicit_audit_and_remains_idempotent(): void
    {
        config([
            'services.payments.providers.cloudpayments.webhook_secret' => 'cp-secret',
            'services.payments.providers.cloudpayments.public_id' => 'pk_test',
            'services.payments.providers.cloudpayments.webhook_allowed_ips' => ['127.0.0.1'],
        ]);
        [$order] = $this->createPendingOrderWithAttempt(amount: 100000, provider: 'cloudpayments');
        $this->postSignedCloudpaymentsWebhook('pay', ['InvoiceId' => $order->id, 'Amount' => '1000.00'])->assertJson(['code' => 0]);
        Http::preventStrayRequests();
        Http::fake(['api.cloudpayments.ru/payments/refund' => Http::response(['Success' => true, 'Model' => ['TransactionId' => 800]])]);
        $service = app(CloudpaymentsRefundService::class);
        $input = ['refund_type' => 'full', 'reason' => 'Authorized system verification', 'policy_access_action' => 'revoke'];
        $key = (string) Str::uuid();
        $refund = $service->request($order->fresh(), $input, $key, null);
        $this->assertNull($refund->created_by);
        $this->assertSame('submitted', $refund->status);
        $this->assertSame($refund->id, $service->request($order->fresh(), $input, $key, null)->id);
        $this->assertDatabaseHas('payment_audit_log', [
            'refund_id' => $refund->id, 'action' => 'refund_requested', 'actor_type' => 'system', 'actor_id' => null,
        ]);
        $this->assertSame(0, (int) $order->fresh()->amount_refunded);
        $this->assertSame('granted', $order->fresh()->access_status);
        Http::assertSentCount(1);
    }

    public function test_cloudpayments_unknown_refund_is_not_resent_after_provider_idempotency_window(): void
    {
        config([
            'services.payments.providers.cloudpayments.webhook_secret' => 'cp-secret',
            'services.payments.providers.cloudpayments.public_id' => 'pk_test',
            'services.payments.providers.cloudpayments.webhook_allowed_ips' => ['127.0.0.1'],
        ]);
        [$order] = $this->createPendingOrderWithAttempt(amount: 100000, provider: 'cloudpayments');
        $this->postSignedCloudpaymentsWebhook('pay', ['InvoiceId' => $order->id, 'Amount' => '1000.00'])->assertJson(['code' => 0]);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin_full']));
        Http::fake(['api.cloudpayments.ru/payments/refund' => Http::response('timeout', 504)]);
        $key = (string) Str::uuid();
        $request = ['refund_type' => 'full', 'reason' => 'Test', 'policy_access_action' => 'revoke'];
        $url = "/api/v1/payments/orders/{$order->id}/refund";
        $this->withHeader('Idempotency-Key', $key)->postJson($url, $request)->assertAccepted()->assertJsonPath('status', 'unknown');
        $this->travel(2)->hours();
        $this->withHeader('Idempotency-Key', $key)->postJson($url, $request)->assertAccepted()->assertJsonPath('status', 'unknown');
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson($url, $request)->assertConflict();
        Http::assertSentCount(1);
        $this->assertSame(0, (int) $order->fresh()->amount_refunded);
        $this->assertSame('granted', $order->fresh()->access_status);
    }

    public function test_cloudpayments_refund_callback_can_arrive_before_api_response(): void
    {
        config([
            'services.payments.providers.cloudpayments.webhook_secret' => 'cp-secret',
            'services.payments.providers.cloudpayments.public_id' => 'pk_test',
            'services.payments.providers.cloudpayments.webhook_allowed_ips' => ['127.0.0.1'],
        ]);
        [$order] = $this->createPendingOrderWithAttempt(amount: 100000, provider: 'cloudpayments');
        $this->postSignedCloudpaymentsWebhook('pay', ['InvoiceId' => $order->id, 'Amount' => '1000.00'])->assertJson(['code' => 0]);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin_full']));
        $key = (string) Str::uuid();
        Http::fake(function () use ($order, $key) {
            $this->postSignedCloudpaymentsWebhook('refund', [
                'InvoiceId' => $order->id, 'Amount' => '1000.00', 'TransactionId' => 800,
                'Data' => json_encode(['cms_refund_request_key' => $key]),
            ])->assertJson(['code' => 0]);

            return Http::response(['Success' => true, 'Model' => ['TransactionId' => 800]]);
        });
        $this->withHeader('Idempotency-Key', $key)->postJson("/api/v1/payments/orders/{$order->id}/refund", [
            'refund_type' => 'full', 'reason' => 'Test', 'policy_access_action' => 'revoke',
        ])->assertAccepted()->assertJsonPath('status', 'completed');
        $this->assertSame(1, Refund::query()->where('order_id', $order->id)->count());
        $this->assertSame(100000, (int) $order->fresh()->amount_refunded);
    }

    public function test_cloudpayments_declined_sbp_refund_does_not_change_money_or_access(): void
    {
        config([
            'services.payments.providers.cloudpayments.webhook_secret' => 'cp-secret',
            'services.payments.providers.cloudpayments.webhook_allowed_ips' => ['127.0.0.1'],
        ]);
        [$order] = $this->createPendingOrderWithAttempt(amount: 100000, provider: 'cloudpayments');
        $this->postSignedCloudpaymentsWebhook('pay', ['InvoiceId' => $order->id, 'Amount' => '1000.00'])->assertJson(['code' => 0]);
        $this->postSignedCloudpaymentsWebhook('refund', [
            'InvoiceId' => $order->id, 'Amount' => '1000.00', 'TransactionId' => 800,
            'PaymentMethod' => 'Sbp', 'Status' => 'Declined',
        ])->assertJson(['code' => 13]);
        $this->assertSame(0, (int) $order->fresh()->amount_refunded);
        $this->assertSame('granted', $order->fresh()->access_status);
        $this->assertDatabaseCount('refunds', 0);
    }

    public function test_cloudpayments_reconciliation_recovers_lost_pay_and_deduplicates_later_webhook(): void
    {
        config([
            'services.payments.providers.cloudpayments.webhook_secret' => 'cp-secret',
            'services.payments.providers.cloudpayments.public_id' => 'pk_test',
            'services.payments.providers.cloudpayments.test_mode' => false,
            'services.payments.providers.cloudpayments.webhook_allowed_ips' => ['127.0.0.1'],
        ]);
        [$order, $attempt] = $this->createPendingOrderWithAttempt(amount: 100000, provider: 'cloudpayments');
        $attempt->update(['provider_payment_id' => '77']);
        Http::fake(['api.cloudpayments.ru/payments/get' => Http::response(['Success' => true, 'Model' => [
            'PublicId' => 'pk_test', 'TransactionId' => 77, 'OriginalTransactionId' => null,
            'Type' => 0, 'InvoiceId' => (string) $order->id, 'Amount' => 1000,
            'Currency' => 'RUB', 'TestMode' => false, 'Status' => 'Completed', 'Token' => 'must-not-escape',
        ]])]);
        $service = app(CloudpaymentsReconciliationService::class);
        $this->assertSame('verified_provider_result', $service->payment($attempt)['result']);
        $this->assertSame('pending_payment', $order->fresh()->payment_status);
        $this->assertSame('applied', $service->payment($attempt, true)['result']);
        $this->postSignedCloudpaymentsWebhook('pay', ['InvoiceId' => $order->id, 'Amount' => '1000.00'])->assertJson(['code' => 0]);
        $this->assertSame(1, OrderSettlement::query()->where('order_id', $order->id)->count());
        $this->assertStringNotContainsString('must-not-escape', json_encode(ProviderEvent::query()->get()));
    }

    public function test_cloudpayments_reconciliation_refuses_different_merchant_and_amount(): void
    {
        config([
            'services.payments.providers.cloudpayments.webhook_secret' => 'cp-secret',
            'services.payments.providers.cloudpayments.public_id' => 'pk_test',
            'services.payments.providers.cloudpayments.test_mode' => false,
        ]);
        [$order, $attempt] = $this->createPendingOrderWithAttempt(amount: 100000, provider: 'cloudpayments');
        $attempt->update(['provider_payment_id' => '77']);
        $model = ['TransactionId' => 77, 'OriginalTransactionId' => null, 'Type' => 0,
            'InvoiceId' => (string) $order->id, 'Amount' => 999, 'Currency' => 'RUB', 'TestMode' => false, 'Status' => 'Completed'];
        Http::fake(['api.cloudpayments.ru/payments/get' => Http::sequence()
            ->push(['Success' => true, 'Model' => [...$model, 'PublicId' => 'other']])
            ->push(['Success' => true, 'Model' => [...$model, 'PublicId' => 'pk_test']])]);
        try {
            app(CloudpaymentsReconciliationService::class)->payment($attempt, true);
            $this->fail('Wrong merchant accepted');
        } catch (DomainException $exception) {
            $this->assertSame('PAYMENT_RECONCILIATION_IDENTITY_INVALID', $exception->getMessage());
        }
        $this->assertSame('rejected', app(CloudpaymentsReconciliationService::class)->payment($attempt, true)['result']);
        $this->assertSame('pending_payment', $order->fresh()->payment_status);
        $this->assertDatabaseCount('order_settlements', 0);
    }

    public function test_cloudpayments_refund_reconciliation_only_applies_bound_confirmed_transaction(): void
    {
        config([
            'services.payments.providers.cloudpayments.webhook_secret' => 'cp-secret',
            'services.payments.providers.cloudpayments.public_id' => 'pk_test',
            'services.payments.providers.cloudpayments.test_mode' => false,
            'services.payments.providers.cloudpayments.webhook_allowed_ips' => ['127.0.0.1'],
        ]);
        [$order, $attempt] = $this->createPendingOrderWithAttempt(amount: 100000, provider: 'cloudpayments');
        $this->postSignedCloudpaymentsWebhook('pay', ['InvoiceId' => $order->id, 'Amount' => '1000.00'])->assertJson(['code' => 0]);
        $refund = Refund::query()->create([
            'order_id' => $order->id, 'attempt_id' => $attempt->id, 'amount' => 100000, 'currency' => 'RUB',
            'refund_type' => 'full', 'status' => 'submitted', 'policy_access_action' => 'revoke', 'provider_refund_id' => '800',
        ]);
        PaymentAuditLog::query()->create([
            'order_id' => $order->id, 'refund_id' => $refund->id, 'action' => 'refund_provider_submitted',
            'actor_type' => 'provider', 'meta' => ['transaction_id' => '800'],
        ]);
        Http::fake(['api.cloudpayments.ru/payments/get' => Http::response(['Success' => true, 'Model' => [
            'PublicId' => 'pk_test', 'TransactionId' => 800, 'OriginalTransactionId' => 77,
            'InvoiceId' => (string) $order->id, 'Amount' => '1000.00',
            'Currency' => 'RUB', 'TestMode' => false, 'Status' => 'Completed',
        ]])]);
        $service = app(CloudpaymentsReconciliationService::class);
        $this->assertSame('verified_provider_result', $service->refund($refund)['result']);
        $this->assertSame(0, (int) $order->fresh()->amount_refunded);
        $this->assertSame('applied', $service->refund($refund, true)['result']);
        $this->assertSame('applied', $service->refund($refund, true)['result']);
        $this->assertSame('refunded', $order->fresh()->payment_status);
        $this->assertSame(100000, (int) $order->fresh()->amount_refunded);
        $this->assertSame(1, OutboxEvent::query()->where('refund_id', $refund->id)->count());
    }

    public function test_cloudpayments_refund_rejects_partial_missing_config_and_cross_order_key_without_io(): void
    {
        config([
            'services.payments.providers.cloudpayments.webhook_secret' => 'cp-secret',
            'services.payments.providers.cloudpayments.public_id' => 'pk_test',
            'services.payments.providers.cloudpayments.webhook_allowed_ips' => ['127.0.0.1'],
        ]);
        [$order, $attempt] = $this->createPendingOrderWithAttempt(amount: 100000, provider: 'cloudpayments');
        $this->postSignedCloudpaymentsWebhook('pay', ['InvoiceId' => $order->id, 'Amount' => '1000.00'])->assertJson(['code' => 0]);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin_full']));
        Http::preventStrayRequests();
        Http::fake();
        $key = (string) Str::uuid();
        $request = ['refund_type' => 'partial', 'amount' => 1000, 'reason' => 'Test', 'policy_access_action' => 'revoke'];
        $url = "/api/v1/payments/orders/{$order->id}/refund";
        $this->withHeader('Idempotency-Key', $key)->postJson($url, $request)->assertConflict()
            ->assertJsonPath('error.code', 'PAYMENT_REFUND_WHOLE_SERVICE_REQUIRED');
        config(['services.payments.providers.cloudpayments.public_id' => '']);
        $request['refund_type'] = 'full';
        $this->postJson($url, $request)->assertConflict()->assertJsonPath('error.code', 'PAYMENT_CLOUDPAYMENTS_CREDENTIALS_MISSING');
        $this->assertDatabaseCount('refunds', 0);
        config(['services.payments.providers.cloudpayments.public_id' => 'pk_test']);
        [$other] = $this->createPendingOrderWithAttempt();
        Refund::query()->create([
            'order_id' => $other->id, 'amount' => 1000, 'currency' => 'RUB', 'request_key' => $key,
        ]);
        $this->postJson($url, $request)->assertConflict()->assertJsonPath('error.code', 'PAYMENT_REFUND_IDEMPOTENCY_CONFLICT');
        Http::assertNothingSent();
    }

    public function test_cloudpayments_attempt_reuse_expiry_binding_and_pending_bank_guards(): void
    {
        config([
            'services.payments.providers.cloudpayments.webhook_secret' => 'cp-secret',
            'services.payments.providers.cloudpayments.public_id' => 'pk_test',
            'services.payments.providers.cloudpayments.webhook_allowed_ips' => ['127.0.0.1'],
        ]);
        [$order] = $this->createPendingOrderWithAttempt(amount: 100000, provider: 'cloudpayments');
        Sanctum::actingAs($order->user()->firstOrFail());
        $request = ['provider' => 'cloudpayments', 'return_url' => 'https://example.com/return', 'cancel_url' => 'https://example.com/cancel'];
        $path = "/api/v1/payments/orders/{$order->id}/attempts";
        $first = $this->postJson($path, $request)->assertCreated()->json();
        $second = $this->postJson($path, $request)->assertCreated()->json();
        $this->assertSame($first['attempt_id'], $second['attempt_id']);
        $invoice = $first['provider_payload']['invoice_id'];
        $payload = ['InvoiceId' => $invoice, 'Amount' => '1000.00'];
        $this->postSignedCloudpaymentsWebhook('check', [...$payload, 'InvoiceId' => $order->id])->assertJson(['code' => 13]);
        $attempt = $order->fresh()->activeAttempt()->firstOrFail();
        $attempt->update(['expires_at' => now()->subMinute()]);
        $this->postSignedCloudpaymentsWebhook('check', $payload)->assertJson(['code' => 20]);
        $third = $this->postJson($path, $request)->assertCreated()->json();
        $this->assertNotSame($first['attempt_id'], $third['attempt_id']);
        $this->postSignedCloudpaymentsWebhook('pay', $payload)->assertJson(['code' => 13]);
        $payload['InvoiceId'] = $third['provider_payload']['invoice_id'];
        $this->postSignedCloudpaymentsWebhook('check', $payload)->assertJson(['code' => 0]);
        $order->fresh()->activeAttempt()->update(['expires_at' => now()->subMinute()]);
        $this->postJson($path, $request)->assertStatus(409)->assertJsonPath('error.code', 'PAYMENT_CONFIRMATION_PENDING');
        Sanctum::actingAs(User::factory()->create(['role' => 'admin_full']));
        $this->postJson("/api/v1/payments/orders/{$order->id}/cancel", ['reason' => 'test'])->assertStatus(409);
        $this->postSignedCloudpaymentsWebhook('pay', $payload)->assertJson(['code' => 0]);
        $this->assertSame('paid', $order->fresh()->payment_status);
    }

    public function test_cloudpayments_fail_duplicate_and_late_pay_do_not_grant_access(): void
    {
        config([
            'services.payments.providers.cloudpayments.webhook_secret' => 'cp-secret',
            'services.payments.providers.cloudpayments.webhook_allowed_ips' => ['127.0.0.1'],
        ]);
        [$order] = $this->createPendingOrderWithAttempt(amount: 100000, provider: 'cloudpayments');
        $payload = ['InvoiceId' => $order->id, 'Amount' => '1000.00', 'ReasonCode' => 5001];
        $this->postSignedCloudpaymentsWebhook('fail', $payload)->assertJson(['code' => 0]);
        $this->postSignedCloudpaymentsWebhook('fail', $payload)->assertJson(['code' => 0]);
        $this->postSignedCloudpaymentsWebhook('pay', $payload)->assertJson(['code' => 13]);
        $this->assertSame('cancelled', $order->fresh()->payment_status);
        $this->assertDatabaseCount('user_entitlement_grants', 0);
        $this->assertSame(1, ProviderEvent::query()->where('provider', 'cloudpayments')->count());
    }

    public function test_cloudpayments_unsigned_query_and_generic_endpoint_cannot_settle(): void
    {
        config([
            'services.payments.providers.cloudpayments.webhook_secret' => 'cp-secret',
            'services.payments.providers.cloudpayments.webhook_allowed_ips' => ['127.0.0.1'],
        ]);
        [$order] = $this->createPendingOrderWithAttempt(amount: 100000, provider: 'cloudpayments');
        $payload = ['Amount' => '1000.00', 'Currency' => 'RUB', 'TransactionId' => 77, 'TestMode' => 0, 'Status' => 'Completed', 'OperationType' => 'Payment'];
        $signature = base64_encode(hash_hmac('sha256', json_encode($payload, JSON_THROW_ON_ERROR), 'cp-secret', true));
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])->withHeaders(['Content-HMAC' => $signature])
            ->postJson("/api/v1/payments/webhooks/cloudpayments/pay?InvoiceId={$order->id}", $payload)
            ->assertJson(['code' => 10]);
        $this->postJson('/api/v1/payments/webhooks/cloudpayments', $payload)->assertStatus(422);
        $this->assertDatabaseCount('order_settlements', 0);
    }

    private function postSignedCloudpaymentsWebhook(string $type, array $payload, string $secret = 'cp-secret'): TestResponse
    {
        $payload = array_merge([
            'Currency' => 'RUB',
            'TransactionId' => 77,
            'TestMode' => 0,
            'Status' => 'Completed',
            'OperationType' => $type === 'refund' ? 'Refund' : 'Payment',
            'PaymentTransactionId' => 77,
        ], $payload);
        $raw = json_encode($payload, JSON_THROW_ON_ERROR);
        $signature = base64_encode(hash_hmac('sha256', $raw, $secret, true));

        return $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->withHeaders(['Content-HMAC' => $signature])
            ->postJson("/api/v1/payments/webhooks/cloudpayments/{$type}", $payload);
    }

    private function configureTestProviderWebhook(array $allowedIps = ['127.0.0.1']): void
    {
        config([
            'services.payments.providers.test_provider.webhook_secret' => 'top-secret',
            'services.payments.providers.test_provider.webhook_allowed_ips' => $allowedIps,
        ]);
    }

    private function postSignedTestProviderWebhook(
        array $payload,
        string $provider = 'test_provider',
        string $secret = 'top-secret'
    ): TestResponse {
        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        $signature = hash_hmac('sha256', $json, $secret);

        return $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->withHeaders(['X-Payment-Signature' => $signature])
            ->postJson("/api/v1/payments/webhooks/{$provider}", $payload);
    }

    public function test_manual_cancel_rejects_settlement_committed_after_route_binding(): void
    {
        [$order, $attempt] = $this->createPendingOrderWithAttempt();
        Sanctum::actingAs(User::factory()->create(['role' => 'admin_full']));

        // Deterministic stale-binding interleaving; PostgreSQL contention is a separate check.
        Route::bind('order', function (string $id) use ($attempt): Order {
            $stale = Order::query()->findOrFail($id);
            $this->settleOrder($stale, 150000, 'cancel-after-binding', $attempt->id);

            return $stale;
        });

        $this->postJson('/api/v1/payments/orders/'.$order->id.'/cancel')
            ->assertConflict()->assertJsonPath('error.code', 'PAYMENT_INVALID_TRANSITION');

        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertSame('granted', $order->fresh()->access_status);
        $this->assertSame(150000, $order->fresh()->amount_paid);
        $this->assertSame(1, OrderSettlement::query()->where('order_id', $order->id)->where('status', 'confirmed')->count());
        $this->assertGreaterThan(0, UserEntitlementGrant::query()->where('order_id', $order->id)->effectiveActive()->count());
        $this->assertDatabaseMissing('payment_audit_log', ['order_id' => $order->id, 'action' => 'manual_cancel']);
        $this->assertSame(0, OutboxEvent::query()->where('order_id', $order->id)->where('payload->to_status', 'cancelled')->count());
    }

    public function test_manual_cancel_records_pending_transition_once_and_blocks_later_settlement(): void
    {
        [$order, $attempt] = $this->createPendingOrderWithAttempt();
        $staff = User::factory()->create(['role' => 'admin_full']);
        Sanctum::actingAs($staff);

        $this->postJson('/api/v1/payments/orders/'.$order->id.'/cancel', ['reason' => 'Customer requested cancellation'])
            ->assertOk()->assertJsonPath('payment_status', 'cancelled');
        $this->assertDatabaseHas('payment_audit_log', [
            'order_id' => $order->id, 'action' => 'manual_cancel', 'from_status' => 'pending_payment',
            'to_status' => 'cancelled', 'actor_id' => $staff->id, 'reason' => 'Customer requested cancellation',
        ]);
        $this->postJson('/api/v1/payments/orders/'.$order->id.'/cancel')->assertConflict();
        $this->assertSame(1, PaymentAuditLog::query()->where('order_id', $order->id)->where('action', 'manual_cancel')->count());
        $this->assertSame(1, OutboxEvent::query()->where('order_id', $order->id)->where('payload->to_status', 'cancelled')->count());

        try {
            $this->settleOrder($order, 150000, 'settlement-after-cancel', $attempt->id);
            $this->fail('A cancelled order must not settle.');
        } catch (DomainException) {
            $this->assertSame('cancelled', $order->fresh()->payment_status);
            $this->assertSame('not_granted', $order->fresh()->access_status);
            $this->assertDatabaseMissing('order_settlements', ['order_id' => $order->id]);
            $this->assertDatabaseMissing('user_entitlement_grants', ['order_id' => $order->id]);
        }
    }

    public function test_manual_cancel_preserves_draft_transition_and_actual_audit_source(): void
    {
        [$order] = $this->createPendingOrderWithAttempt();
        $order->update(['payment_status' => 'draft']);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin_full']));

        $this->postJson('/api/v1/payments/orders/'.$order->id.'/cancel')
            ->assertOk()->assertJsonPath('payment_status', 'cancelled');
        $this->assertDatabaseHas('payment_audit_log', [
            'order_id' => $order->id, 'action' => 'manual_cancel', 'from_status' => 'draft', 'to_status' => 'cancelled',
        ]);
        $this->assertSame('draft', OutboxEvent::query()->where('order_id', $order->id)->firstOrFail()->payload['from_status']);
    }

    public function test_manual_cancel_rejects_confirmed_settlement_even_if_order_status_is_inconsistent(): void
    {
        [$order, $attempt] = $this->createPendingOrderWithAttempt();
        $this->settleOrder($order, 150000, 'inconsistent-order-settlement', $attempt->id);
        $order->refresh()->update(['payment_status' => 'pending_payment']);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin_full']));

        $this->postJson('/api/v1/payments/orders/'.$order->id.'/cancel')->assertConflict();
        $this->assertSame('pending_payment', $order->fresh()->payment_status);
        $this->assertSame('granted', $order->fresh()->access_status);
        $this->assertDatabaseMissing('payment_audit_log', ['order_id' => $order->id, 'action' => 'manual_cancel']);
        $this->assertSame(0, OutboxEvent::query()->where('order_id', $order->id)->where('payload->to_status', 'cancelled')->count());
    }

    public function test_manual_cancel_rolls_back_transition_and_outbox_when_audit_write_fails(): void
    {
        [$order] = $this->createPendingOrderWithAttempt();
        Sanctum::actingAs(User::factory()->create(['role' => 'admin_full']));
        PaymentAuditLog::creating(static function (PaymentAuditLog $log): void {
            if ($log->action === 'manual_cancel') {
                throw new DomainException('Audit write rejected by test fixture.');
            }
        });

        $this->postJson('/api/v1/payments/orders/'.$order->id.'/cancel')->assertConflict();
        $this->assertSame('pending_payment', $order->fresh()->payment_status);
        $this->assertDatabaseMissing('payment_audit_log', ['order_id' => $order->id, 'action' => 'manual_cancel']);
        $this->assertDatabaseMissing('outbox_events', ['order_id' => $order->id]);
    }

    /**
     * @return array{0: Order, 1: PaymentAttempt}
     */
    private function createPendingOrderWithAttempt(
        int $amount = 150000,
        string $currency = 'RUB',
        string $provider = 'test_provider'
    ): array {
        $user = User::factory()->create([
            'role' => 'user_no_purchase',
            'phone_verified_at' => now(),
        ]);
        $course = $this->createCheckoutCourse(priceCents: $amount);
        $order = Order::query()->create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'payment_status' => 'pending_payment',
            'access_status' => 'not_granted',
            'currency' => $currency,
            'amount_total' => $amount,
            'amount_final' => $amount,
            'amount_paid' => 0,
            'amount_refunded' => 0,
        ]);
        app(OrderItemSnapshotService::class)->createCourseItem($order, $course);

        $attempt = PaymentAttempt::query()->create([
            'order_id' => $order->id,
            'provider' => $provider,
            'provider_payment_id' => $provider === 'cloudpayments' ? null : "{$provider}-payment-{$order->id}",
            'provider_payment_url' => "https://example.com/pay/{$order->id}",
            'status' => 'pending',
            'amount' => $amount,
            'currency' => $currency,
            'provider_substatus' => ['source' => 'test'],
        ]);

        $order->active_attempt_id = $attempt->id;
        $order->save();

        return [$order->fresh(), $attempt->fresh()];
    }

    private function createCheckoutCourse(int $priceCents, bool $isActive = true): Course
    {
        $subject = Subject::query()->create([
            'slug' => 'subject-'.Str::uuid(),
            'title' => 'Анатомия',
            'sort_order' => 1,
            'is_active' => true,
        ]);

        $course = Course::query()->create([
            'subject_id' => $subject->id,
            'slug' => 'course-'.Str::uuid(),
            'title' => 'Остеология',
            'short_title' => 'Остеология',
            'price_cents' => $priceCents,
            'sort_order' => 1,
            'is_active' => $isActive,
        ]);

        Lesson::query()->create([
            'course_id' => $course->id,
            'slug' => 'lesson-'.Str::uuid(),
            'title' => 'Введение',
            'sort_order' => 1,
            'is_active' => true,
        ]);

        $course->refresh();

        return $course;
    }

    private function settleOrder(Order $order, int $amount, string $idempotencyKey, ?int $paymentAttemptId = null): void
    {
        app(OrderSettlementService::class)->confirmPaid($order->fresh(), [
            'idempotency_key' => $idempotencyKey,
            'amount' => $amount,
            'currency' => 'RUB',
            'settlement_type' => OrderSettlement::TYPE_BACKEND_CONFIRMATION,
            'payment_attempt_id' => $paymentAttemptId,
        ]);
    }

    private function lessonUrl(Course $course, Lesson $lesson): string
    {
        $subject = $course->subject()->firstOrFail();

        return "/api/v1/learning/subjects/{$subject->slug}/courses/{$course->slug}/lessons/{$lesson->slug}";
    }

    /**
     * @return array{0: Course, 1: SellableEntity}
     */
    private function createSellableCheckoutCourse(
        int $priceCents,
        string $status = SellableEntity::STATUS_ACTIVE,
        string $currency = 'RUB'
    ): array {
        $course = $this->createCheckoutCourse(priceCents: $priceCents);
        $lesson = $course->lessons()->firstOrFail();
        $sellable = SellableEntity::query()->create([
            'sellable_kind' => OrderItem::KIND_COURSE,
            'entity_type' => OrderItem::KIND_COURSE,
            'entity_id' => $course->id,
            'sku' => $course->itemId(),
            'title' => $course->title,
            'currency' => $currency,
            'price_cents' => $priceCents,
            'price_version' => 1,
            'status' => $status,
            'metadata' => ['slug' => $course->slug],
        ]);
        SellableGrantRule::query()->create([
            'sellable_entity_id' => $sellable->id,
            'atom_kind' => UserEntitlementGrant::ATOM_LESSON,
            'atom_id' => $lesson->id,
            'grant_snapshot_version' => 1,
            'sort_order' => 1,
        ]);

        return [$course, $sellable];
    }
}
