<?php

namespace Tests\Feature;

use App\Jobs\ProcessOutboxEventJob;
use App\Jobs\ProcessPaymentSideEffectJob;
use App\Models\CoinLedgerEntry;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\Order;
use App\Models\OutboxEvent;
use App\Models\PromoCode;
use App\Models\SideEffectExecution;
use App\Models\Subject;
use App\Models\User;
use App\Services\Bonuses\AutoPurchasePromoService;
use App\Services\Bonuses\BonusPurchaseEffectService;
use App\Services\Bonuses\PromoReservationService;
use App\Services\OrderItemSnapshotService;
use App\Services\OrderSettlementService;
use App\Services\Payments\PaymentOutboxService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PaymentCoinsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_zero_cash_settlement_requires_verified_phone(): void
    {
        [$user, $order] = $this->fixture();
        $user->forceFill(['phone' => null, 'phone_verified_at' => null])->save();
        Sanctum::actingAs($user->fresh());

        $this->withHeader('Idempotency-Key', 'unverified-zero-cash')
            ->postJson("/api/v1/payments/orders/{$order->id}/discount-settlement")
            ->assertForbidden()
            ->assertJsonPath('error.code', 'VERIFY_PHONE_REQUIRED');

        $this->assertDatabaseCount('order_settlements', 0);
        $this->assertSame('pending_payment', $order->fresh()->payment_status);
    }

    public function test_full_promo_requires_owner_and_settles_without_coins_or_provider(): void
    {
        [$user, $order] = $this->fixture();
        PromoCode::query()->create([
            'code_hash' => PromoReservationService::codeHash('FREE-COURSE'),
            'status' => 'active', 'discount_kind' => 'percent_bps', 'discount_bps' => 10000,
            'currency' => 'RUB', 'starts_at' => now()->subMinute(), 'expires_at' => now()->addDay(),
            'activation_limit' => 2, 'per_user_limit' => 1, 'rules_version' => 'test-v1',
        ]);
        Sanctum::actingAs(User::factory()->create(['role' => 'user_no_purchase']));
        $this->withHeader('Idempotency-Key', 'promo-full')->postJson("/api/v1/payments/orders/{$order->id}/promo", ['code' => 'FREE-COURSE'])->assertNotFound();
        Sanctum::actingAs($user);
        $this->postJson("/api/v1/payments/orders/{$order->id}/promo", ['code' => 'FREE-COURSE', 'amount_final' => 999])
            ->assertOk()->assertJsonPath('discount_type', 'promo')->assertJsonPath('payable_total', 0)->assertJsonPath('coins_units', 0);
        for ($i = 0; $i < 2; $i++) {
            $this->withHeader('Idempotency-Key', 'promo-zero-final')->postJson("/api/v1/payments/orders/{$order->id}/discount-settlement")
                ->assertOk()->assertJsonPath('payment_status', 'paid')->assertJsonPath('access_status', 'granted');
        }
        $this->assertDatabaseCount('payment_attempts', 0);
        $this->assertDatabaseCount('order_settlements', 1);
        $this->assertDatabaseCount('coin_reservations', 0);
        $this->assertDatabaseHas('promo_reservations', ['order_id' => $order->id, 'status' => 'committed']);
        $this->assertSame(0, (int) $order->fresh()->amount_paid);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin_full']));
        $this->withHeader('Idempotency-Key', 'promo-zero-refund')->postJson("/api/v1/payments/orders/{$order->id}/refund", [
            'refund_type' => 'full', 'policy_access_action' => 'revoke', 'reason' => 'Test promo refund',
        ])->assertOk()->assertJsonPath('payment_status', 'refunded');
        $refundEvent = OutboxEvent::query()->where('order_id', $order->id)->whereNotNull('refund_id')->firstOrFail();
        (new ProcessOutboxEventJob($refundEvent->id))->handle();
        $refundEffect = SideEffectExecution::query()->where('order_id', $order->id)->where('handler', 'bonus.purchase_refund')->firstOrFail();
        app(BonusPurchaseEffectService::class)->execute($refundEffect->id);
        $this->assertSame('completed', $refundEffect->fresh()->status);
        $this->assertDatabaseHas('promo_reservations', ['order_id' => $order->id, 'status' => 'committed']);
        Http::assertNothingSent();
    }

    public function test_issued_promo_materializes_one_durable_email_without_plaintext_code(): void
    {
        [, $order] = $this->fixture(100000);
        app(OrderSettlementService::class)->confirmPaid($order, ['idempotency_key' => 'promo-email', 'amount' => 100000, 'currency' => 'RUB']);
        $event = app(PaymentOutboxService::class)->publishTransition($order->fresh(), null, null, 'pending_payment', 'paid');
        (new ProcessOutboxEventJob($event->id))->handle();
        $effect = SideEffectExecution::query()->where('order_id', $order->id)->where('handler', 'bonus.purchase_reward')->firstOrFail();
        app(BonusPurchaseEffectService::class)->execute($effect->id);
        app(BonusPurchaseEffectService::class)->execute($effect->id);
        $notifications = SideEffectExecution::query()->where('order_id', $order->id)->where('handler', 'user_notification.purchase_promo')->get();
        $this->assertCount(1, $notifications);
        $this->assertSame('pending', $notifications->sole()->status);
        $this->assertSame(['delivery_version', 'promo_code_id'], array_keys($notifications->sole()->meta));
        Queue::assertPushed(ProcessPaymentSideEffectJob::class, fn ($job) => $job->executionId === $notifications->sole()->id);
    }

    public function test_bonus_child_failure_rolls_back_accrual_before_review(): void
    {
        [, $order] = $this->fixture();
        app(OrderSettlementService::class)->confirmPaid($order, ['idempotency_key' => 'atomic-reward', 'amount' => 10000, 'currency' => 'RUB']);
        $event = app(PaymentOutboxService::class)->publishTransition($order->fresh(), null, null, 'pending_payment', 'paid');
        (new ProcessOutboxEventJob($event->id))->handle();
        $effect = SideEffectExecution::query()->where('order_id', $order->id)->where('handler', 'bonus.purchase_reward')->firstOrFail();
        $this->mock(AutoPurchasePromoService::class)
            ->shouldReceive('apply')->once()->andThrow(new \DomainException('BONUS_PROMO_EVIDENCE_CONFLICT'));
        app(BonusPurchaseEffectService::class)->execute($effect->id);
        $this->assertSame('manual_review', $effect->fresh()->status);
        $this->assertSame(0, CoinLedgerEntry::query()->where('order_id', $order->id)->count());
        $this->assertDatabaseMissing('payment_audit_log', ['order_id' => $order->id, 'action' => 'purchase_reward_applied']);
    }

    public function test_zero_cash_refund_requires_staff_and_runs_without_provider(): void
    {
        [$user, $order] = $this->fixture();
        Sanctum::actingAs($user);
        $this->withHeader('Idempotency-Key', 'coins-full-refund')
            ->postJson("/api/v1/payments/orders/{$order->id}/coins", ['coins_units' => 100])->assertOk();
        $this->withHeader('Idempotency-Key', 'coins-full-settle')
            ->postJson("/api/v1/payments/orders/{$order->id}/coin-settlement")->assertOk();
        $input = ['refund_type' => 'full', 'policy_access_action' => 'revoke', 'reason' => 'Test zero refund'];
        $this->withHeader('Idempotency-Key', 'refund-zero')->postJson("/api/v1/payments/orders/{$order->id}/refund", $input)->assertForbidden();
        Sanctum::actingAs(User::factory()->create(['role' => 'admin_full']));
        config(['bonuses.checkout_enabled' => false]);
        for ($i = 0; $i < 2; $i++) {
            $this->withHeader('Idempotency-Key', 'refund-zero')->postJson("/api/v1/payments/orders/{$order->id}/refund", $input)
                ->assertOk()->assertJsonPath('payment_status', 'refunded')->assertJsonPath('access_status', 'revoked');
        }
        $this->assertDatabaseCount('refunds', 1);
        $event = OutboxEvent::query()->where('order_id', $order->id)->whereNotNull('refund_id')->firstOrFail();
        (new ProcessOutboxEventJob($event->id))->handle();
        $effect = SideEffectExecution::query()->where('order_id', $order->id)->where('handler', 'bonus.purchase_refund')->firstOrFail();
        app(BonusPurchaseEffectService::class)->execute($effect->id);
        $this->assertSame('completed', $effect->fresh()->status);
        $this->assertDatabaseHas('coin_ledger_entries', ['order_id' => $order->id, 'event_type' => 'coins_refund', 'units' => 100]);
        $effect->refresh()->update(['status' => 'pending']);
        $this->mock(AutoPurchasePromoService::class)->shouldReceive('revokeForRefund')
            ->once()->andReturn(['status' => 'promotion_violation_review']);
        app(BonusPurchaseEffectService::class)->execute($effect->id);
        $this->assertSame('manual_review', $effect->fresh()->status);
        $this->assertSame('BONUS_USED_PROMO_REFUND_REVIEW', $effect->fresh()->meta['diagnostic']);
        $this->assertSame(1, CoinLedgerEntry::query()->where('order_id', $order->id)->where('event_type', 'coins_refund')->count());
        Http::assertNothingSent();
    }

    public function test_bonus_outbox_executes_once_and_rejects_unverified_grant(): void
    {
        [, $order] = $this->fixture();
        $settlement = app(OrderSettlementService::class)->confirmPaid($order, [
            'idempotency_key' => 'cash-reward', 'amount' => 10000, 'currency' => 'RUB',
        ]);
        $event = app(PaymentOutboxService::class)->publishTransition($order->fresh(), null, null, 'pending_payment', 'paid');
        (new ProcessOutboxEventJob($event->id))->handle();
        $effect = SideEffectExecution::query()->where('order_id', $order->id)->where('handler', 'bonus.purchase_reward')->firstOrFail();
        $originalMetadata = $settlement->fresh()->metadata;
        $settlement->update(['metadata' => [...$originalMetadata, 'grant_status' => 'blocked']]);
        app(BonusPurchaseEffectService::class)->execute($effect->id);
        $this->assertSame('manual_review', $effect->fresh()->status);
        $this->assertDatabaseMissing('payment_audit_log', ['order_id' => $order->id, 'action' => 'purchase_reward_applied']);
        $settlement->update(['metadata' => $originalMetadata]);
        app(BonusPurchaseEffectService::class)->execute($effect->id);
        app(BonusPurchaseEffectService::class)->execute($effect->id);
        $this->assertSame('completed', $effect->fresh()->status);
        $this->assertDatabaseHas('coin_ledger_entries', ['order_id' => $order->id, 'event_type' => 'coins_accrual', 'units' => 5]);
        $this->assertSame(1, CoinLedgerEntry::query()->where('order_id', $order->id)->where('event_type', 'coins_accrual')->count());
    }

    public function test_only_staff_cancel_releases_reservation_atomically(): void
    {
        [$user, $order] = $this->fixture();
        Sanctum::actingAs($user);
        $this->withHeader('Idempotency-Key', 'reserve-cancel')
            ->postJson("/api/v1/payments/orders/{$order->id}/coins", ['coins_units' => 10])->assertOk();
        $this->postJson("/api/v1/payments/orders/{$order->id}/cancel")->assertForbidden();
        $this->assertDatabaseHas('coin_reservations', ['order_id' => $order->id, 'status' => 'reserved']);
        $staff = User::factory()->create(['role' => 'admin_full']);
        Sanctum::actingAs($staff);
        $this->postJson("/api/v1/payments/orders/{$order->id}/cancel")->assertOk();
        $this->assertDatabaseHas('coin_reservations', ['order_id' => $order->id, 'status' => 'released']);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'payment_status' => 'cancelled']);
    }

    public function test_late_cash_transition_materializes_receipt_notification_and_bonus_effects(): void
    {
        [, $order] = $this->fixture();
        $event = app(PaymentOutboxService::class)->publishTransition($order, null, null, 'cancelled', 'paid');
        (new ProcessOutboxEventJob($event->id))->handle();
        foreach (['receipt.income.issue', 'user_notification.payment_success', 'bonus.purchase_reward'] as $handler) {
            $this->assertDatabaseHas('side_effect_executions', ['order_id' => $order->id, 'handler' => $handler]);
        }
        (new ProcessOutboxEventJob($event->id))->handle();
        $this->assertSame(1, SideEffectExecution::query()->where('order_id', $order->id)->where('handler', 'bonus.purchase_reward')->count());
    }

    public function test_zero_cash_key_is_scoped_to_order_even_across_users(): void
    {
        foreach ([1, 2] as $index) {
            [$user, $order] = $this->fixture();
            Sanctum::actingAs($user);
            $this->withHeader('Idempotency-Key', 'apply-'.$index)
                ->postJson("/api/v1/payments/orders/{$order->id}/coins", ['coins_units' => 100])->assertOk();
            $this->withHeader('Idempotency-Key', 'same-settlement-key')
                ->postJson("/api/v1/payments/orders/{$order->id}/coin-settlement")->assertOk();
        }
        $this->assertDatabaseCount('order_settlements', 2);
        $this->assertDatabaseCount('payment_attempts', 0);
    }

    public function test_disabled_capability_never_claims_a_zero_balance_and_requires_login(): void
    {
        $this->getJson('/api/v1/payments/coins')->assertUnauthorized();
        [$user, $order] = $this->fixture();
        config(['bonuses.checkout_enabled' => false]);
        Sanctum::actingAs($user);
        $this->getJson('/api/v1/payments/coins')->assertOk()->assertJson([
            'enabled' => false, 'coin_minor_units' => 100, 'available_units' => null,
        ])->assertHeader('Cache-Control', 'no-store, private');
        $this->postJson("/api/v1/payments/orders/{$order->id}/coins/quote", ['coins_units' => 1])
            ->assertConflict()->assertJsonPath('error.code', 'BONUS_CHECKOUT_DISABLED');
        $this->assertDatabaseCount('coin_reservations', 0);
    }

    public function test_foreign_order_and_client_amounts_cannot_control_discount(): void
    {
        [$owner, $order] = $this->fixture();
        [$other] = $this->fixture();
        Sanctum::actingAs($other);
        $this->postJson("/api/v1/payments/orders/{$order->id}/coins/quote", ['coins_units' => 10, 'user_id' => $owner->id])->assertNotFound();
        $this->withHeader('Idempotency-Key', 'foreign')->postJson("/api/v1/payments/orders/{$order->id}/coins", ['coins_units' => 10])->assertNotFound();
        Sanctum::actingAs($owner);
        $quote = $this->postJson("/api/v1/payments/orders/{$order->id}/coins/quote", ['coins_units' => 10])
            ->assertOk()->assertJsonPath('payable_total', 9000)->assertJsonMissingPath('purchase_hash')->json();
        $payload = ['coins_units' => 10, 'quote_hash' => $quote['quote_hash'], 'amount_final' => 0, 'user_id' => $other->id, 'proof' => ['verified' => true]];
        $first = $this->withHeader('Idempotency-Key', 'api-priced-order')->postJson("/api/v1/payments/orders/{$order->id}/coins", $payload)
            ->assertOk()->assertJsonPath('payable_total', 9000)->assertJsonPath('discount', 1000)
            ->assertJsonPath('coins_units', 10)->assertJsonMissingPath('proof_hash')->json();
        $this->withHeader('Idempotency-Key', 'api-priced-order')->postJson("/api/v1/payments/orders/{$order->id}/coins", $payload)
            ->assertOk()->assertJsonPath('expires_at', $first['expires_at']);
        $this->withHeader('Idempotency-Key', 'api-priced-order')->postJson("/api/v1/payments/orders/{$order->id}/coins", ['coins_units' => 20])->assertConflict();
        $this->assertDatabaseCount('coin_reservations', 1);
        $this->assertDatabaseCount('order_settlements', 0);
        $this->getJson("/api/v1/payments/orders/{$order->id}")->assertOk()->assertJsonPath('pricing.payable_total', 9000)
            ->assertJsonPath('pricing.coins_units', 10);
    }

    public function test_missing_key_and_invalid_units_are_rejected_before_reserving(): void
    {
        [$user, $order] = $this->fixture();
        Sanctum::actingAs($user);
        $this->postJson("/api/v1/payments/orders/{$order->id}/coins", ['coins_units' => 10])->assertUnprocessable();
        $this->withHeader('Idempotency-Key', 'invalid-units')->postJson("/api/v1/payments/orders/{$order->id}/coins", ['coins_units' => -1])->assertUnprocessable();
        $this->assertDatabaseCount('coin_reservations', 0);
    }

    public function test_full_coin_payment_never_calls_provider_and_replay_keeps_one_settlement(): void
    {
        [$user, $order] = $this->fixture();
        Sanctum::actingAs($user);
        Http::preventStrayRequests();
        Http::fake();
        $this->withHeader('Idempotency-Key', 'all-coins')->postJson("/api/v1/payments/orders/{$order->id}/coins", ['coins_units' => 100])
            ->assertOk()->assertJsonPath('payable_total', 0);
        for ($repeat = 0; $repeat < 2; $repeat++) {
            $this->withHeader('Idempotency-Key', 'settle-coins')->postJson("/api/v1/payments/orders/{$order->id}/coin-settlement")
                ->assertOk()->assertJsonPath('payment_status', 'paid')->assertJsonPath('access_status', 'granted');
        }
        $this->assertDatabaseCount('payment_attempts', 0);
        $this->assertDatabaseCount('order_settlements', 1);
        $this->assertSame(0, (int) $order->fresh()->amount_paid);
        $this->assertDatabaseCount('user_entitlement_grants', 1);
        Http::assertNothingSent();
    }

    private function fixture(int $amount = 10000): array
    {
        config(['bonuses.checkout_enabled' => true]);
        Queue::fake();
        $user = User::factory()->create([
            'role' => 'user_no_purchase',
            'phone_verified_at' => now(),
        ]);
        $subject = Subject::query()->create(['slug' => 'coins-api-'.Str::uuid(), 'title' => 'Subject', 'sort_order' => 1, 'is_active' => true]);
        $course = Course::query()->create(['subject_id' => $subject->id, 'slug' => 'coins-api-'.Str::uuid(),
            'title' => 'Course', 'short_title' => 'Course', 'price_cents' => $amount, 'sort_order' => 1, 'is_active' => true]);
        Lesson::query()->create(['course_id' => $course->id, 'slug' => 'lesson', 'title' => 'Lesson', 'sort_order' => 1, 'is_active' => true]);
        $order = Order::query()->create(['user_id' => $user->id, 'course_id' => $course->id, 'payment_status' => 'pending_payment',
            'access_status' => 'not_granted', 'currency' => 'RUB', 'amount_total' => $amount, 'amount_final' => $amount,
            'amount_discount' => 0, 'amount_paid' => 0, 'amount_refunded' => 0]);
        app(OrderItemSnapshotService::class)->createCourseItem($order, $course);
        CoinLedgerEntry::query()->create(['user_id' => $user->id, 'event_type' => 'coins_accrual', 'units' => 200,
            'idempotency_key' => 'fixture:'.Str::uuid(), 'expires_at' => now()->addDays(1)]);

        return [$user, $order];
    }
}
