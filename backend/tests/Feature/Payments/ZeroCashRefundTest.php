<?php

namespace Tests\Feature\Payments;

use App\Jobs\ProcessOutboxEventJob;
use App\Models\CoinLedgerEntry;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\Order;
use App\Models\OrderSettlement;
use App\Models\OutboxEvent;
use App\Models\Refund;
use App\Models\SideEffectExecution;
use App\Models\Subject;
use App\Models\User;
use App\Models\UserEntitlementGrant;
use App\Services\Bonuses\CoinOrderPricingService;
use App\Services\Bonuses\PurchaseBonusRefundService;
use App\Services\OrderItemSnapshotService;
use App\Services\Payments\EntitlementEffectEvidenceService;
use App\Services\Payments\ZeroCashRefundService;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class ZeroCashRefundTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        Queue::fake();
        Http::preventStrayRequests();
        config(['bonuses.checkout_enabled' => true]);
    }

    public function test_completed_zero_cash_refund_replays_with_checkout_disabled_and_no_provider(): void
    {
        $order = $this->fixture();
        $userBefore = User::query()->findOrFail($order->user_id)->getAttributes();
        $independent = UserEntitlementGrant::query()->where('order_id', $order->id)->sole()->replicate();
        $independent->forceFill(['source_type' => 'test_fixture', 'source_id' => 'independent',
            'idempotency_key' => 'independent', 'order_id' => null, 'order_item_id' => null])->save();
        config(['bonuses.checkout_enabled' => false]);
        $refund = $this->request($order);
        $this->assertSame($refund->id, $this->request($order)->id);
        $this->assertSame('completed', $refund->status);
        $this->assertSame(0, (int) $refund->amount);
        $this->assertNull($refund->attempt_id);
        $this->assertSame('refunded', $order->fresh()->payment_status);
        $this->assertSame('revoked', $order->items()->sole()->access_status);
        $this->assertSame('revoked', UserEntitlementGrant::query()->where('order_id', $order->id)->sole()->status);
        $this->assertSame($userBefore, User::query()->findOrFail($order->user_id)->getAttributes());
        $this->assertSame('active', $independent->fresh()->status);
        $this->assertDatabaseCount('refunds', 1);
        $this->assertDatabaseCount('payment_attempts', 0);
        $this->assertSame(1, OutboxEvent::query()->where('refund_id', $refund->id)->count());
        $this->assertSame(100, app(PurchaseBonusRefundService::class)->apply($refund->id)['restored_units']);
        Http::assertNothingSent();
    }

    public function test_global_foreign_key_and_changed_intent_cannot_reuse_refund(): void
    {
        $first = $this->fixture();
        $this->request($first);
        $second = $this->fixture();
        $this->reject('PAYMENT_REFUND_IDEMPOTENCY_CONFLICT', fn () => $this->request($second));
        $this->reject('PAYMENT_REFUND_IDEMPOTENCY_CONFLICT', fn () => $this->request($first, 'refund-key', 'changed'));
        $this->assertSame('paid', $second->fresh()->payment_status);
        $this->assertSame('active', UserEntitlementGrant::query()->where('order_id', $second->id)->sole()->status);
    }

    public function test_missing_key_mixed_cash_and_foreign_coin_binding_are_rejected(): void
    {
        $order = $this->fixture();
        $this->reject('PAYMENT_REFUND_IDEMPOTENCY_KEY_REQUIRED', fn () => $this->request($order, ''));
        $order->update(['amount_paid' => 1]);
        $this->reject('PAYMENT_ZERO_CASH_REFUND_EVIDENCE_REQUIRED', fn () => $this->request($order));
        $order->update(['amount_paid' => 0]);
        $other = $this->fixture();
        $item = $order->items()->sole();
        $item->update(['benefit_snapshot' => $other->items()->sole()->benefit_snapshot]);
        $this->reject('BONUS_BINDING_INVALID', fn () => $this->request($order));
        $this->assertDatabaseCount('refunds', 0);
    }

    public function test_outbox_failure_rolls_back_refund_and_grant_revocation(): void
    {
        $order = $this->fixture();
        OutboxEvent::creating(function (OutboxEvent $event): void {
            if ($event->refund_id !== null) {
                throw new RuntimeException('injected outbox failure');
            }
        });
        try {
            $this->request($order);
            $this->fail('Expected rollback');
        } catch (RuntimeException $exception) {
            $this->assertSame('injected outbox failure', $exception->getMessage());
        }
        $this->assertDatabaseCount('refunds', 0);
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertSame('granted', $order->items()->sole()->access_status);
        $this->assertSame('active', UserEntitlementGrant::query()->where('order_id', $order->id)->sole()->status);
    }

    public function test_partial_and_keep_policy_are_rejected(): void
    {
        $order = $this->fixture();
        foreach ([['refund_type' => 'partial', 'policy_access_action' => 'revoke'], ['refund_type' => 'full', 'policy_access_action' => 'keep']] as $input) {
            $this->reject('PAYMENT_REFUND_WHOLE_SERVICE_REQUIRED', fn () => app(ZeroCashRefundService::class)
                ->request($order, [...$input, 'reason' => 'test'], 'key', $order->user_id));
        }
        $this->assertDatabaseCount('refunds', 0);
    }

    public function test_zero_cash_historical_grant_and_revoke_effects_verify_without_domain_mutations(): void
    {
        $order = $this->fixture();
        $refund = $this->request($order);
        foreach (OutboxEvent::query()->where('order_id', $order->id)->get() as $event) {
            (new ProcessOutboxEventJob($event->id))->handle();
        }
        $grant = SideEffectExecution::query()->where('order_id', $order->id)->where('handler', 'entitlement.grant')->sole();
        $revoke = SideEffectExecution::query()->where('refund_id', $refund->id)->where('handler', 'entitlement.revoke')->sole();
        $before = [$order->fresh()->toArray(), $refund->fresh()->toArray(), UserEntitlementGrant::query()->get()->toArray(), CoinLedgerEntry::query()->get()->toArray()];
        config(['bonuses.checkout_enabled' => false]);
        app(EntitlementEffectEvidenceService::class)->execute($grant->id);
        app(EntitlementEffectEvidenceService::class)->execute($revoke->id);
        $this->assertSame('completed', $grant->fresh()->status);
        $this->assertSame('historical_grant_verified_then_refunded', $grant->fresh()->meta['evidence']['outcome']);
        $this->assertSame('completed', $revoke->fresh()->status);
        $this->assertSame('revocation_verified', $revoke->fresh()->meta['evidence']['outcome']);
        $this->assertSame($before, [$order->fresh()->toArray(), $refund->fresh()->toArray(), UserEntitlementGrant::query()->get()->toArray(), CoinLedgerEntry::query()->get()->toArray()]);
    }

    public function test_zero_cash_verifier_rejects_malformed_settlement_binding_and_refund(): void
    {
        foreach (['settlement', 'binding', 'refund'] as $corruption) {
            $order = $this->fixture();
            $refund = $this->request($order, 'refund:'.$order->id);
            $event = OutboxEvent::query()->where('refund_id', $refund->id)->sole();
            (new ProcessOutboxEventJob($event->id))->handle();
            $effect = SideEffectExecution::query()->where('refund_id', $refund->id)->where('handler', 'entitlement.revoke')->sole();
            if ($corruption === 'settlement') {
                OrderSettlement::query()->where('order_id', $order->id)->update(['settlement_type' => 'provider_payment']);
            } elseif ($corruption === 'binding') {
                $item = $order->items()->sole();
                $snapshot = $item->benefit_snapshot;
                $snapshot['coin_reservation']['proof_hash'] = str_repeat('0', 64);
                $item->update(['benefit_snapshot' => $snapshot]);
            } else {
                $refund->update(['refund_type' => 'partial']);
            }
            app(EntitlementEffectEvidenceService::class)->execute($effect->id);
            $this->assertSame('manual_review', $effect->fresh()->status);
            $this->assertFalse($effect->fresh()->meta['verified']);
            $this->assertSame('refunded', $order->fresh()->payment_status);
        }
    }

    private function request(Order $order, string $key = 'refund-key', string $reason = 'test'): Refund
    {
        return app(ZeroCashRefundService::class)->request($order,
            ['refund_type' => 'full', 'policy_access_action' => 'revoke', 'reason' => $reason], $key, $order->user_id);
    }

    private function fixture(): Order
    {
        $user = User::factory()->create();
        $subject = Subject::query()->create(['slug' => 'zero-'.Str::uuid(), 'title' => 'Subject', 'sort_order' => 1, 'is_active' => true]);
        $course = Course::query()->create(['subject_id' => $subject->id, 'slug' => 'zero-'.Str::uuid(), 'title' => 'Course',
            'short_title' => 'Course', 'price_cents' => 10000, 'sort_order' => 1, 'is_active' => true]);
        Lesson::query()->create(['course_id' => $course->id, 'slug' => 'lesson', 'title' => 'Lesson', 'sort_order' => 1, 'is_active' => true]);
        $order = Order::query()->create(['user_id' => $user->id, 'course_id' => $course->id, 'payment_status' => 'draft',
            'access_status' => 'not_granted', 'currency' => 'RUB', 'amount_total' => 10000, 'amount_discount' => 0,
            'amount_final' => 10000, 'amount_paid' => 0, 'amount_refunded' => 0]);
        app(OrderItemSnapshotService::class)->createCourseItem($order, $course);
        CoinLedgerEntry::query()->create(['user_id' => $user->id, 'event_type' => 'coins_accrual', 'units' => 100,
            'idempotency_key' => 'fixture:'.Str::uuid()]);
        app(CoinOrderPricingService::class)->apply($user->id, $order->id, 100, 'price:'.$order->id);
        app(CoinOrderPricingService::class)->finalizeZeroCash($user->id, $order->id, 'settle:'.$order->id);

        return $order->fresh();
    }

    private function reject(string $expected, callable $action): void
    {
        try {
            $action();
            $this->fail('Expected '.$expected);
        } catch (DomainException $exception) {
            $this->assertSame($expected, $exception->getMessage());
        }
    }
}
