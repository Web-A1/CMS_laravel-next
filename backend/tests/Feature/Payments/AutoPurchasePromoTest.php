<?php

namespace Tests\Feature\Payments;

use App\Models\Order;
use App\Models\OrderSettlement;
use App\Models\PaymentAuditLog;
use App\Models\PromoCode;
use App\Models\Refund;
use App\Models\User;
use App\Services\Bonuses\AutoPurchasePromoService;
use App\Services\Bonuses\PromoReservationService;
use DomainException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class AutoPurchasePromoTest extends TestCase
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

    public function test_percent_discount_rounds_half_up_to_kopeck_without_overflow(): void
    {
        foreach ([[1, 5000, 1], [1, 4999, 0], [101, 1000, 10], [105, 1000, 11], [PHP_INT_MAX, 10000, PHP_INT_MAX]] as [$subtotal, $bps, $expected]) {
            $order = $this->order($subtotal, false);
            $code = 'P_'.Str::random(20);
            $this->promo($code, $bps);
            $quote = app(PromoReservationService::class)->quote($order->user_id, $order->id, $code);
            $this->assertSame($expected, $quote['promo_discount']);
            $this->assertSame($subtotal - $expected, $quote['payable_total']);
            $this->assertSame('minor_half_up_v1', $quote['discount_rounding']);
        }
    }

    public function test_invalid_percent_and_unknown_kind_reject(): void
    {
        $order = $this->order(100000, false);
        foreach ([0, -1, 10001] as $bps) {
            $code = 'BAD_'.Str::random(12);
            $this->promo($code, $bps);
            $this->reject('BONUS_PROMO_INVALID', fn () => app(PromoReservationService::class)->quote($order->user_id, $order->id, $code));
        }
    }

    public function test_cash_thresholds_select_highest_percentage_and_zero_cash_issues_nothing(): void
    {
        foreach ([0 => 0, 99999 => 0, 100000 => 1000, 499999 => 1000, 500000 => 1500, 999999 => 1500, 1000000 => 2500] as $cash => $bps) {
            $order = $this->order($cash);
            $result = $this->service()->apply($order->id);
            $this->assertSame($bps, $result['discount_bps']);
            $this->assertSame($bps === 0 ? 'skipped_threshold' : 'issued', $result['status']);
            if ($bps !== 0) {
                $promo = PromoCode::query()->findOrFail($result['promo_code_id']);
                $this->assertSame(now()->addDays(7)->toIso8601String(), $promo->expires_at->toIso8601String());
                $this->assertSame(hash('sha256', mb_strtolower($order->user->email)), $promo->bound_email_hash);
                $this->assertSame(1, $promo->activation_limit);
                $this->assertSame(1, $promo->per_user_limit);
            }
        }
        $this->assertDatabaseCount('promo_codes', 5);
        $this->assertDatabaseCount('coin_ledger_entries', 0);
        Http::assertNothingSent();
    }

    public function test_mixed_coin_order_uses_cash_not_catalog_total_for_threshold(): void
    {
        $order = $this->order(60000);
        $order->update(['amount_total' => 100000, 'amount_discount' => 40000]);
        $order->items()->update(['unit_amount' => 100000, 'subtotal_amount' => 100000, 'discount_amount' => 40000]);
        $this->assertSame('skipped_threshold', $this->service()->apply($order->id)['status']);
        $this->assertDatabaseCount('promo_codes', 0);
    }

    public function test_issuance_replay_does_not_duplicate_or_extend_and_plaintext_is_only_encrypted(): void
    {
        $order = $this->order(100000);
        $first = $this->service()->apply($order->id);
        $marker = $this->issuance($order);
        $code = Crypt::decryptString($marker->meta['encrypted_code']);
        $promo = PromoCode::query()->findOrFail($first['promo_code_id']);
        $this->assertSame(PromoReservationService::codeHash($code), $promo->code_hash);
        $this->assertStringNotContainsString($code, json_encode($marker->toArray(), JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString($code, json_encode($promo->toArray(), JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString($code, json_encode($first, JSON_THROW_ON_ERROR));
        $this->travel(2)->days();
        $this->assertSame($first, $this->service()->apply($order->id));
        $this->assertSame($promo->toArray(), $promo->fresh()->toArray());
        $this->assertDatabaseCount('promo_codes', 1);
        $this->assertSame(1, PaymentAuditLog::query()->where('action', 'auto_purchase_promo_applied')->count());
    }

    public function test_late_job_cannot_extend_expired_purchase_promotion(): void
    {
        $order = $this->order(100000);
        $this->travel(7)->days();
        $this->assertSame('skipped_expired', $this->service()->apply($order->id)['status']);
        $this->assertDatabaseCount('promo_codes', 0);
    }

    public function test_full_refund_before_issuance_skips_and_partial_refund_does_not_revoke(): void
    {
        $order = $this->order(100000);
        $partial = $this->refund($order, 1000, false);
        $this->reject('BONUS_PROMO_FULL_REFUND_REQUIRED', fn () => $this->service()->revokeForRefund($partial->id));
        $final = $this->refund($order->fresh(), 99000, true);
        $this->assertSame('skipped_not_issued', $this->service()->revokeForRefund($final->id)['status']);
        $this->assertSame('skipped_refunded', $this->service()->apply($order->id)['status']);
        $this->assertDatabaseCount('promo_codes', 0);
    }

    public function test_cumulative_full_refund_revokes_unused_code_idempotently(): void
    {
        $order = $this->order(100000);
        $issued = $this->service()->apply($order->id);
        $this->refund($order, 1000, false);
        $final = $this->refund($order->fresh(), 99000, true);
        $result = $this->service()->revokeForRefund($final->id);
        $this->assertSame('revoked', $result['status']);
        $this->assertSame($result, $this->service()->revokeForRefund($final->id));
        $this->assertSame('revoked', PromoCode::query()->findOrFail($issued['promo_code_id'])->status);
        $this->assertSame(1, PaymentAuditLog::query()->where('action', 'auto_purchase_promo_refund_applied')->count());
    }

    public function test_used_code_full_refund_enters_review_without_touching_using_order(): void
    {
        $source = $this->order(100000);
        $issued = $this->service()->apply($source->id);
        $code = Crypt::decryptString($this->issuance($source)->meta['encrypted_code']);
        $next = $this->order(100000, false, $source->user);
        $reservation = app(PromoReservationService::class)->reserve($next->user_id, $next->id, $code, 'used');
        app(PromoReservationService::class)->commit($next->user_id, $reservation->id);
        $nextBefore = $next->fresh()->toArray();
        $refund = $this->refund($source, 100000, true);
        $this->assertSame('promotion_violation_review', $this->service()->revokeForRefund($refund->id)['status']);
        $this->assertSame('promotion_violation_review', PromoCode::query()->findOrFail($issued['promo_code_id'])->status);
        $this->assertSame($nextBefore, $next->fresh()->toArray());
        $this->assertSame('committed', $reservation->fresh()->status);
    }

    public function test_reserved_but_unused_code_revokes_and_cannot_be_committed(): void
    {
        $source = $this->order(100000);
        $this->service()->apply($source->id);
        $code = Crypt::decryptString($this->issuance($source)->meta['encrypted_code']);
        $next = $this->order(100000, false, $source->user);
        $reservation = app(PromoReservationService::class)->reserve($next->user_id, $next->id, $code, 'reserved');
        $refund = $this->refund($source, 100000, true);
        $this->assertSame('revoked', $this->service()->revokeForRefund($refund->id)['status']);
        $this->reject('BONUS_PROMO_INVALID', fn () => app(PromoReservationService::class)->commit($next->user_id, $reservation->id));
        $this->assertSame('reserved', $reservation->fresh()->status);
    }

    public function test_changed_settlement_replay_and_unverified_cash_purchase_reject(): void
    {
        $order = $this->order(100000);
        $this->service()->apply($order->id);
        $order->update(['amount_paid' => 200000, 'amount_final' => 200000]);
        $order->settlements()->sole()->update(['amount' => 200000]);
        $this->reject('BONUS_PROMO_EVIDENCE_CONFLICT', fn () => $this->service()->apply($order->id));
        $blocked = $this->order(100000);
        $blocked->settlements()->sole()->update(['metadata' => ['grant_status' => 'blocked']]);
        $this->reject('BONUS_PROMO_PURCHASE_REQUIRES_REVIEW', fn () => $this->service()->apply($blocked->id));
        $this->assertDatabaseCount('promo_codes', 1);
    }

    public function test_outer_rollback_leaves_no_issued_code_or_audit_marker(): void
    {
        $order = $this->order(100000);
        $this->reject('ROLLBACK', function () use ($order): void {
            DB::transaction(function () use ($order): void {
                $this->service()->apply($order->id);
                throw new DomainException('ROLLBACK');
            });
        });
        $this->assertDatabaseCount('promo_codes', 0);
        $this->assertDatabaseCount('payment_audit_log', 0);
    }

    private function order(int $cash, bool $paid = true, ?User $user = null): Order
    {
        $user ??= User::factory()->create();
        $order = Order::query()->create(['user_id' => $user->id, 'payment_status' => $paid ? 'paid' : 'draft',
            'access_status' => $paid ? 'granted' : 'not_granted', 'currency' => 'RUB', 'amount_total' => $cash,
            'amount_discount' => 0, 'amount_final' => $cash, 'amount_paid' => $paid ? $cash : 0, 'amount_refunded' => 0,
            'paid_at' => $paid ? now() : null]);
        $order->items()->create(['sellable_kind' => 'course', 'sellable_id' => 1, 'sellable_sku' => 'fixture:course',
            'purchased_entity_type' => 'course', 'purchased_entity_id' => 1, 'display_title' => 'Fixture course',
            'quantity' => 1, 'currency' => 'RUB', 'unit_amount' => $cash, 'subtotal_amount' => $cash,
            'discount_amount' => 0, 'final_amount' => $cash, 'snapshot_hash' => str_repeat('a', 64), 'grant_snapshot' => ['atoms' => []]]);
        if ($paid) {
            OrderSettlement::query()->create(['order_id' => $order->id, 'idempotency_key' => 'fixture:'.$order->id,
                'settlement_type' => $cash > 0 ? 'provider_payment' : 'settled_zero_cash', 'amount' => $cash,
                'currency' => 'RUB', 'status' => 'confirmed', 'confirmed_at' => now(), 'fingerprint_hash' => str_repeat('a', 64),
                'metadata' => ['grant_status' => 'granted']]);
        }

        return $order;
    }

    private function promo(string $code, int $bps): PromoCode
    {
        return PromoCode::query()->create(['code_hash' => PromoReservationService::codeHash($code), 'status' => 'active',
            'discount_kind' => 'percent_bps', 'discount_bps' => $bps, 'currency' => 'RUB', 'expires_at' => now()->addDays(7),
            'activation_limit' => 1, 'per_user_limit' => 1, 'rules_version' => 'test']);
    }

    private function refund(Order $order, int $amount, bool $full): Refund
    {
        $refund = Refund::query()->create(['order_id' => $order->id, 'amount' => $amount, 'currency' => 'RUB',
            'refund_type' => $full ? 'full' : 'partial', 'policy_access_action' => $full ? 'revoke' : 'keep',
            'reason' => 'fixture', 'status' => 'completed', 'completed_at' => now()]);
        $order->update(['amount_refunded' => (int) $order->amount_refunded + $amount,
            'payment_status' => $full ? 'refunded' : 'paid']);

        return $refund;
    }

    private function issuance(Order $order): PaymentAuditLog
    {
        return PaymentAuditLog::query()->where('order_id', $order->id)->where('action', 'auto_purchase_promo_applied')->sole();
    }

    private function service(): AutoPurchasePromoService
    {
        return app(AutoPurchasePromoService::class);
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
