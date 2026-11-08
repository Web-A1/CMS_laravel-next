<?php

namespace Tests\Feature\Payments;

use App\Jobs\ProcessOutboxEventJob;
use App\Jobs\ProcessPaymentSideEffectJob;
use App\Models\Order;
use App\Models\OutboxEvent;
use App\Models\Refund;
use App\Models\SideEffectExecution;
use App\Services\Payments\FiscalReceiptService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FiscalReceiptTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        Http::preventStrayRequests();
        config(['fiscal' => ['enabled' => true, 'exclusive_issuer_confirmed' => true,
            'public_id' => 'fixture', 'api_secret' => 'fixture-secret', 'version' => 'test-v1',
            'inn' => '1234567890', 'taxation_system' => '1', 'vat' => 'none', 'method' => '4', 'object' => '4']]);
    }

    public function test_missing_configuration_and_mixed_payment_never_send(): void
    {
        $effect = $this->effect();
        config(['fiscal.vat' => null]);
        $this->runEffect($effect);
        $this->assertSame('blocked_configuration', $effect->fresh()->status);
        config(['fiscal.vat' => 'none']);
        Order::query()->whereKey($effect->order_id)->update(['settlement_type' => 'paid_by_coins']);
        $this->runEffect($effect);
        $this->assertSame('blocked_fiscal_qualification', $effect->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_sync_worker_does_not_recursively_dispatch_queued_poll(): void
    {
        config(['queue.default' => 'sync']);
        $effect = $this->effect();
        Http::fake(['*' => Http::response(['Success' => true, 'Model' => ['Id' => 'receipt-1']])]);
        (new ProcessPaymentSideEffectJob($effect->id))->handle(app(FiscalReceiptService::class));
        $this->assertSame('submitted', $effect->fresh()->status);
        Http::assertSentCount(1);
        Bus::assertNotDispatched(ProcessPaymentSideEffectJob::class);
    }

    public function test_poll_enqueue_failure_preserves_submitted_receipt_for_outbox_recovery(): void
    {
        config(['queue.default' => 'database']);
        $effect = $this->effect();
        $bus = Bus::getFacadeRoot();
        Http::fake(['*' => Http::response(['Success' => true, 'Model' => ['Id' => 'receipt-1']])]);
        Bus::shouldReceive('dispatch')->once()->andThrow(new \RuntimeException('enqueue unavailable'));
        try {
            (new ProcessPaymentSideEffectJob($effect->id))->handle(app(FiscalReceiptService::class));
            $this->fail('Enqueue failure must remain visible.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('enqueue unavailable', $exception->getMessage());
        }
        $this->assertSame('submitted', $effect->fresh()->status);
        $this->assertSame('receipt-1', $effect->fresh()->meta['provider_receipt_id']);
        Bus::swap($bus);
        (new ProcessOutboxEventJob($effect->outbox_event_id))->handle();
        Bus::assertDispatched(ProcessPaymentSideEffectJob::class, fn ($job) => $job->executionId === $effect->id);
        Http::assertSentCount(1);
    }

    public function test_unconfigured_notification_remains_blocked_and_duplicate_event_waits(): void
    {
        $effect = $this->effect();
        $event = OutboxEvent::query()->findOrFail($effect->outbox_event_id);
        (new ProcessOutboxEventJob($event->id))->handle();
        $notification = SideEffectExecution::query()->where('handler', 'user_notification.payment_success')->firstOrFail();
        (new ProcessPaymentSideEffectJob($notification->id))->handle(app(FiscalReceiptService::class));
        $this->assertSame('blocked_configuration', $notification->fresh()->status);
        $duplicate = $event->replicate();
        $duplicate->save();
        (new ProcessOutboxEventJob($duplicate->id))->handle();
        $this->assertSame('pending', $duplicate->fresh()->status);
        $this->assertDatabaseCount('side_effect_executions', 4);
    }

    public function test_queued_is_not_completed_and_validated_details_complete_without_recreating(): void
    {
        $effect = $this->effect();
        Http::fake([
            '*/receipt' => Http::response(['Success' => true, 'Model' => ['Id' => 'receipt-1']]),
            '*/status/get' => Http::sequence()->push(['Success' => true, 'Model' => 'Queued'])->push(['Success' => true, 'Model' => 'Processed']),
            '*/receipt/get' => Http::response(['Success' => true, 'Model' => ['AdditionalData' => $this->evidence($effect)]]),
        ]);
        $this->runEffect($effect);
        $this->assertSame('submitted', $effect->fresh()->status);
        $this->runEffect($effect);
        $this->assertSame('submitted', $effect->fresh()->status);
        $this->runEffect($effect);
        $this->assertSame('completed', $effect->fresh()->status);
        $this->assertTrue($effect->fresh()->meta['verified']);
        $this->runEffect($effect);
        Http::assertSentCount(4);
    }

    public function test_each_identity_mismatch_and_unfiscalized_detail_rejects(): void
    {
        foreach (['InvoiceId', 'OrganizationInn', 'Type', 'Amount', 'FiscalSign', 'Id'] as $field) {
            $effect = $this->effect();
            $evidence = $this->evidence($effect);
            $evidence[$field] = $field === 'Amount' ? 999 : '';
            Http::fake([
                '*/receipt' => Http::response(['Success' => true, 'Model' => ['Id' => 'receipt-1']]),
                '*/status/get' => Http::response(['Success' => true, 'Model' => 'Processed']),
                '*/receipt/get' => Http::response(['Success' => true, 'Model' => ['AdditionalData' => $evidence]]),
            ]);
            $this->runEffect($effect);
            $this->runEffect($effect);
            $this->assertSame('manual_review', $effect->fresh()->status, $field);
        }
    }

    public function test_timeout_and_expired_claim_never_reissue(): void
    {
        $effect = $this->effect();
        Http::fake(fn () => throw new ConnectionException('secret must not be copied'));
        $this->runEffect($effect);
        $this->assertSame('unknown', $effect->fresh()->status);
        $this->assertStringNotContainsString('secret must', json_encode($effect->fresh()->meta));
        $this->travel(2)->hours();
        Http::fake();
        $this->runEffect($effect);
        $expired = $this->effect();
        $expired->update(['status' => 'submitting', 'meta' => ['claim_until' => now()->subMinute()->timestamp]]);
        $this->runEffect($expired);
        $this->assertSame('unknown', $expired->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_claim_prevents_reentrant_dispatch_and_payload_is_durable_before_http(): void
    {
        $effect = $this->effect();
        $calls = 0;
        Http::fake(function () use ($effect, &$calls) {
            $calls++;
            $saved = $effect->fresh();
            $this->assertSame('submitting', $saved->status);
            $this->assertNotEmpty($saved->meta['payload_hash']);
            $this->runEffect($effect);

            return Http::response(['Success' => true, 'Model' => ['Id' => 'receipt-1']]);
        });
        $this->runEffect($effect);
        $this->assertSame(1, $calls);
    }

    public function test_expired_claim_prevents_stale_response_overwrite(): void
    {
        $effect = $this->effect();
        Http::fake(function () use ($effect) {
            $this->travel(61)->seconds();
            $this->runEffect($effect);

            return Http::response(['Success' => true, 'Model' => ['Id' => 'stale-receipt']]);
        });
        $this->runEffect($effect);
        $this->assertSame('unknown', $effect->fresh()->status);
        $this->assertArrayNotHasKey('provider_receipt_id', $effect->fresh()->meta);
    }

    public function test_provider_error_and_notfound_are_not_successful_receipts(): void
    {
        foreach (['Error', 'NotFound'] as $state) {
            $effect = $this->effect();
            Http::fake([
                '*/receipt' => Http::response(['Success' => true, 'Model' => ['Id' => 'receipt-1']]),
                '*/status/get' => Http::response(['Success' => true, 'Model' => $state]),
            ]);
            $this->runEffect($effect);
            $this->runEffect($effect);
            $this->assertSame('manual_review', $effect->fresh()->status);
        }
    }

    public function test_refund_uses_verified_income_snapshot_after_configuration_change(): void
    {
        $income = $this->effect();
        Http::fake([
            '*/receipt' => Http::response(['Success' => true, 'Model' => ['Id' => 'receipt-1']]),
            '*/status/get' => Http::response(['Success' => true, 'Model' => 'Processed']),
            '*/receipt/get' => Http::response(['Success' => true, 'Model' => ['AdditionalData' => $this->evidence($income)]]),
        ]);
        $this->runEffect($income);
        $this->runEffect($income);
        $refund = Refund::query()->create(['order_id' => $income->order_id, 'refund_type' => 'full', 'amount' => 10000,
            'currency' => 'RUB', 'status' => 'completed', 'policy_access_action' => 'revoke']);
        $effect = SideEffectExecution::query()->create(['outbox_event_id' => $income->outbox_event_id, 'order_id' => $income->order_id,
            'refund_id' => $refund->id, 'handler' => 'receipt.refund.issue', 'dedup_key' => 'refund-'.$refund->id,
            'status' => 'pending', 'meta' => ['delivery_version' => 1], 'processed_at' => now()]);
        config(['fiscal.vat' => '22', 'fiscal.inn' => '9876543210']);
        $this->runEffect($effect);
        $payload = $effect->fresh()->meta['payload'];
        $this->assertSame('IncomeReturn', $payload['Type']);
        $this->assertSame('1234567890', $payload['Inn']);
        $this->assertNull($payload['CustomerReceipt']['Items'][0]['Vat']);
    }

    public function test_refund_waits_for_income_then_resumes_without_manual_intervention(): void
    {
        $income = $this->effect();
        $refund = Refund::query()->create(['order_id' => $income->order_id, 'refund_type' => 'full', 'amount' => 10000,
            'currency' => 'RUB', 'status' => 'completed', 'policy_access_action' => 'revoke']);
        $effect = SideEffectExecution::query()->create(['outbox_event_id' => $income->outbox_event_id, 'order_id' => $income->order_id,
            'refund_id' => $refund->id, 'handler' => 'receipt.refund.issue', 'dedup_key' => 'refund-'.$refund->id,
            'status' => 'pending', 'meta' => ['delivery_version' => 1], 'processed_at' => now()]);
        $this->runEffect($effect);
        $this->assertSame('waiting_income_receipt', $effect->fresh()->status);
        Http::assertNothingSent();
        Http::fake([
            '*/receipt' => Http::sequence()->push(['Success' => true, 'Model' => ['Id' => 'receipt-1']])->push(['Success' => true, 'Model' => ['Id' => 'return-1']]),
            '*/status/get' => Http::response(['Success' => true, 'Model' => 'Processed']),
            '*/receipt/get' => Http::response(['Success' => true, 'Model' => ['AdditionalData' => $this->evidence($income)]]),
        ]);
        $this->runEffect($income);
        $this->runEffect($effect);
        $this->assertSame('waiting_income_receipt', $effect->fresh()->status);
        Http::assertSentCount(1);
        $this->runEffect($income);
        $this->assertSame('completed', $income->fresh()->status);
        $this->runEffect($effect);
        $this->assertSame('submitted', $effect->fresh()->status);
        $this->assertSame('return-1', $effect->fresh()->meta['provider_receipt_id']);
        $this->assertSame('IncomeReturn', $effect->fresh()->meta['payload']['Type']);
        Http::assertSentCount(4);
    }

    public function test_outbox_materializes_honestly_and_does_not_reissue_legacy_marker(): void
    {
        $effect = $this->effect();
        $effect->update(['status' => 'completed', 'meta' => []]);
        $event = OutboxEvent::query()->findOrFail($effect->outbox_event_id);
        (new ProcessOutboxEventJob($event->id))->handle();
        $this->assertSame('pending', $event->fresh()->status);
        $this->assertSame('legacy_unverified', $event->fresh()->payload['delivery_state']);
        $this->assertSame([], $effect->fresh()->meta);
        $this->runEffect($effect);
        Http::assertNothingSent();
    }

    private function runEffect(SideEffectExecution $effect): void
    {
        app(FiscalReceiptService::class)->execute($effect->id);
    }

    private function evidence(SideEffectExecution $effect): array
    {
        return ['Id' => 'receipt-1', 'InvoiceId' => (string) $effect->order_id, 'OrganizationInn' => '1234567890',
            'Type' => 'Income', 'Amount' => 100, 'DocumentNumber' => '1', 'FiscalNumber' => '2', 'FiscalSign' => '3'];
    }

    private function effect(): SideEffectExecution
    {
        $order = Order::query()->create(['amount_total' => 10000, 'amount_final' => 10000, 'amount_paid' => 10000,
            'payment_status' => 'paid', 'settlement_type' => 'provider_payment', 'currency' => 'RUB']);
        $order->items()->create(['sellable_kind' => 'course', 'sellable_id' => 1, 'purchased_entity_type' => 'course',
            'purchased_entity_id' => 1, 'display_title' => 'Stored course', 'quantity' => 1, 'unit_amount' => 10000,
            'subtotal_amount' => 10000, 'final_amount' => 10000, 'snapshot_hash' => str_repeat('a', 64), 'grant_snapshot' => []]);
        $order->settlements()->create(['settlement_type' => 'provider_payment', 'idempotency_key' => 'settlement-'.$order->id,
            'amount' => 10000, 'currency' => 'RUB', 'status' => 'confirmed', 'fingerprint_hash' => str_repeat('a', 64)]);
        $event = OutboxEvent::query()->create(['event_type' => 'payment.transition.applied', 'order_id' => $order->id,
            'status' => 'pending', 'payload' => ['from_status' => 'pending_payment', 'to_status' => 'paid'], 'available_at' => now()]);

        return SideEffectExecution::query()->create(['outbox_event_id' => $event->id, 'order_id' => $order->id,
            'handler' => 'receipt.income.issue', 'dedup_key' => "order:{$order->id}:receipt:income", 'status' => 'pending',
            'meta' => ['delivery_version' => 1], 'processed_at' => now()]);
    }
}
