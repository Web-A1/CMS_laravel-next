<?php

namespace Tests\Feature\Payments;

use App\Models\CoinLedgerEntry;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\Order;
use App\Models\Subject;
use App\Models\User;
use App\Services\Bonuses\CoinLedgerService;
use App\Services\OrderItemSnapshotService;
use DomainException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class BonusPricedReservationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        $this->travelTo(now()->startOfSecond());
        config(['bonuses.coin_minor_units' => 100]);
        Http::preventStrayRequests();
    }

    public function test_priced_zero_cash_reserve_persists_exact_proof_without_mutating_order_or_settling(): void
    {
        [$user, $order] = $this->fixture();
        $beforeOrder = $order->toArray();
        $beforeItems = $order->items()->get()->toArray();
        $quote = $this->service()->quote($user->id, $order->id, 100);
        $reserve = $this->service()->reservePriced($user->id, $order->id, 100, 'priced', $quote['quote_hash']);
        $this->assertSame(100, $reserve->proof['coin_minor_units']);
        $this->assertSame(10000, $reserve->proof['coins_writeoff']);
        $this->assertSame(0, $reserve->proof['payable_total']);
        $this->assertSame('settled_zero_cash', $reserve->proof['settlement_type']);
        $this->assertTrue($reserve->proof['monetary_pricing_verified']);
        $this->assertFalse($reserve->proof['settlement_confirmed']);
        $this->assertSame($quote['quote_hash'], $reserve->proof['quote_hash']);
        $proof = $reserve->proof;
        $hash = $proof['proof_hash'];
        unset($proof['proof_hash']);
        $this->assertSame(hash('sha256', json_encode($proof, JSON_THROW_ON_ERROR)), $hash);
        $this->assertSame($beforeOrder, $order->fresh()->toArray());
        $this->assertSame($beforeItems, $order->items()->get()->toArray());
        $this->assertDatabaseCount('order_settlements', 0);
        $this->assertDatabaseCount('payment_attempts', 0);
        $this->assertDatabaseCount('user_entitlement_grants', 0);
        Http::assertNothingSent();
    }

    public function test_partial_coins_preserve_kopecks_and_never_round_up(): void
    {
        [$user, $order] = $this->fixture(10001);
        $reserve = $this->service()->reservePriced($user->id, $order->id, 100, 'kopeck');
        $this->assertSame(1, $reserve->proof['payable_total']);
        $this->assertSame('provider_payment', $reserve->proof['settlement_type']);
        [$anotherUser, $anotherOrder] = $this->fixture(10001);
        $this->reject('BONUS_COINS_EXCEED_ORDER', fn () => $this->service()->reservePriced($anotherUser->id, $anotherOrder->id, 101, 'over'));
    }

    public function test_unconfigured_or_unapproved_conversion_creates_no_reservation(): void
    {
        [$user, $order] = $this->fixture();
        foreach ([null, 50, '100'] as $rate) {
            config(['bonuses.coin_minor_units' => $rate]);
            $this->reject('BONUS_COIN_CONVERSION_UNCONFIGURED', fn () => $this->service()->reservePriced($user->id, $order->id, 10, 'rate'));
        }
        $this->assertDatabaseCount('coin_reservations', 0);
        $this->assertSame(200, $this->service()->balance($user->id));
    }

    public function test_priced_and_unit_only_operations_cannot_upgrade_or_downgrade_same_key(): void
    {
        [$user, $order] = $this->fixture();
        $legacy = $this->service()->reserve($user->id, $order->id, 10, 'unit-only');
        $this->reject('BONUS_IDEMPOTENCY_CONFLICT', fn () => $this->service()->reservePriced($user->id, $order->id, 10, 'unit-only'));
        $this->assertFalse($legacy->fresh()->proof['monetary_pricing_verified']);
        $this->service()->release($user->id, $legacy->id, 'abandoned');
        $priced = $this->service()->reservePriced($user->id, $order->id, 10, 'priced-only');
        $this->reject('BONUS_IDEMPOTENCY_CONFLICT', fn () => $this->service()->reserve($user->id, $order->id, 10, 'priced-only'));
        $this->assertTrue($priced->fresh()->proof['monetary_pricing_verified']);
    }

    public function test_same_key_returns_original_proof_without_renewal_or_repricing(): void
    {
        [$user, $order] = $this->fixture();
        $reserve = $this->service()->reservePriced($user->id, $order->id, 10, 'replay');
        $original = $reserve->fresh()->toArray();
        $this->travel(31)->minutes();
        config(['bonuses.coin_minor_units' => null]);
        $replay = $this->service()->reservePriced($user->id, $order->id, 10, 'replay', $reserve->proof['quote_hash']);
        $this->assertSame($original, $replay->toArray());
        $this->reject('BONUS_RESERVATION_EXPIRED', fn () => $this->service()->commit($user->id, $reserve->id));
        $this->assertSame(200, $this->service()->balance($user->id));
        $this->assertDatabaseCount('coin_reservation_allocations', 1);
    }

    public function test_expected_quote_is_validated_and_changed_units_or_purchase_reject(): void
    {
        [$user, $order] = $this->fixture();
        $quote = $this->service()->quote($user->id, $order->id, 10);
        $this->reject('BONUS_QUOTE_HASH_INVALID', fn () => $this->service()->reservePriced($user->id, $order->id, 10, 'hash', 'invalid'));
        $this->reject('BONUS_QUOTE_CHANGED', fn () => $this->service()->reservePriced($user->id, $order->id, 11, 'hash', $quote['quote_hash']));
        $order->items()->update(['sellable_sku' => 'different-purchase']);
        $this->reject('BONUS_QUOTE_CHANGED', fn () => $this->service()->reservePriced($user->id, $order->id, 10, 'hash', $quote['quote_hash']));
        $this->assertDatabaseCount('coin_reservations', 0);
    }

    public function test_proof_and_reservation_intent_are_immutable_but_release_remains_available(): void
    {
        [$user, $order] = $this->fixture();
        $reserve = $this->service()->reservePriced($user->id, $order->id, 10, 'immutable');
        foreach ([['proof' => [...$reserve->proof, 'payable_total' => 0]], ['units' => 100],
            ['expires_at' => now()->addDay()], ['intent_hash' => str_repeat('0', 64)], ['order_id' => 999]] as $mutation) {
            $this->reject('BONUS_RESERVATION_INTENT_IMMUTABLE', fn () => $reserve->fresh()->update($mutation));
        }
        $this->reject('BONUS_RESERVATION_INTENT_IMMUTABLE', fn () => $reserve->fresh()->delete());
        $this->service()->release($user->id, $reserve->id, 'abandoned');
        $this->assertSame('released', $reserve->fresh()->status);
        $this->assertSame($reserve->proof, $reserve->fresh()->proof);
    }

    public function test_allocation_lifetime_and_outer_rollback_are_preserved(): void
    {
        [$user, $order] = $this->fixture(10000, true);
        $quote = $this->service()->quote($user->id, $order->id, 10);
        $this->reject('ROOT_ABORT', function () use ($user, $order, $quote): void {
            DB::transaction(function () use ($user, $order, $quote): void {
                $reserve = $this->service()->reservePriced($user->id, $order->id, 10, 'rollback', $quote['quote_hash']);
                $this->assertSame(now()->addMinutes(2)->toIso8601String(), $reserve->proof['expires_at']);
                $this->assertSame($quote['expires_at'], $reserve->proof['expires_at']);
                throw new DomainException('ROOT_ABORT');
            });
        });
        $this->assertDatabaseCount('coin_reservations', 0);
        $this->assertDatabaseCount('coin_reservation_allocations', 0);
        $this->assertSame(200, $this->service()->balance($user->id));
    }

    public function test_global_key_race_does_not_allocate_losing_priced_request(): void
    {
        [$user, $order] = $this->fixture();
        [$winner, $winnerOrder] = $this->fixture();
        $injected = false;
        DB::listen(function (QueryExecuted $query) use (&$injected, $winner, $winnerOrder): void {
            if (! $injected && str_starts_with($query->sql, 'select * from "coin_reservations" where "idempotency_key"')) {
                $injected = true;
                DB::table('coin_reservations')->insert(['user_id' => $winner->id, 'order_id' => $winnerOrder->id,
                    'idempotency_key' => 'race', 'intent_hash' => str_repeat('f', 64), 'units' => 1,
                    'status' => 'reserved', 'expires_at' => now()->addMinutes(30), 'proof' => '{}',
                    'created_at' => now(), 'updated_at' => now()]);
            }
        });
        $this->reject('BONUS_IDEMPOTENCY_CONFLICT', fn () => $this->service()->reservePriced($user->id, $order->id, 10, 'race'));
        $this->assertTrue($injected);
        $this->assertDatabaseCount('coin_reservation_allocations', 0);
        $this->assertSame(200, $this->service()->balance($user->id));
    }

    private function fixture(int $amount = 10000, bool $expiresSoon = false): array
    {
        $user = User::factory()->create();
        $subject = Subject::query()->create(['slug' => 'priced-'.Str::uuid(), 'title' => 'Subject', 'sort_order' => 1, 'is_active' => true]);
        $course = Course::query()->create(['subject_id' => $subject->id, 'slug' => 'priced-'.Str::uuid(),
            'title' => 'Course', 'short_title' => 'Course', 'price_cents' => $amount, 'sort_order' => 1, 'is_active' => true]);
        Lesson::query()->create(['course_id' => $course->id, 'slug' => 'lesson', 'title' => 'Lesson', 'sort_order' => 1, 'is_active' => true]);
        $order = Order::query()->create(['user_id' => $user->id, 'course_id' => $course->id,
            'payment_status' => 'pending_payment', 'access_status' => 'not_granted', 'currency' => 'RUB',
            'amount_total' => $amount, 'amount_discount' => 0, 'amount_final' => $amount, 'amount_paid' => 0, 'amount_refunded' => 0]);
        app(OrderItemSnapshotService::class)->createCourseItem($order, $course);
        CoinLedgerEntry::query()->create(['user_id' => $user->id, 'event_type' => 'coins_accrual', 'units' => 200,
            'idempotency_key' => 'fixture:'.Str::uuid(), 'expires_at' => $expiresSoon ? now()->addMinutes(2) : null]);

        return [$user, $order->fresh()];
    }

    private function service(): CoinLedgerService
    {
        return app(CoinLedgerService::class);
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
