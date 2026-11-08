<?php

namespace Tests\Feature\Payments;

use App\Models\CoinLedgerEntry;
use App\Models\CoinReservationAllocation;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\PromoCode;
use App\Models\Subject;
use App\Models\User;
use App\Services\Bonuses\CoinLedgerService;
use App\Services\Bonuses\PromoReservationService;
use App\Services\OrderItemSnapshotService;
use DomainException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class BonusReservationCoreTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        $this->travelTo(now()->startOfSecond());
    }

    public function test_no_synthetic_balance_and_pricing_requires_explicit_conversion(): void
    {
        [$user, $order] = $this->fixture();
        $this->assertSame(0, $this->coins()->balance($user->id));
        $this->assertSame(100, config('bonuses.coin_minor_units'));
        config(['bonuses.coin_minor_units' => null]);
        $this->reject('BONUS_COIN_CONVERSION_UNCONFIGURED', fn () => $this->coins()->quote($user->id, $order->id, 1));
        $this->reject('BONUS_INSUFFICIENT_COINS', fn () => $this->coins()->reserve($user->id, $order->id, 1, 'no-balance'));
        $this->assertDatabaseCount('coin_reservations', 0);
        $this->assertDatabaseCount('order_settlements', 0);
    }

    public function test_fifo_by_accrual_time_and_id_commit_is_exactly_once(): void
    {
        [$user, $order] = $this->fixture();
        $first = $this->lot($user, 40, now()->addDays(7));
        $second = $this->lot($user, 80, now()->addDays(1));
        $reserve = $this->coins()->reserve($user->id, $order->id, 70, 'fifo');
        $allocations = CoinReservationAllocation::query()->orderBy('id')->get();
        $this->assertSame([$first->id, $second->id], $allocations->pluck('lot_id')->all());
        $this->assertSame([40, 30], $allocations->pluck('units')->all());
        $this->assertSame(50, $this->coins()->balance($user->id));
        $this->assertSame($reserve->id, $this->coins()->reserve($user->id, $order->id, 70, 'fifo')->id);
        $this->coins()->commit($user->id, $reserve->id);
        $this->coins()->commit($user->id, $reserve->id);
        $this->assertSame(2, CoinLedgerEntry::query()->where('event_type', 'coins_commit')->count());
        $this->assertSame(50, $this->coins()->balance($user->id));
        $this->reject('BONUS_RESERVATION_TERMINAL', fn () => $this->coins()->release($user->id, $reserve->id, 'cancelled'));
        $this->assertSame('pending_payment', $order->fresh()->payment_status);
        $this->assertDatabaseCount('order_settlements', 0);
    }

    public function test_reserve_expires_and_cannot_commit_after_funds_are_reserved_elsewhere(): void
    {
        [$user, $order] = $this->fixture();
        $this->lot($user, 100);
        $reserve = $this->coins()->reserve($user->id, $order->id, 100, 'expired');
        $this->travel(30)->minutes();
        $this->assertSame(100, $this->coins()->balance($user->id));
        [, $other] = $this->fixture($user);
        $second = $this->coins()->reserve($user->id, $other->id, 100, 'second');
        $this->reject('BONUS_RESERVATION_EXPIRED', fn () => $this->coins()->commit($user->id, $reserve->id));
        $this->coins()->release($user->id, $reserve->id, 'expired');
        $this->coins()->release($user->id, $reserve->id, 'expired');
        $this->coins()->commit($user->id, $second->id);
        $this->assertSame(0, $this->coins()->balance($user->id));
        $this->assertSame(1, CoinLedgerEntry::query()->where('event_type', 'coins_release')->count());
    }

    public function test_early_lot_expiry_shortens_reserve_and_release_never_revives_expired_coins(): void
    {
        [$user, $order] = $this->fixture();
        $this->lot($user, 100, now()->addMinutes(2));
        config(['bonuses.coin_minor_units' => 100]);
        $quote = $this->coins()->quote($user->id, $order->id, 100);
        $this->assertSame(now()->addMinutes(2)->toIso8601String(), $quote['expires_at']);
        $reserve = $this->coins()->reserve($user->id, $order->id, 100, 'short');
        $this->assertTrue($reserve->expires_at->equalTo(now()->addMinutes(2)));
        $this->travel(2)->minutes();
        $this->coins()->release($user->id, $reserve->id, 'expired');
        $this->assertSame(0, $this->coins()->balance($user->id));
    }

    public function test_release_restores_only_original_unexpired_lots_and_enforces_replay_intent(): void
    {
        [$user, $order] = $this->fixture();
        $this->lot($user, 100);
        $reserve = $this->coins()->reserve($user->id, $order->id, 70, 'release');
        $this->reject('BONUS_IDEMPOTENCY_CONFLICT', fn () => $this->coins()->reserve($user->id, $order->id, 71, 'release'));
        $this->coins()->release($user->id, $reserve->id, 'cancelled');
        $this->assertSame(100, $this->coins()->balance($user->id));
        $this->reject('BONUS_IDEMPOTENCY_CONFLICT', fn () => $this->coins()->release($user->id, $reserve->id, 'failed'));
        $this->reject('BONUS_RESERVATION_TERMINAL', fn () => $this->coins()->commit($user->id, $reserve->id));
    }

    public function test_ownership_purchase_drift_and_ledger_mutation_reject(): void
    {
        [$user, $order] = $this->fixture();
        $stranger = User::factory()->create();
        $lot = $this->lot($user, 100);
        $this->reject('BONUS_ORDER_OWNER_MISMATCH', fn () => $this->coins()->reserve($stranger->id, $order->id, 1, 'foreign'));
        $reserve = $this->coins()->reserve($user->id, $order->id, 50, 'owned');
        $this->reject('BONUS_ORDER_OWNER_MISMATCH', fn () => $this->coins()->commit($stranger->id, $reserve->id));
        $order->items()->update(['sellable_sku' => 'changed']);
        $this->reject('BONUS_PURCHASE_CHANGED', fn () => $this->coins()->commit($user->id, $reserve->id));
        $this->reject('BONUS_LEDGER_IMMUTABLE', fn () => $lot->update(['units' => 200]));
        $this->reject('BONUS_LEDGER_IMMUTABLE', fn () => $lot->delete());
        $this->assertSame(50, $this->coins()->balance($user->id));
    }

    public function test_fixed_promo_quotes_backend_subtotal_and_can_cover_all_cash_without_settling(): void
    {
        [$user, $order] = $this->fixture();
        $this->promo('FULL', 20000);
        $quote = $this->promos()->quote($user->id, $order->id, 'FULL');
        $this->assertSame(10000, $quote['promo_discount']);
        $this->assertSame(0, $quote['payable_total']);
        $reservation = $this->promos()->reserve($user->id, $order->id, 'FULL', 'full');
        $this->promos()->commit($user->id, $reservation->id);
        $this->promos()->commit($user->id, $reservation->id);
        $this->assertSame('committed', $reservation->fresh()->status);
        $this->assertSame('pending_payment', $order->fresh()->payment_status);
        $this->assertDatabaseCount('order_settlements', 0);
    }

    public function test_promo_limits_count_reserves_and_commits_and_release_frees_capacity(): void
    {
        [$user, $order] = $this->fixture();
        [$other, $otherOrder] = $this->fixture();
        $this->promo('ONCE', 1000);
        $reserve = $this->promos()->reserve($user->id, $order->id, 'ONCE', 'one');
        $this->reject('BONUS_PROMO_LIMIT_REACHED', fn () => $this->promos()->reserve($other->id, $otherOrder->id, 'ONCE', 'two'));
        $this->promos()->release($user->id, $reserve->id, 'cancelled');
        $next = $this->promos()->reserve($other->id, $otherOrder->id, 'ONCE', 'two');
        $this->promos()->commit($other->id, $next->id);
        $this->reject('BONUS_PROMO_LIMIT_REACHED', fn () => $this->promos()->reserve($user->id, $order->id, 'ONCE', 'three'));
    }

    public function test_promo_email_terms_and_expiry_fail_closed(): void
    {
        [$user, $order] = $this->fixture();
        $promo = $this->promo('BOUND', 1000);
        $promo->update(['bound_email_hash' => hash('sha256', 'someone-else@example.test')]);
        $this->reject('BONUS_PROMO_INVALID', fn () => $this->promos()->quote($user->id, $order->id, 'BOUND'));
        $promo->update(['bound_email_hash' => hash('sha256', mb_strtolower(trim($user->email)))]);
        $reserve = $this->promos()->reserve($user->id, $order->id, 'BOUND', 'bound');
        $promo->update(['discount_minor' => 2000]);
        $this->reject('BONUS_PROMO_TERMS_CHANGED', fn () => $this->promos()->commit($user->id, $reserve->id));
        $this->travel(30)->minutes();
        $this->reject('BONUS_RESERVATION_EXPIRED', fn () => $this->promos()->commit($user->id, $reserve->id));
    }

    public function test_promo_and_coins_are_mutually_exclusive_in_both_directions(): void
    {
        [$user, $order] = $this->fixture();
        $this->lot($user, 100);
        $this->promo('XOR', 1000);
        $coins = $this->coins()->reserve($user->id, $order->id, 50, 'coins');
        $this->reject('BONUS_DISCOUNT_ALREADY_RESERVED', fn () => $this->promos()->reserve($user->id, $order->id, 'XOR', 'promo'));
        $this->coins()->release($user->id, $coins->id, 'abandoned');
        $this->promos()->reserve($user->id, $order->id, 'XOR', 'promo');
        $this->reject('BONUS_DISCOUNT_ALREADY_RESERVED', fn () => $this->coins()->reserve($user->id, $order->id, 50, 'new-coins'));
    }

    public function test_percentage_uses_approved_rounding_and_coin_quote_is_read_only(): void
    {
        [$user, $order] = $this->fixture();
        $this->promo('PERCENT', 1000)->update(['discount_kind' => 'percent_bps', 'discount_bps' => 1000]);
        $this->assertSame(1000, $this->promos()->quote($user->id, $order->id, 'PERCENT')['promo_discount']);
        $this->lot($user, 100);
        config(['bonuses.coin_minor_units' => 100]);
        $quote = $this->coins()->quote($user->id, $order->id, 100);
        $this->assertSame(0, $quote['payable_total']);
        $this->assertSame(10000, $quote['coins_writeoff']);
        $this->assertTrue($quote['reservation_required']);
        $this->assertDatabaseCount('coin_reservations', 0);
        $this->assertSame(100, $this->coins()->balance($user->id));
        $this->reject('BONUS_COINS_EXCEED_ORDER', fn () => $this->coins()->quote($user->id, $order->id, 101));
    }

    public function test_nested_commit_rolls_back_with_root_settlement_transaction(): void
    {
        [$user, $order] = $this->fixture();
        $this->lot($user, 100);
        $reserve = $this->coins()->reserve($user->id, $order->id, 100, 'atomic');
        $this->reject('ROOT_SETTLEMENT_FAILED', function () use ($user, $reserve): void {
            DB::transaction(function () use ($user, $reserve): void {
                $this->coins()->commit($user->id, $reserve->id);
                throw new DomainException('ROOT_SETTLEMENT_FAILED');
            });
        });
        $this->assertSame('reserved', $reserve->fresh()->status);
        $this->assertSame(0, CoinLedgerEntry::query()->where('event_type', 'coins_commit')->count());
        $this->assertSame(0, $this->coins()->balance($user->id));
    }

    public function test_new_discount_cannot_be_reserved_after_provider_attempt_started(): void
    {
        [$user, $order] = $this->fixture();
        $this->lot($user, 100);
        $this->promo('LATE', 1000);
        $attempt = PaymentAttempt::query()->create(['order_id' => $order->id, 'provider' => 'test',
            'status' => 'pending', 'amount' => 10000, 'currency' => 'RUB']);
        $order->update(['active_attempt_id' => $attempt->id]);
        $this->reject('BONUS_ORDER_ALREADY_IN_PAYMENT', fn () => $this->coins()->reserve($user->id, $order->id, 1, 'late-coins'));
        $this->reject('BONUS_ORDER_ALREADY_IN_PAYMENT', fn () => $this->promos()->reserve($user->id, $order->id, 'LATE', 'late-promo'));
        $this->assertDatabaseCount('coin_reservations', 0);
        $this->assertDatabaseCount('promo_reservations', 0);
    }

    public function test_per_user_promo_limit_and_exact_replay_enforced_independently_of_global_limit(): void
    {
        [$user, $order] = $this->fixture();
        [, $another] = $this->fixture($user);
        $this->promo('PER_USER', 1000)->update(['activation_limit' => 5]);
        $reserve = $this->promos()->reserve($user->id, $order->id, 'PER_USER', 'same');
        $this->assertSame($reserve->id, $this->promos()->reserve($user->id, $order->id, 'PER_USER', 'same')->id);
        $this->reject('BONUS_IDEMPOTENCY_CONFLICT', fn () => $this->promos()->reserve($user->id, $another->id, 'PER_USER', 'same'));
        $this->reject('BONUS_PROMO_LIMIT_REACHED', fn () => $this->promos()->reserve($user->id, $another->id, 'PER_USER', 'different'));
        $this->travel(30)->minutes();
        $this->promos()->reserve($user->id, $another->id, 'PER_USER', 'different');
        $this->reject('BONUS_RESERVATION_EXPIRED', fn () => $this->promos()->commit($user->id, $reserve->id));
    }

    public function test_invalid_ledger_events_and_nonpositive_units_cannot_be_created(): void
    {
        [$user] = $this->fixture();
        $this->reject('BONUS_UNITS_INVALID', fn () => $this->lot($user, 0));
        $this->reject('BONUS_UNITS_INVALID', fn () => $this->lot($user, -1));
        $this->reject('BONUS_LEDGER_EVENT_INVALID', fn () => CoinLedgerEntry::query()->create([
            'user_id' => $user->id, 'event_type' => 'coins_refund', 'units' => 1, 'idempotency_key' => 'unimplemented-refund',
        ]));
        $this->assertSame(0, $this->coins()->balance($user->id));
    }

    public function test_global_key_collision_after_lookup_is_domain_conflict_without_losing_request_allocations(): void
    {
        foreach (['coin_reservations', 'promo_reservations'] as $table) {
            [$user, $order] = $this->fixture();
            [$winnerUser, $winnerOrder] = $this->fixture();
            $this->lot($user, 100);
            $this->promo('RACE_'.$table, 1000);
            $winner = [
                'user_id' => $winnerUser->id, 'order_id' => $winnerOrder->id,
                'idempotency_key' => 'collision-'.$table, 'intent_hash' => str_repeat('f', 64),
                'status' => 'reserved', 'expires_at' => now()->addMinutes(30), 'proof' => '{}',
                'created_at' => now(), 'updated_at' => now(),
            ];
            if ($table === 'coin_reservations') {
                $winner['units'] = 1;
            } else {
                $winner['promo_code_id'] = $this->promo('WINNER_'.$table, 1000)->id;
                $winner['discount_minor'] = 1000;
            }
            $injected = false;
            // Deterministically insert after the empty SELECT, before createOrFirst's
            // savepoint. This exercises actual unique-constraint recovery in SQLite;
            // it is not a substitute for PostgreSQL concurrency evidence.
            DB::listen(function (QueryExecuted $query) use ($table, $winner, &$injected): void {
                if (! $injected && str_starts_with($query->sql, 'select * from "'.$table.'" where "idempotency_key"')) {
                    $injected = true;
                    DB::table($table)->insert($winner);
                }
            });
            $this->reject('BONUS_IDEMPOTENCY_CONFLICT', fn () => $table === 'coin_reservations'
                ? $this->coins()->reserve($user->id, $order->id, 50, $winner['idempotency_key'])
                : $this->promos()->reserve($user->id, $order->id, 'RACE_'.$table, $winner['idempotency_key']));
            $this->assertTrue($injected);
            $this->assertSame(100, $this->coins()->balance($user->id));
            $this->assertDatabaseCount('coin_reservation_allocations', 0);
            $this->assertSame(0, CoinLedgerEntry::query()->where('event_type', 'coins_reserve')->count());
        }
    }

    private function fixture(?User $user = null): array
    {
        $user ??= User::factory()->create();
        $subject = Subject::query()->create(['slug' => 'bonus-'.Str::uuid(), 'title' => 'Subject', 'sort_order' => 1, 'is_active' => true]);
        $course = Course::query()->create(['subject_id' => $subject->id, 'slug' => 'bonus-'.Str::uuid(),
            'title' => 'Course', 'short_title' => 'Course', 'price_cents' => 10000, 'sort_order' => 1, 'is_active' => true]);
        Lesson::query()->create(['course_id' => $course->id, 'slug' => 'lesson', 'title' => 'Lesson', 'sort_order' => 1, 'is_active' => true]);
        $order = Order::query()->create(['user_id' => $user->id, 'course_id' => $course->id,
            'payment_status' => 'pending_payment', 'access_status' => 'not_granted', 'currency' => 'RUB',
            'amount_total' => 10000, 'amount_discount' => 0, 'amount_final' => 10000, 'amount_paid' => 0, 'amount_refunded' => 0]);
        app(OrderItemSnapshotService::class)->createCourseItem($order, $course);

        return [$user, $order];
    }

    private function lot(User $user, int $units, mixed $expires = null): CoinLedgerEntry
    {
        return CoinLedgerEntry::query()->create(['user_id' => $user->id, 'event_type' => 'coins_accrual',
            'units' => $units, 'idempotency_key' => 'fixture:'.Str::uuid(), 'expires_at' => $expires]);
    }

    private function promo(string $code, int $amount): PromoCode
    {
        return PromoCode::query()->create(['code_hash' => PromoReservationService::codeHash($code), 'status' => 'active',
            'discount_kind' => 'fixed_minor', 'discount_minor' => $amount, 'currency' => 'RUB',
            'expires_at' => now()->addDays(7), 'activation_limit' => 1, 'per_user_limit' => 1, 'rules_version' => 'test-v1']);
    }

    private function coins(): CoinLedgerService
    {
        return app(CoinLedgerService::class);
    }

    private function promos(): PromoReservationService
    {
        return app(PromoReservationService::class);
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
