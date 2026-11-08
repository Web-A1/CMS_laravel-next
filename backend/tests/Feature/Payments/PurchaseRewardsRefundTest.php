<?php

namespace Tests\Feature\Payments;

use App\Models\CoinLedgerEntry;
use App\Models\CoinReservation;
use App\Models\Order;
use App\Models\OrderSettlement;
use App\Models\PaymentAuditLog;
use App\Models\Refund;
use App\Models\User;
use App\Services\Bonuses\CoinLedgerService;
use App\Services\Bonuses\PurchaseBonusRefundService;
use App\Services\Bonuses\PurchaseRewardService;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class PurchaseRewardsRefundTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        $this->travelTo(now()->startOfSecond());
        Http::preventStrayRequests();
    }

    public function test_reward_rounding_uses_cash_only_and_replays_original_result(): void
    {
        foreach ([0 => 0, 999 => 0, 1000 => 1, 2999 => 1, 3000 => 2, 99900 => 50, 60000 => 30] as $cash => $expected) {
            $order = $this->order($cash);
            $order->update(['amount_total' => $cash + 40000, 'amount_discount' => 40000]);
            $result = $this->reward($order);
            $this->assertSame($expected, $result['reward_units']);
            $this->assertSame($result, $this->reward($order));
            $this->assertSame($expected, $this->balance($order));
        }
        $this->assertSame(7, PaymentAuditLog::query()->where('action', 'purchase_reward_applied')->count());
    }

    public function test_unconfirmed_paid_flag_cannot_mint_rewards(): void
    {
        $order = $this->order(100000);
        OrderSettlement::query()->where('order_id', $order->id)->delete();
        $this->reject('BONUS_CONFIRMED_SETTLEMENT_REQUIRED', fn () => $this->reward($order));
        $this->assertDatabaseCount('coin_ledger_entries', 0);
    }

    public function test_refund_before_reward_does_not_mint_late_rewards(): void
    {
        $order = $this->order(100000);
        $refund = $this->refund($order);
        $result = $this->reverse($refund);
        $this->assertSame($result, $this->reverse($refund));
        $this->assertSame('skipped_refunded', $this->reward($order)['status']);
        $this->assertDatabaseCount('coin_ledger_entries', 0);
    }

    public function test_spent_coins_restore_original_expiry_and_never_revive_expired_balance(): void
    {
        foreach ([false, true] as $expired) {
            $source = $this->order(100000);
            $this->reward($source);
            $lot = $this->lot($source);
            $purchase = $this->order(0, $source->user_id);
            $this->consume($purchase, $lot, 20);
            if ($expired) {
                $this->travel(61)->days();
            }
            $refund = $this->refund($purchase);
            $result = $this->reverse($refund);
            $this->assertSame(20, $result['restored_units']);
            $entry = CoinLedgerEntry::query()->where('order_id', $purchase->id)->where('event_type', 'coins_refund')->sole();
            $this->assertTrue($entry->expires_at->equalTo($lot->expires_at));
            $this->assertSame($expired ? 0 : 50, $this->balance($source));
            $this->assertSame($result, $this->reverse($refund));
        }
    }

    public function test_reversal_debt_is_only_consumed_units_and_future_reward_repays_it(): void
    {
        $source = $this->order(100000);
        $this->reward($source);
        $spend = $this->order(0, $source->user_id);
        $this->consume($spend, $this->lot($source), 30);
        $refund = $this->refund($source);
        $result = $this->reverse($refund);
        $this->assertSame(30, $result['debt_units']);
        $this->assertSame(0, $this->balance($source));
        $this->assertSame($result, $this->reverse($refund));
        $next = $this->order(100000, $source->user_id);
        $this->assertSame(['status' => 'applied', 'reward_units' => 50, 'debt_repaid' => 30], $this->reward($next));
        $this->assertSame(20, $this->balance($next));
        $this->assertSame(0, $this->debt($source));
        // Reversing an award used for debt repayment must reopen the original debt.
        $nextRefund = $this->refund($next);
        $this->assertSame(30, $this->reverse($nextRefund)['debt_units']);
        $this->assertSame(30, $this->debt($source));
        $this->assertSame(0, $this->balance($next));
    }

    public function test_expired_unspent_reward_and_pending_reservation_create_no_debt(): void
    {
        foreach ([false, true] as $expired) {
            $source = $this->order(100000);
            $this->reward($source);
            $other = $this->order(0, $source->user_id);
            $this->consume($other, $this->lot($source), 30, 'reserved');
            if ($expired) {
                $this->travel(61)->days();
            }
            $this->assertSame(0, $this->reverse($this->refund($source))['debt_units']);
            $this->assertSame(0, $this->balance($source));
            $this->assertSame(0, $this->debt($source));
        }
    }

    public function test_returned_consumption_is_not_counted_as_debt_again(): void
    {
        $source = $this->order(100000);
        $this->reward($source);
        $spend = $this->order(0, $source->user_id);
        $this->consume($spend, $this->lot($source), 30);
        $this->reverse($this->refund($spend));
        $this->assertSame(0, $this->reverse($this->refund($source))['debt_units']);
        $this->assertSame(0, $this->debt($source));
    }

    public function test_revoked_lot_restitution_requires_review_without_rolling_back_cash_refund(): void
    {
        $source = $this->order(100000);
        $this->reward($source);
        $spend = $this->order(20000, $source->user_id);
        $this->consume($spend, $this->lot($source), 30);
        $this->reverse($this->refund($source));
        $refund = $this->refund($spend);
        $before = CoinLedgerEntry::query()->count();
        $this->reject('BONUS_REVOKED_LOT_REFUND_REVIEW', fn () => $this->reverse($refund));
        $this->assertSame($before, CoinLedgerEntry::query()->count());
        $this->assertSame('completed', $refund->fresh()->status);
        $this->assertSame('refunded', $spend->fresh()->payment_status);
        $this->assertSame(20000, (int) $spend->fresh()->amount_refunded);
    }

    public function test_partial_or_unconfirmed_refund_cannot_restore_coins(): void
    {
        $order = $this->order(100000);
        $this->reward($order);
        $refund = $this->refund($order);
        $refund->update(['status' => 'submitted']);
        $this->reject('BONUS_FULL_REFUND_EVIDENCE_REQUIRED', fn () => $this->reverse($refund));
        $refund->update(['status' => 'completed', 'refund_type' => 'partial', 'amount' => 50000]);
        $this->reject('BONUS_FULL_REFUND_EVIDENCE_REQUIRED', fn () => $this->reverse($refund));
        $this->assertSame(50, $this->balance($order));
    }

    public function test_cumulative_partial_refunds_reverse_once_at_terminal_completion_not_largest_id(): void
    {
        $source = $this->order(100000);
        $this->reward($source);
        $purchase = $this->order(60000, $source->user_id);
        $this->consume($purchase, $this->lot($source), 20);
        $this->reward($purchase);
        // Terminal request was created first, but completed last.
        $terminal = Refund::query()->create(['order_id' => $purchase->id, 'refund_type' => 'partial', 'amount' => 40000,
            'currency' => 'RUB', 'status' => 'submitted', 'policy_access_action' => 'revoke', 'request_key' => 'terminal']);
        $earlier = Refund::query()->create(['order_id' => $purchase->id, 'refund_type' => 'partial', 'amount' => 20000,
            'currency' => 'RUB', 'status' => 'completed', 'completed_at' => now(), 'policy_access_action' => 'keep', 'request_key' => 'earlier']);
        $purchase->update(['amount_refunded' => 20000]);
        $count = CoinLedgerEntry::query()->count();
        $this->reject('BONUS_FULL_REFUND_EVIDENCE_REQUIRED', fn () => $this->reverse($earlier));
        $this->assertSame($count, CoinLedgerEntry::query()->count());
        $this->travel(1)->seconds();
        $terminal->update(['status' => 'completed', 'completed_at' => now()]);
        $purchase->update(['amount_refunded' => 60000, 'payment_status' => 'refunded']);
        $result = $this->reverse($terminal);
        $this->assertSame(20, $result['restored_units']);
        $this->assertSame(30, $result['revoked_units']);
        $this->assertSame(50, $this->balance($purchase));
        $this->assertSame($result, $this->reverse($terminal));
        $this->reject('BONUS_FULL_REFUND_EVIDENCE_REQUIRED', fn () => $this->reverse($earlier));
        $this->assertSame(1, CoinLedgerEntry::query()->where('order_id', $purchase->id)->where('event_type', 'coins_refund')->count());
        $this->assertSame(1, CoinLedgerEntry::query()->where('order_id', $purchase->id)->where('event_type', 'coins_revoke')->count());
        $earlier->update(['completed_at' => now()->addSecond()]);
        $this->reject('BONUS_FULL_REFUND_EVIDENCE_REQUIRED', fn () => $this->reverse($terminal));
    }

    public function test_audit_failure_rolls_back_reward_and_refund_ledger_writes(): void
    {
        $order = $this->order(100000);
        $action = 'purchase_reward_applied';
        PaymentAuditLog::creating(function (PaymentAuditLog $audit) use (&$action): void {
            if ($audit->action === $action) {
                throw new RuntimeException('injected audit failure');
            }
        });
        try {
            $this->reward($order);
            $this->fail('Expected rollback');
        } catch (RuntimeException $exception) {
            $this->assertSame('injected audit failure', $exception->getMessage());
        }
        $this->assertDatabaseCount('coin_ledger_entries', 0);
        $action = '';
        $this->reward($order);
        $action = 'purchase_bonus_refund_applied';
        $refund = $this->refund($order);
        try {
            $this->reverse($refund);
            $this->fail('Expected rollback');
        } catch (RuntimeException $exception) {
            $this->assertSame('injected audit failure', $exception->getMessage());
        }
        $this->assertDatabaseCount('coin_ledger_entries', 1);
        $this->assertSame('completed', $refund->fresh()->status);
        $action = '';
        $this->assertSame(50, $this->reverse($refund)['revoked_units']);
    }

    public function test_reward_replay_rejects_changed_settlement_amount(): void
    {
        $order = $this->order(100000);
        $this->reward($order);
        $order->update(['amount_paid' => 200000, 'amount_final' => 200000]);
        OrderSettlement::query()->where('order_id', $order->id)->update(['amount' => 200000]);
        $this->reject('BONUS_REWARD_EVIDENCE_CONFLICT', fn () => $this->reward($order));
        $this->assertSame(50, $this->balance($order));
    }

    private function order(int $cash, ?int $userId = null): Order
    {
        $order = Order::query()->create(['user_id' => $userId ?? User::factory()->create()->id,
            'currency' => 'RUB', 'amount_total' => $cash, 'amount_final' => $cash, 'amount_paid' => $cash,
            'payment_status' => 'paid', 'paid_at' => now()]);
        OrderSettlement::query()->create(['order_id' => $order->id, 'amount' => $cash, 'currency' => 'RUB',
            'status' => 'confirmed', 'confirmed_at' => now(), 'idempotency_key' => 'test:'.Str::uuid(),
            'settlement_type' => $cash === 0 ? 'settled_zero_cash' : 'provider_payment', 'fingerprint_hash' => str_repeat('a', 64)]);

        return $order;
    }

    private function refund(Order $order): Refund
    {
        $order->update(['payment_status' => 'refunded', 'amount_refunded' => $order->amount_paid]);

        return Refund::query()->create(['order_id' => $order->id, 'refund_type' => 'full', 'amount' => $order->amount_paid,
            'currency' => 'RUB', 'status' => 'completed', 'completed_at' => now(), 'policy_access_action' => 'revoke', 'request_key' => 'test:'.Str::uuid()]);
    }

    private function consume(Order $order, CoinLedgerEntry $lot, int $units, string $status = 'committed'): void
    {
        $reservation = CoinReservation::query()->create(['order_id' => $order->id, 'user_id' => $order->user_id,
            'units' => $units, 'status' => $status, 'expires_at' => now()->addMinutes(30), 'proof' => [],
            'intent_hash' => str_repeat('b', 64), 'idempotency_key' => 'test:'.Str::uuid()]);
        CoinLedgerEntry::query()->create(['user_id' => $order->user_id, 'order_id' => $order->id,
            'reservation_id' => $reservation->id, 'lot_id' => $lot->id, 'units' => $units,
            'event_type' => $status === 'committed' ? 'coins_commit' : 'coins_reserve', 'idempotency_key' => 'test:'.Str::uuid()]);
    }

    private function lot(Order $order): CoinLedgerEntry
    {
        return CoinLedgerEntry::query()->where('order_id', $order->id)->where('event_type', 'coins_accrual')->sole();
    }

    private function reward(Order $order): array
    {
        return app(PurchaseRewardService::class)->apply($order->id);
    }

    private function reverse(Refund $refund): array
    {
        return app(PurchaseBonusRefundService::class)->apply($refund->id);
    }

    private function balance(Order $order): int
    {
        return app(CoinLedgerService::class)->balance($order->user_id);
    }

    private function debt(Order $order): int
    {
        return (int) CoinLedgerEntry::query()->where('user_id', $order->user_id)->where('event_type', 'coins_debt')->sum('units')
            - (int) CoinLedgerEntry::query()->where('user_id', $order->user_id)->where('event_type', 'coins_debt_repay')->sum('units');
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
