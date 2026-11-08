<?php

namespace Tests\Feature\Payments;

use App\Models\CoinLedgerEntry;
use App\Models\Order;
use App\Models\User;
use App\Services\Bonuses\CoinLedgerService;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CoinLedgerAdjustmentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        $this->travelTo(now()->startOfSecond());
    }

    public function test_returned_spend_restores_only_original_unexpired_lot(): void
    {
        [$user, $order, $lot] = $this->fixture();
        $service = app(CoinLedgerService::class);
        $reserve = $service->reserve($user->id, $order->id, 40, 'spend');
        $service->commit($user->id, $reserve->id);
        $this->assertSame(60, $service->balance($user->id));
        $this->entry($user->id, $order->id, $lot->id, 'coins_refund', 40, $reserve->id);
        $this->assertSame(100, $service->balance($user->id));
        $this->travel(2)->days();
        $this->assertSame(0, $service->balance($user->id));
    }

    public function test_revoked_lot_is_not_restored_by_return_and_cannot_commit_existing_reserve(): void
    {
        [$user, $order, $lot] = $this->fixture();
        $service = app(CoinLedgerService::class);
        $reserve = $service->reserve($user->id, $order->id, 40, 'revoked');
        $this->entry($user->id, $order->id, $lot->id, 'coins_revoke', 100);
        $this->assertSame(0, $service->balance($user->id));
        try {
            $service->commit($user->id, $reserve->id);
            $this->fail('Expected revoked lot rejection');
        } catch (DomainException $exception) {
            $this->assertSame('BONUS_LEDGER_INCONSISTENT', $exception->getMessage());
        }
        $service->release($user->id, $reserve->id, 'abandoned');
        $this->assertSame(0, $service->balance($user->id));
        $this->assertSame(0, CoinLedgerEntry::query()->where('event_type', 'coins_commit')->count());
    }

    public function test_debt_repayment_uses_new_earning_lot_and_debt_itself_does_not_create_money(): void
    {
        [$user, $order, $lot] = $this->fixture();
        $this->entry($user->id, $order->id, $lot->id, 'coins_debt', 40);
        $this->assertSame(100, app(CoinLedgerService::class)->balance($user->id));
        $this->entry($user->id, $order->id, $lot->id, 'coins_debt_repay', 40);
        $this->assertSame(60, app(CoinLedgerService::class)->balance($user->id));
    }

    private function fixture(): array
    {
        $user = User::factory()->create();
        $order = Order::query()->create(['user_id' => $user->id, 'payment_status' => 'pending_payment',
            'access_status' => 'not_granted', 'currency' => 'RUB', 'amount_total' => 10000, 'amount_discount' => 0,
            'amount_final' => 10000, 'amount_paid' => 0, 'amount_refunded' => 0]);
        $order->items()->create(['sellable_kind' => 'course', 'sellable_id' => 1, 'sellable_sku' => 'fixture:course',
            'purchased_entity_type' => 'course', 'purchased_entity_id' => 1, 'display_title' => 'Fixture course',
            'quantity' => 1, 'currency' => 'RUB', 'unit_amount' => 10000, 'subtotal_amount' => 10000,
            'discount_amount' => 0, 'final_amount' => 10000, 'snapshot_hash' => str_repeat('a', 64),
            'grant_snapshot' => ['atoms' => []]]);
        $lot = CoinLedgerEntry::query()->create(['user_id' => $user->id, 'event_type' => 'coins_accrual', 'units' => 100,
            'idempotency_key' => 'lot:'.Str::uuid(), 'expires_at' => now()->addDay()]);

        return [$user, $order, $lot];
    }

    private function entry(int $user, int $order, int $lot, string $type, int $units, ?int $reservation = null): void
    {
        CoinLedgerEntry::query()->create(['user_id' => $user, 'order_id' => $order, 'lot_id' => $lot,
            'reservation_id' => $reservation, 'event_type' => $type, 'units' => $units, 'idempotency_key' => 'event:'.Str::uuid()]);
    }
}
