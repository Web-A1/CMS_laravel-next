<?php

namespace Tests\Feature\Payments;

use App\Models\CoinLedgerEntry;
use App\Models\CoinReservation;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\Order;
use App\Models\OrderSettlement;
use App\Models\PaymentAttempt;
use App\Models\ProviderEvent;
use App\Models\Subject;
use App\Models\User;
use App\Services\Bonuses\CoinOrderPricingService;
use App\Services\OrderItemSnapshotService;
use App\Services\OrderSettlementService;
use App\Services\Payments\CloudpaymentsWebhookHandler;
use App\Services\Payments\PaymentAttemptCreator;
use App\Services\Payments\PodeliClient;
use App\Services\Payments\PodeliWebhookHandler;
use App\Services\Payments\RobokassaWebhookHandler;
use App\Services\Payments\YandexSplitClient;
use App\Services\Payments\YandexSplitWebhookHandler;
use App\Services\PaymentStateMachine;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class CoinCheckoutSettlementTest extends TestCase
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
        config(['bonuses.checkout_enabled' => true, 'bonuses.coin_minor_units' => 100]);
    }

    public function test_disabled_new_apply_and_zero_cash_cannot_mutate(): void
    {
        [$user, $order] = $this->fixture();
        config(['bonuses.checkout_enabled' => false]);
        $this->reject('BONUS_CHECKOUT_DISABLED', fn () => $this->pricing()->apply($user->id, $order->id, 100, 'disabled'));
        $this->reject('BONUS_CHECKOUT_DISABLED', fn () => $this->pricing()->finalizeZeroCash($user->id, $order->id, 'disabled'));
        $this->assertDatabaseCount('coin_reservations', 0);
        $this->assertSame(10000, (int) $order->fresh()->amount_final);
    }

    public function test_apply_rehashes_price_snapshot_preserves_atoms_and_is_idempotent(): void
    {
        [$user, $order] = $this->fixture();
        $original = $order->items()->sole();
        $updated = $this->pricing()->apply($user->id, $order->id, 40, 'apply');
        $item = $updated->items()->sole();
        $this->assertSame(4000, (int) $updated->amount_discount);
        $this->assertSame(6000, (int) $updated->amount_final);
        $this->assertSame($original->grant_snapshot, $item->grant_snapshot);
        $this->assertSame($original->subtotal_amount, $item->subtotal_amount);
        $this->assertSame(OrderItemSnapshotService::snapshotHash($item->getAttributesForSnapshot()), $item->snapshot_hash);
        $this->assertSame($updated->toArray(), $this->pricing()->apply($user->id, $order->id, 40, 'apply')->toArray());
        $this->reject('BONUS_IDEMPOTENCY_CONFLICT', fn () => $this->pricing()->apply($user->id, $order->id, 41, 'apply'));
        $this->reject('BONUS_ORDER_OWNER_MISMATCH', fn () => $this->pricing()->apply(User::factory()->create()->id, $order->id, 40, 'apply'));
    }

    public function test_zero_cash_commits_once_and_grants_without_provider_or_attempt(): void
    {
        [$user, $order] = $this->fixture();
        $this->pricing()->apply($user->id, $order->id, 100, 'zero-price');
        $settlement = $this->pricing()->finalizeZeroCash($user->id, $order->id, 'zero-settle');
        $this->pricing()->finalizeZeroCash($user->id, $order->id, 'zero-settle');
        $this->assertSame('settled_zero_cash', $settlement->settlement_type);
        $this->assertSame(0, (int) $settlement->amount);
        $this->assertSame('committed', $settlement->metadata['coin_settlement']['state']);
        $this->assertSame('granted', $order->fresh()->access_status);
        $this->assertDatabaseCount('payment_attempts', 0);
        $this->assertDatabaseCount('order_settlements', 1);
        $this->assertDatabaseCount('user_entitlement_grants', 1);
        $this->assertDatabaseCount('outbox_events', 1);
        $this->assertSame(1, CoinLedgerEntry::query()->where('event_type', 'coins_commit')->count());
        Http::assertNothingSent();
    }

    public function test_generic_amount_zero_is_not_a_free_access_loophole(): void
    {
        [, $order] = $this->fixture();
        $order->update(['payment_status' => 'pending_payment']);
        $this->reject('BONUS_ZERO_CASH_PROOF_REQUIRED', fn () => app(OrderSettlementService::class)->confirmPaid($order, [
            'idempotency_key' => 'fake-zero', 'amount' => 0, 'currency' => 'RUB', 'settlement_type' => 'settled_zero_cash']));
        $this->assertDatabaseCount('order_settlements', 0);
        $this->assertDatabaseCount('user_entitlement_grants', 0);
    }

    public function test_mixed_payment_commits_coins_before_paid_and_records_only_cash(): void
    {
        [$user, $order] = $this->fixture();
        $this->pricing()->apply($user->id, $order->id, 40, 'mixed');
        [$attempt, $event] = $this->attempt($order->fresh());
        config(['bonuses.checkout_enabled' => false]);
        $settlement = $this->confirm($order->fresh(), $attempt, $event);
        $this->confirm($order->fresh(), $attempt, $event);
        $this->assertSame(6000, (int) $settlement->amount);
        $this->assertSame(6000, (int) $order->fresh()->amount_paid);
        $this->assertSame('granted', $order->fresh()->access_status);
        $this->assertSame('committed', CoinReservation::query()->sole()->status);
        $this->assertSame(1, CoinLedgerEntry::query()->where('event_type', 'coins_commit')->count());
    }

    public function test_expired_or_released_reserve_keeps_actual_cash_and_permanently_blocks_replay_grants(): void
    {
        foreach (['expired', 'released'] as $case) {
            [$user, $order] = $this->fixture();
            $this->pricing()->apply($user->id, $order->id, 40, 'late-'.$case);
            [$attempt, $event] = $this->attempt($order->fresh());
            if ($case === 'expired') {
                $this->travel(31)->minutes();
            } else {
                $this->pricing()->releaseForOrder($order->fresh(), 'cancelled');
                $order->update(['payment_status' => 'cancelled']);
            }
            $settlement = $this->confirm($order->fresh(), $attempt, $event);
            $this->assertSame('paid', $order->fresh()->payment_status);
            $this->assertSame(6000, (int) $order->fresh()->amount_paid);
            $this->assertSame('grant_failed', $order->fresh()->access_status);
            $this->assertSame('blocked', $settlement->metadata['grant_status']);
            $this->confirm($order->fresh(), $attempt, $event);
            $this->assertSame('grant_failed', $order->fresh()->access_status);
        }
        $this->assertDatabaseCount('user_entitlement_grants', 0);
        $this->assertSame(0, CoinLedgerEntry::query()->where('event_type', 'coins_commit')->count());
    }

    public function test_tampered_binding_cannot_grant_but_confirmed_cash_is_not_lost(): void
    {
        [$user, $order] = $this->fixture();
        $this->pricing()->apply($user->id, $order->id, 40, 'tamper');
        [$attempt, $event] = $this->attempt($order->fresh());
        $item = $order->items()->sole();
        $item->update(['benefit_snapshot' => [...$item->benefit_snapshot, 'coin_reservation' => ['reservation_id' => 999999, 'proof_hash' => 'wrong']]]);
        $settlement = $this->confirm($order->fresh(), $attempt, $event);
        $this->assertSame('blocked', $settlement->metadata['grant_status']);
        $this->assertSame(6000, (int) $order->fresh()->amount_paid);
        $this->assertDatabaseCount('user_entitlement_grants', 0);
    }

    public function test_settlement_failure_rolls_back_coin_commit(): void
    {
        [$user, $order] = $this->fixture();
        $this->pricing()->apply($user->id, $order->id, 40, 'rollback');
        [$attempt, $event] = $this->attempt($order->fresh());
        $this->reject('ROOT_FAILURE', function () use ($order, $attempt, $event): void {
            DB::transaction(function () use ($order, $attempt, $event): void {
                $this->confirm($order->fresh(), $attempt, $event);
                throw new DomainException('ROOT_FAILURE');
            });
        });
        $this->assertSame('reserved', CoinReservation::query()->sole()->status);
        $this->assertDatabaseCount('order_settlements', 0);
        $this->assertDatabaseCount('user_entitlement_grants', 0);
        $this->assertSame(0, CoinLedgerEntry::query()->where('event_type', 'coins_commit')->count());
    }

    public function test_attempt_creation_cannot_charge_full_price_for_zero_cash_or_expired_discount(): void
    {
        foreach ([100, 40] as $units) {
            [$user, $order] = $this->fixture();
            $this->pricing()->apply($user->id, $order->id, $units, 'attempt-'.$units);
            if ($units === 40) {
                $this->travel(31)->minutes();
            }
            $this->reject($units === 100 ? 'PAYMENT_POSITIVE_CASH_REQUIRED' : 'BONUS_RESERVATION_EXPIRED', fn () => app(PaymentAttemptCreator::class)->create($order->fresh(), [
                'provider' => 'test_provider', 'return_url' => 'https://example.test/ok', 'cancel_url' => 'https://example.test/cancel',
            ], app(PaymentStateMachine::class)));
        }
        $this->assertDatabaseCount('payment_attempts', 0);
        Http::assertNothingSent();
    }

    public function test_cloudpayments_late_pay_after_fail_records_cash_and_never_regrants(): void
    {
        [$user, $order] = $this->fixture();
        $this->pricing()->apply($user->id, $order->id, 40, 'cp-late');
        [$attempt] = $this->attempt($order->fresh(), 'cloudpayments');
        config(['services.payments.providers.cloudpayments.test_mode' => true]);
        $invoice = 'cp-'.$order->id.'-'.$attempt->id;
        $attempt->update(['provider_substatus' => ['mode' => 'widget', 'invoice_id' => $invoice]]);
        $payload = ['InvoiceId' => $invoice, 'TransactionId' => 777, 'Amount' => '60.00', 'Currency' => 'RUB',
            'TestMode' => 1, 'OperationType' => 'Payment', 'Status' => 'Completed', 'ReasonCode' => 5001];
        $handler = app(CloudpaymentsWebhookHandler::class);
        $this->assertSame(0, $handler->handle('fail', $payload));
        $this->assertSame('released', CoinReservation::query()->sole()->status);
        $this->assertSame(0, $handler->handle('pay', $payload));
        $this->assertSame(0, $handler->handle('pay', $payload));
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertSame('grant_failed', $order->fresh()->access_status);
        $this->assertDatabaseCount('order_settlements', 1);
        $this->assertDatabaseCount('user_entitlement_grants', 0);
    }

    public function test_podeli_does_not_capture_expired_coins_but_later_completed_info_records_cash(): void
    {
        [$user, $order] = $this->fixture();
        $this->pricing()->apply($user->id, $order->id, 40, 'podeli');
        [$attempt] = $this->attempt($order->fresh(), 'podeli');
        config(['podeli.sandbox' => true, 'podeli.capture_mode' => 'two_stage', 'podeli.login' => 'test']);
        $attempt->update(['provider_payment_id' => PodeliClient::orderId($order->id, $attempt->id),
            'provider_substatus' => ['sandbox' => true, 'capture_mode' => 'two_stage', 'account_fingerprint' => PodeliClient::accountFingerprint()]]);
        $base = ['id' => $attempt->provider_payment_id, 'amount' => '60.00', 'prepaidAmount' => '0.00',
            'items' => [['id' => PodeliClient::itemId($order->items()->sole()->sellable_sku), 'amount' => '60.00', 'quantity' => '1', 'prepaidAmount' => '0.00']]];
        $client = Mockery::mock(PodeliClient::class)->makePartial();
        $client->shouldReceive('getOrder')->times(3)->andReturn(
            ['order' => [...$base, 'status' => 'WAIT_FOR_COMMIT']], ['order' => [...$base, 'status' => 'COMPLETED']], ['order' => [...$base, 'status' => 'COMPLETED']]);
        $client->shouldNotReceive('commit');
        app()->instance(PodeliClient::class, $client);
        $this->travel(31)->minutes();
        $handler = app(PodeliWebhookHandler::class);
        $handler->reconcile($attempt->fresh());
        $this->assertSame('BONUS_RESERVATION_EXPIRED', $attempt->fresh()->provider_substatus['coin_capture_blocked']);
        $this->assertSame(0, (int) $order->fresh()->amount_paid);
        $handler->reconcile($attempt->fresh());
        $handler->reconcile($attempt->fresh());
        $this->assertSame(6000, (int) $order->fresh()->amount_paid);
        $this->assertSame('grant_failed', $order->fresh()->access_status);
        $this->assertDatabaseCount('user_entitlement_grants', 0);
    }

    public function test_yandex_confirmed_readback_cannot_regrant_expired_coin_order(): void
    {
        [$user, $order] = $this->fixture();
        $this->pricing()->apply($user->id, $order->id, 40, 'yandex');
        [$attempt] = $this->attempt($order->fresh(), 'yandex_split');
        config(['yandex_split.sandbox' => true, 'yandex_split.merchant_id' => 'merchant']);
        $attempt->update(['provider_payment_id' => YandexSplitClient::orderId($order->id, $attempt->id),
            'provider_substatus' => ['sandbox' => true, 'merchant_id' => 'merchant']]);
        $client = Mockery::mock(YandexSplitClient::class)->makePartial();
        $client->shouldReceive('getOrder')->twice()->andReturn(['order' => ['orderId' => $attempt->provider_payment_id,
            'merchantId' => 'merchant', 'currencyCode' => 'RUB', 'orderAmount' => '60.00', 'paymentStatus' => 'CAPTURED']]);
        app()->instance(YandexSplitClient::class, $client);
        $this->travel(31)->minutes();
        $handler = app(YandexSplitWebhookHandler::class);
        $handler->reconcile($attempt->fresh());
        $handler->reconcile($attempt->fresh());
        $this->assertSame(6000, (int) $order->fresh()->amount_paid);
        $this->assertSame('grant_failed', $order->fresh()->access_status);
        $this->assertDatabaseCount('order_settlements', 1);
        $this->assertDatabaseCount('user_entitlement_grants', 0);
    }

    public function test_robokassa_signed_confirmation_cannot_regrant_expired_coin_order(): void
    {
        [$user, $order] = $this->fixture();
        $this->pricing()->apply($user->id, $order->id, 40, 'robokassa');
        [$attempt] = $this->attempt($order->fresh(), 'robokassa');
        config(['robokassa.enabled' => true, 'robokassa.merchant_login' => 'merchant', 'robokassa.password1' => 'first',
            'robokassa.password2' => 'second', 'robokassa.hash' => 'md5', 'robokassa.test_mode' => false]);
        $attempt->update(['provider_payment_id' => (string) $attempt->id,
            'provider_substatus' => ['merchant_login' => 'merchant', 'test_mode' => false,
                'custom' => ['Shp_order' => (string) $order->id, 'Shp_test' => '0']]]);
        $body = http_build_query(['OutSum' => '60.00', 'InvId' => (string) $attempt->id, 'Shp_order' => (string) $order->id, 'Shp_test' => '0',
            'SignatureValue' => md5('60.00:'.$attempt->id.':second:Shp_order='.$order->id.':Shp_test=0')]);
        $this->travel(31)->minutes();
        $handler = app(RobokassaWebhookHandler::class);
        $this->assertSame('OK'.$attempt->id, $handler->handle($body));
        $this->assertSame('OK'.$attempt->id, $handler->handle($body));
        $this->assertSame(6000, (int) $order->fresh()->amount_paid);
        $this->assertSame('grant_failed', $order->fresh()->access_status);
        $this->assertDatabaseCount('user_entitlement_grants', 0);
    }

    private function fixture(): array
    {
        $user = User::factory()->create();
        $subject = Subject::query()->create(['slug' => 'coins-'.Str::uuid(), 'title' => 'Subject', 'sort_order' => 1, 'is_active' => true]);
        $course = Course::query()->create(['subject_id' => $subject->id, 'slug' => 'coins-'.Str::uuid(), 'title' => 'Course',
            'short_title' => 'Course', 'price_cents' => 10000, 'sort_order' => 1, 'is_active' => true]);
        Lesson::query()->create(['course_id' => $course->id, 'slug' => 'lesson', 'title' => 'Lesson', 'sort_order' => 1, 'is_active' => true]);
        $order = Order::query()->create(['user_id' => $user->id, 'course_id' => $course->id, 'payment_status' => 'draft',
            'access_status' => 'not_granted', 'currency' => 'RUB', 'amount_total' => 10000, 'amount_discount' => 0,
            'amount_final' => 10000, 'amount_paid' => 0, 'amount_refunded' => 0]);
        app(OrderItemSnapshotService::class)->createCourseItem($order, $course);
        CoinLedgerEntry::query()->create(['user_id' => $user->id, 'event_type' => 'coins_accrual', 'units' => 200,
            'idempotency_key' => 'fixture:'.Str::uuid()]);

        return [$user, $order->fresh()];
    }

    private function attempt(Order $order, string $provider = 'test'): array
    {
        $attempt = PaymentAttempt::query()->create(['order_id' => $order->id, 'provider' => $provider,
            'status' => 'pending', 'amount' => $order->amount_final, 'currency' => 'RUB']);
        $order->update(['active_attempt_id' => $attempt->id, 'payment_status' => 'pending_payment']);
        $event = ProviderEvent::query()->create(['provider' => $provider, 'provider_event_id' => 'fixture:'.$order->id,
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

    private function pricing(): CoinOrderPricingService
    {
        return app(CoinOrderPricingService::class);
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
