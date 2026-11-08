<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OutboxEvent;
use App\Models\Refund;
use App\Models\SideEffectExecution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PaymentHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_history_requires_authentication_and_ignores_foreign_user_id(): void
    {
        $this->getJson('/api/v1/payments/history')->assertUnauthorized();
        $owner = User::factory()->create(['role' => 'user_no_purchase']);
        $other = User::factory()->create(['role' => 'user_no_purchase']);
        $own = $this->order($owner);
        $foreign = $this->order($other);
        Sanctum::actingAs($owner);
        $this->getJson('/api/v1/payments/history?user_id='.$other->id)->assertOk()
            ->assertJsonCount(1, 'orders')->assertJsonPath('orders.0.id', $own->id)
            ->assertJsonMissing(['id' => $foreign->id]);
    }

    public function test_cursor_paging_does_not_duplicate_orders(): void
    {
        $owner = User::factory()->create(['role' => 'user_no_purchase']);
        for ($index = 0; $index < 23; $index++) {
            $this->order($owner);
        }
        Sanctum::actingAs($owner);
        $first = $this->getJson('/api/v1/payments/history')->assertOk()->assertJsonCount(20, 'orders')->json();
        $second = $this->getJson('/api/v1/payments/history?before_id='.$first['next_before_id'])
            ->assertOk()->assertJsonCount(3, 'orders')->assertJsonPath('next_before_id', null)->json();
        $this->assertSame([], array_intersect(array_column($first['orders'], 'id'), array_column($second['orders'], 'id')));
    }

    public function test_history_distinguishes_pending_refund_and_verified_receipt_without_private_metadata(): void
    {
        $owner = User::factory()->create(['role' => 'user_no_purchase']);
        $order = $this->order($owner);
        $event = OutboxEvent::query()->create(['order_id' => $order->id, 'event_type' => 'payment.transition.applied', 'status' => 'pending', 'payload' => []]);
        Refund::query()->create(['order_id' => $order->id, 'refund_type' => 'full', 'amount' => 10000,
            'currency' => 'RUB', 'status' => 'submitted', 'reason' => 'private staff reason', 'policy_access_action' => 'revoke']);
        foreach ([['handler' => 'receipt.income.issue', 'meta' => ['legacy_private' => 'private token']],
            ['handler' => 'receipt.refund.issue', 'meta' => ['delivery_version' => 1, 'verified' => true, 'payload' => ['secret' => 'private token']]]] as $index => $receipt) {
            SideEffectExecution::query()->create(['order_id' => $order->id, 'outbox_event_id' => $event->id, 'handler' => $receipt['handler'],
                'status' => 'completed', 'dedup_key' => 'history-receipt-'.$index, 'meta' => $receipt['meta'], 'processed_at' => now()]);
        }
        Sanctum::actingAs($owner);
        $response = $this->getJson('/api/v1/payments/history')->assertOk()
            ->assertJsonPath('orders.0.payment_status', 'paid')->assertJsonPath('orders.0.refunded', 0)
            ->assertJsonPath('orders.0.refunds.0.status', 'submitted')
            ->assertJsonPath('orders.0.receipts.0.status', 'pending_verification')
            ->assertJsonPath('orders.0.receipts.1.status', 'issued');
        $this->assertStringNotContainsString('private', $response->getContent());
        $this->assertStringNotContainsString('payload', $response->getContent());
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
    }

    private function order(User $owner): Order
    {
        return Order::query()->create(['user_id' => $owner->id, 'currency' => 'RUB', 'amount_total' => 10000,
            'amount_final' => 10000, 'amount_paid' => 10000, 'amount_refunded' => 0,
            'payment_status' => 'paid', 'access_status' => 'granted']);
    }
}
