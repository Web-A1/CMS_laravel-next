<?php

namespace Tests\Feature\Payments;

use App\Jobs\ProcessOutboxEventJob;
use App\Models\CoinLedgerEntry;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\Order;
use App\Models\OrderSettlement;
use App\Models\OutboxEvent;
use App\Models\PaymentAttempt;
use App\Models\PromoCode;
use App\Models\PromoReservation;
use App\Models\ProviderEvent;
use App\Models\SideEffectExecution;
use App\Models\Subject;
use App\Models\User;
use App\Models\UserEntitlementGrant;
use App\Services\AdminOperationsService;
use App\Services\Bonuses\CoinOrderPricingService;
use App\Services\Bonuses\OrderDiscountService;
use App\Services\Bonuses\PromoOrderPricingService;
use App\Services\Bonuses\PromoReservationService;
use App\Services\OrderItemSnapshotService;
use App\Services\OrderSettlementService;
use App\Services\Payments\EntitlementEffectEvidenceService;
use App\Services\Payments\PaymentAttemptCreator;
use App\Services\Payments\PodeliClient;
use App\Services\Payments\PodeliWebhookHandler;
use App\Services\Payments\ZeroCashRefundService;
use App\Services\PaymentStateMachine;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class PromoCheckoutSettlementTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        $this->travelTo(now()->startOfSecond());
        Queue::fake();
        Http::preventStrayRequests();
        config(['bonuses.checkout_enabled' => true]);
    }

    public function test_fixed_and_percent_apply_preserve_purchase_and_exact_replay(): void
    {
        foreach (['fixed_minor', 'percent_bps'] as $kind) {
            $order = $this->fixture();
            $code = 'PRICE_'.$order->id;
            $this->promo($code, $kind, 1500);
            $before = $order->items()->sole();
            $quote = app(PromoReservationService::class)->quotePriced($order->user_id, $order->id, $code);
            $updated = $this->apply($order, $code, $quote['quote_hash']);
            $this->assertSame(8500, (int) $updated->amount_final);
            $this->assertSame($before->grant_snapshot, $updated->items()->sole()->grant_snapshot);
            $this->assertSame($updated->toArray(), $this->apply($order, $code, $quote['quote_hash'])->toArray());
            $binding = app(OrderDiscountService::class)->binding($updated);
            $this->assertSame('promo', $binding['kind']);
            $this->assertTrue($binding['proof']['monetary_pricing_verified']);
            $this->assertStringNotContainsString($code, json_encode($updated->items()->sole()->benefit_snapshot));
        }
        $this->assertDatabaseCount('promo_reservations', 2);
        $this->assertDatabaseCount('order_settlements', 0);
    }

    public function test_stale_quote_legacy_upgrade_and_foreign_key_are_rejected(): void
    {
        $order = $this->fixture();
        $promo = $this->promo('STALE');
        $quote = app(PromoReservationService::class)->quotePriced($order->user_id, $order->id, 'STALE');
        $promo->update(['discount_minor' => 2000]);
        $this->reject('BONUS_QUOTE_CHANGED', fn () => $this->apply($order, 'STALE', $quote['quote_hash']));
        $legacy = app(PromoReservationService::class)->reserve($order->user_id, $order->id, 'STALE', 'apply:'.$order->id);
        $this->reject('BONUS_IDEMPOTENCY_CONFLICT', fn () => $this->apply($order, 'STALE'));
        $this->assertArrayNotHasKey('proof_hash', $legacy->proof);
        $other = $this->fixture();
        $this->reject('BONUS_IDEMPOTENCY_CONFLICT', fn () => app(PromoReservationService::class)
            ->reservePriced($other->user_id, $other->id, 'STALE', 'apply:'.$order->id));
        $this->assertSame(10000, (int) $order->fresh()->amount_final);
    }

    public function test_priced_reservation_proof_and_identity_are_immutable(): void
    {
        $order = $this->fixture();
        $this->promo('IMMUTABLE');
        $this->apply($order, 'IMMUTABLE');
        foreach (['proof' => [], 'discount_minor' => 0, 'expires_at' => now()->addDays(10), 'user_id' => 999] as $field => $value) {
            $this->reject('BONUS_RESERVATION_INTENT_IMMUTABLE', fn () => PromoReservation::query()->sole()->update([$field => $value]));
        }
        $this->reject('BONUS_RESERVATION_INTENT_IMMUTABLE', fn () => PromoReservation::query()->sole()->delete());
    }

    public function test_coin_and_promo_are_exclusive_in_both_directions(): void
    {
        $promoOrder = $this->fixture();
        $this->promo('XOR');
        $this->apply($promoOrder, 'XOR');
        $this->reject('BONUS_ORDER_ALREADY_IN_PAYMENT', fn () => app(CoinOrderPricingService::class)
            ->apply($promoOrder->user_id, $promoOrder->id, 10, 'coins'));
        $coinOrder = $this->fixture();
        app(CoinOrderPricingService::class)->apply($coinOrder->user_id, $coinOrder->id, 10, 'coins');
        $this->reject('BONUS_ORDER_ALREADY_IN_PAYMENT', fn () => $this->apply($coinOrder, 'XOR'));
    }

    public function test_cash_commits_promo_once_and_disabling_checkout_preserves_bound_safety(): void
    {
        $order = $this->fixture();
        $this->promo('CASH');
        $this->apply($order, 'CASH');
        [$attempt, $event] = $this->attempt($order->fresh());
        config(['bonuses.checkout_enabled' => false]);
        $settlement = $this->confirm($order, $attempt, $event);
        $this->assertSame($settlement->id, $this->confirm($order, $attempt, $event)->id);
        $this->assertSame('promo', $settlement->metadata['discount_settlement']['kind']);
        $this->assertNull($settlement->metadata['coin_settlement']);
        $this->assertSame(8500, (int) $order->fresh()->amount_paid);
        $this->assertSame('granted', $order->fresh()->access_status);
        $this->assertSame('committed', PromoReservation::query()->sole()->status);
        $this->assertDatabaseCount('user_entitlement_grants', 1);
    }

    public function test_deleted_promo_keeps_existing_paid_order_discount_history_and_grants(): void
    {
        $owner = User::factory()->create(['role' => 'polina']);
        $created = app(AdminOperationsService::class)->promos($owner, 'create', [
            'code' => 'DELETE-PAID', 'status' => 'active', 'discount_kind' => 'fixed_minor', 'discount_minor' => 1500,
            'expires_at' => now()->addDay()->toISOString(), 'activation_limit' => 1, 'per_user_limit' => 1,
        ], key: 'delete-paid-create');
        $order = $this->fixture();
        $this->apply($order, 'DELETE-PAID');
        $before = $order->items()->sole()->getRawOriginal();
        app(AdminOperationsService::class)->deleteOwnPromo($owner, $created['data']['id'], key: 'delete-paid');
        DB::transaction(fn () => app(OrderDiscountService::class)->assertCanStartPayment($order->fresh()));
        [$attempt, $event] = $this->attempt($order->fresh());
        $settlement = $this->confirm($order, $attempt, $event);
        $this->assertSame('promo', $settlement->metadata['discount_settlement']['kind']);
        $this->assertSame('granted', $order->fresh()->access_status);
        $this->assertSame(8500, (int) $order->fresh()->amount_paid);
        $this->assertSame('committed', PromoReservation::query()->sole()->status);
        $this->assertSame($before['benefit_snapshot'], $order->items()->sole()->getRawOriginal('benefit_snapshot'));
        $this->assertNotNull(PromoCode::withTrashed()->findOrFail($created['data']['id'])->deleted_at);
        $this->assertDatabaseCount('order_settlements', 1);
        $this->assertDatabaseCount('user_entitlement_grants', 1);
    }

    public function test_invalid_pending_promo_blocks_start_and_late_cash_permanently_blocks_grant(): void
    {
        foreach (['expired', 'revoked', 'terms', 'released'] as $case) {
            $order = $this->fixture();
            $promo = $this->promo('LATE_'.$case);
            $this->apply($order, 'LATE_'.$case);
            [$attempt, $event] = $this->attempt($order->fresh());
            $expected = 'BONUS_RESERVATION_EXPIRED';
            if ($case === 'expired') {
                $this->travel(31)->minutes();
            } elseif ($case === 'revoked') {
                $promo->update(['status' => 'revoked']);
                $expected = 'BONUS_PROMO_INVALID';
            } elseif ($case === 'terms') {
                $promo->update(['discount_minor' => 3000]);
                $expected = 'BONUS_PROMO_TERMS_CHANGED';
            } else {
                DB::transaction(fn () => app(OrderDiscountService::class)->releaseForOrder($order->fresh(), 'cancelled'));
                $order->update(['payment_status' => 'cancelled']);
            }
            $this->reject($expected, fn () => DB::transaction(fn () => app(OrderDiscountService::class)->assertCanStartPayment($order->fresh())));
            $settlement = $this->confirm($order, $attempt, $event);
            $this->assertSame('blocked', $settlement->metadata['grant_status']);
            $this->assertSame(8500, (int) $order->fresh()->amount_paid);
            $this->assertSame('paid', $order->fresh()->payment_status);
            $this->confirm($order, $attempt, $event);
            $this->assertSame('grant_failed', $order->fresh()->access_status);
        }
        $this->assertDatabaseCount('user_entitlement_grants', 0);
    }

    public function test_tampered_and_missing_binding_never_grant_confirmed_cash(): void
    {
        foreach (['missing', 'foreign'] as $case) {
            $order = $this->fixture();
            $this->promo('BAD_'.$case);
            $this->apply($order, 'BAD_'.$case);
            [$attempt, $event] = $this->attempt($order->fresh());
            $item = $order->items()->sole();
            $snapshot = $item->benefit_snapshot;
            if ($case === 'missing') {
                unset($snapshot['promo_reservation']);
            } else {
                $snapshot['promo_reservation']['reservation_id'] = 99999;
            }
            $item->update(['benefit_snapshot' => $snapshot]);
            $settlement = $this->confirm($order, $attempt, $event);
            $this->assertSame('blocked', $settlement->metadata['grant_status']);
            $this->assertSame(8500, (int) $order->fresh()->amount_paid);
        }
        $this->assertDatabaseCount('user_entitlement_grants', 0);
    }

    public function test_one_hundred_percent_zero_cash_has_no_provider_and_full_refund_preserves_used_promo(): void
    {
        $order = $this->fixture();
        $promo = $this->promo('FREE', 'percent_bps', 10000);
        $this->apply($order, 'FREE');
        $settlement = app(OrderDiscountService::class)->finalizeZeroCash($order->user_id, $order->id, 'zero');
        $this->assertSame($settlement->id, app(OrderDiscountService::class)->finalizeZeroCash($order->user_id, $order->id, 'zero')->id);
        $this->assertSame('settled_zero_cash', $settlement->settlement_type);
        $this->assertSame(0, (int) $order->fresh()->amount_paid);
        $this->assertSame('granted', $order->fresh()->access_status);
        $this->assertDatabaseCount('payment_attempts', 0);
        $this->assertDatabaseCount('outbox_events', 1);
        $promo->update(['status' => 'revoked']);
        config(['bonuses.checkout_enabled' => false]);
        $refund = app(ZeroCashRefundService::class)->request($order->fresh(), ['refund_type' => 'full',
            'policy_access_action' => 'revoke', 'reason' => 'fixture'], 'zero-refund', $order->user_id);
        $this->assertSame('refunded', $order->fresh()->payment_status);
        $this->assertSame('committed', PromoReservation::query()->sole()->status);
        foreach (OutboxEvent::query()->get() as $event) {
            (new ProcessOutboxEventJob($event->id))->handle();
        }
        foreach (SideEffectExecution::query()->whereIn('handler', ['entitlement.grant', 'entitlement.revoke'])->get() as $effect) {
            app(EntitlementEffectEvidenceService::class)->execute($effect->id);
            $this->assertSame('completed', $effect->fresh()->status);
        }
        $this->assertSame('revoked', UserEntitlementGrant::query()->sole()->status);
        $this->assertSame(0, (int) $refund->amount);
        Http::assertNothingSent();
    }

    public function test_zero_finalizer_rejects_expired_reserve_and_unpriced_free_order(): void
    {
        $order = $this->fixture();
        $this->promo('EXPIRED_FREE', 'percent_bps', 10000);
        $this->apply($order, 'EXPIRED_FREE');
        $this->travel(31)->minutes();
        $this->reject('BONUS_ZERO_CASH_PROOF_REQUIRED', fn () => app(OrderDiscountService::class)->finalizeZeroCash($order->user_id, $order->id, 'zero'));
        $other = $this->fixture();
        $other->update(['amount_final' => 0]);
        $this->reject('BONUS_ZERO_CASH_PROOF_REQUIRED', fn () => app(OrderDiscountService::class)->finalizeZeroCash($other->user_id, $other->id, 'zero'));
        $this->assertDatabaseCount('order_settlements', 0);
        $this->assertDatabaseCount('user_entitlement_grants', 0);
    }

    public function test_attempt_uses_discounted_cash_and_reservation_expiry(): void
    {
        $order = $this->fixture();
        $this->promo('SHORT')->update(['expires_at' => now()->addMinutes(2)]);
        $this->apply($order, 'SHORT');
        $created = app(PaymentAttemptCreator::class)->create($order->fresh(), ['provider' => 'test_provider',
            'return_url' => 'https://example.test/ok', 'cancel_url' => 'https://example.test/cancel'], app(PaymentStateMachine::class));
        $attempt = PaymentAttempt::query()->findOrFail($created->active_attempt_id);
        $this->assertSame(8500, (int) $attempt->amount);
        $this->assertTrue($attempt->expires_at->lessThanOrEqualTo(now()->addMinutes(2)));
        Http::assertNothingSent();
    }

    public function test_owner_disabled_flag_and_changed_key_are_enforced(): void
    {
        $order = $this->fixture();
        $this->promo('OWNER');
        config(['bonuses.checkout_enabled' => false]);
        $this->reject('BONUS_CHECKOUT_DISABLED', fn () => $this->apply($order, 'OWNER'));
        config(['bonuses.checkout_enabled' => true]);
        $this->reject('BONUS_ORDER_OWNER_MISMATCH', fn () => app(PromoOrderPricingService::class)
            ->apply(User::factory()->create()->id, $order->id, 'OWNER', 'key'));
        $this->apply($order, 'OWNER');
        $this->reject('BONUS_IDEMPOTENCY_CONFLICT', fn () => app(PromoOrderPricingService::class)
            ->apply($order->user_id, $order->id, 'OWNER', 'different-key'));
        $this->assertDatabaseCount('promo_reservations', 1);
    }

    public function test_zero_cash_keys_are_scoped_per_order_and_usage_capacity_is_reserved(): void
    {
        foreach (['FIRST', 'SECOND'] as $code) {
            $order = $this->fixture();
            $this->promo($code, 'percent_bps', 10000);
            $this->apply($order, $code);
            $other = $this->fixture();
            $this->reject('BONUS_PROMO_LIMIT_REACHED', fn () => $this->apply($other, $code));
            app(OrderDiscountService::class)->finalizeZeroCash($order->user_id, $order->id, 'same-public-key');
        }
        $this->assertDatabaseCount('order_settlements', 2);
        $this->assertDatabaseCount('user_entitlement_grants', 2);
        $this->assertDatabaseCount('payment_attempts', 0);
    }

    public function test_podeli_never_captures_revoked_promo_and_authoritative_cash_survives(): void
    {
        $order = $this->fixture();
        $promo = $this->promo('CAPTURE');
        $this->apply($order, 'CAPTURE');
        [$attempt] = $this->attempt($order->fresh());
        config(['podeli.sandbox' => true, 'podeli.capture_mode' => 'two_stage', 'podeli.login' => 'test']);
        $attempt->update(['provider' => 'podeli', 'provider_payment_id' => PodeliClient::orderId($order->id, $attempt->id),
            'provider_substatus' => ['sandbox' => true, 'capture_mode' => 'two_stage', 'account_fingerprint' => PodeliClient::accountFingerprint()]]);
        $base = ['id' => $attempt->provider_payment_id, 'amount' => '85.00', 'prepaidAmount' => '0.00',
            'items' => [['id' => PodeliClient::itemId($order->items()->sole()->sellable_sku), 'amount' => '85.00', 'quantity' => '1', 'prepaidAmount' => '0.00']]];
        $client = Mockery::mock(PodeliClient::class)->makePartial();
        $client->shouldReceive('getOrder')->times(3)->andReturn(
            ['order' => [...$base, 'status' => 'WAIT_FOR_COMMIT']], ['order' => [...$base, 'status' => 'COMPLETED']], ['order' => [...$base, 'status' => 'COMPLETED']]);
        $client->shouldNotReceive('commit');
        app()->instance(PodeliClient::class, $client);
        $promo->update(['status' => 'revoked']);
        $handler = app(PodeliWebhookHandler::class);
        $handler->reconcile($attempt->fresh());
        $this->assertSame('BONUS_PROMO_INVALID', $attempt->fresh()->provider_substatus['coin_capture_blocked']);
        $this->assertSame(0, (int) $order->fresh()->amount_paid);
        $handler->reconcile($attempt->fresh());
        $handler->reconcile($attempt->fresh());
        $this->assertSame(8500, (int) $order->fresh()->amount_paid);
        $this->assertSame('grant_failed', $order->fresh()->access_status);
        $this->assertDatabaseCount('user_entitlement_grants', 0);
    }

    private function fixture(): Order
    {
        $user = User::factory()->create();
        $subject = Subject::query()->create(['slug' => 'promo-'.Str::uuid(), 'title' => 'Subject', 'sort_order' => 1, 'is_active' => true]);
        $course = Course::query()->create(['subject_id' => $subject->id, 'slug' => 'promo-'.Str::uuid(), 'title' => 'Course',
            'short_title' => 'Course', 'price_cents' => 10000, 'sort_order' => 1, 'is_active' => true]);
        Lesson::query()->create(['course_id' => $course->id, 'slug' => 'lesson', 'title' => 'Lesson', 'sort_order' => 1, 'is_active' => true]);
        $order = Order::query()->create(['user_id' => $user->id, 'course_id' => $course->id, 'payment_status' => 'draft',
            'access_status' => 'not_granted', 'currency' => 'RUB', 'amount_total' => 10000, 'amount_discount' => 0,
            'amount_final' => 10000, 'amount_paid' => 0, 'amount_refunded' => 0]);
        app(OrderItemSnapshotService::class)->createCourseItem($order, $course);
        CoinLedgerEntry::query()->create(['user_id' => $user->id, 'event_type' => 'coins_accrual', 'units' => 200,
            'idempotency_key' => 'fixture:'.Str::uuid()]);

        return $order->fresh();
    }

    private function promo(string $code, string $kind = 'fixed_minor', int $discount = 1500): PromoCode
    {
        return PromoCode::query()->create(['code_hash' => PromoReservationService::codeHash($code), 'status' => 'active',
            'discount_kind' => $kind, $kind === 'fixed_minor' ? 'discount_minor' : 'discount_bps' => $discount,
            'currency' => 'RUB', 'expires_at' => now()->addDays(7), 'activation_limit' => 1,
            'per_user_limit' => 1, 'rules_version' => 'fixture-v1']);
    }

    private function apply(Order $order, string $code, ?string $quote = null): Order
    {
        return app(PromoOrderPricingService::class)->apply($order->user_id, $order->id, $code, 'apply:'.$order->id, $quote);
    }

    private function attempt(Order $order): array
    {
        $attempt = PaymentAttempt::query()->create(['order_id' => $order->id, 'provider' => 'test',
            'status' => 'pending', 'amount' => $order->amount_final, 'currency' => 'RUB']);
        $order->update(['active_attempt_id' => $attempt->id, 'payment_status' => 'pending_payment']);
        $event = ProviderEvent::query()->create(['provider' => 'test', 'provider_event_id' => 'fixture:'.$order->id,
            'event_type' => 'pay', 'order_id' => $order->id, 'attempt_id' => $attempt->id, 'signature_valid' => true,
            'processed' => true, 'payload_raw' => [], 'received_at' => now()]);

        return [$attempt, $event];
    }

    private function confirm(Order $order, PaymentAttempt $attempt, ProviderEvent $event): OrderSettlement
    {
        return app(OrderSettlementService::class)->confirmPaid($order, ['idempotency_key' => 'cash:'.$order->id,
            'amount' => (int) $attempt->amount, 'currency' => 'RUB', 'settlement_type' => 'provider_payment',
            'payment_attempt_id' => $attempt->id, 'provider_event_id' => $event->id]);
    }

    private function reject(string $code, callable $call): void
    {
        try {
            $call();
            $this->fail('Expected '.$code);
        } catch (DomainException $exception) {
            $this->assertSame($code, $exception->getMessage());
        }
    }
}
