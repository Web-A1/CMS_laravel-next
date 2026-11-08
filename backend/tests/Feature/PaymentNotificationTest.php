<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OutboxEvent;
use App\Models\SideEffectExecution;
use App\Models\User;
use App\Services\Payments\PaymentNotificationService;
use App\Services\Payments\PaymentNotificationTransport;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class PaymentNotificationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        Artisan::call('migrate', ['--force' => true]);
        config(['payment_notifications.enabled' => true, 'payment_notifications.mailer' => 'smtp',
            'mail.mailers.smtp.transport' => 'smtp', 'mail.mailers.smtp.timeout' => 30,
            'mail.from.address' => 'payments@merchant.test']);
        $this->freezeTime();
    }

    public function test_acceptance_is_recorded_only_after_send_outside_transaction_and_not_sent_twice(): void
    {
        $effect = $this->effect();
        $transport = Mockery::mock(PaymentNotificationTransport::class);
        $transport->shouldReceive('send')->once()->andReturnUsing(function (array $payload) use ($effect): string {
            $this->assertSame(0, DB::transactionLevel());
            $this->assertSame('submitting', $effect->fresh()->status);
            $this->assertSame('buyer@example.test', $payload['recipient']);
            $this->assertStringContainsString('100,00 RUB', $payload['body']);
            $this->assertStringContainsString('@merchant.test', $payload['message_id']);

            return $payload['message_id'];
        });
        $service = new PaymentNotificationService($transport);
        $service->execute($effect->id);
        $service->execute($effect->id);
        $this->assertSame('completed', $effect->fresh()->status);
        $this->assertSame('transport_accepted', $effect->fresh()->meta['acceptance']);
        $this->assertNotNull($effect->fresh()->processed_at);
    }

    public function test_unknown_transport_result_is_never_automatically_resent(): void
    {
        $effect = $this->effect();
        $transport = Mockery::mock(PaymentNotificationTransport::class);
        $transport->shouldReceive('send')->once()->andThrow(new RuntimeException('private SMTP details'));
        $service = new PaymentNotificationService($transport);
        $service->execute($effect->id);
        $this->travel(3)->minutes();
        $service->execute($effect->id);
        $this->assertSame('unknown', $effect->fresh()->status);
        $this->assertStringNotContainsString('private SMTP', json_encode($effect->fresh()->meta));
    }

    public function test_crash_after_claim_expires_stays_unknown_without_send(): void
    {
        $effect = $this->effect();
        $effect->update(['status' => 'submitting', 'meta' => ['delivery_version' => 1, 'claim_until' => now()->subSecond()->timestamp]]);
        $transport = Mockery::mock(PaymentNotificationTransport::class);
        $transport->shouldNotReceive('send');
        (new PaymentNotificationService($transport))->execute($effect->id);
        $this->assertSame('unknown', $effect->fresh()->status);
    }

    public function test_disabled_and_log_transports_never_count_as_sent(): void
    {
        $effect = $this->effect();
        $transport = Mockery::mock(PaymentNotificationTransport::class);
        $transport->shouldNotReceive('send');
        $service = new PaymentNotificationService($transport);
        config(['payment_notifications.enabled' => false]);
        $service->execute($effect->id);
        $this->assertSame('blocked_configuration', $effect->fresh()->status);
        config(['payment_notifications.enabled' => true, 'mail.mailers.smtp.transport' => 'log']);
        $service->execute($effect->id);
        $this->assertSame('blocked_configuration', $effect->fresh()->status);
    }

    public function test_legacy_marker_and_incomplete_payment_do_not_send(): void
    {
        $effect = $this->effect();
        $transport = Mockery::mock(PaymentNotificationTransport::class);
        $transport->shouldNotReceive('send');
        $service = new PaymentNotificationService($transport);
        $effect->update(['meta' => []]);
        $service->execute($effect->id);
        $this->assertSame('pending', $effect->fresh()->status);
        $effect->update(['meta' => ['delivery_version' => 1]]);
        Order::query()->whereKey($effect->order_id)->update(['paid_at' => null]);
        $service->execute($effect->id);
        $this->assertSame('blocked_notification_data', $effect->fresh()->status);
    }

    public function test_reentrant_worker_does_not_resend_and_expired_claim_response_cannot_override_unknown(): void
    {
        $effect = $this->effect();
        $transport = Mockery::mock(PaymentNotificationTransport::class);
        $service = new PaymentNotificationService($transport);
        $transport->shouldReceive('send')->once()->andReturnUsing(function (array $payload) use ($effect, $service): string {
            $service->execute($effect->id);
            $this->assertSame('submitting', $effect->fresh()->status);
            $this->travel(121)->seconds();
            $service->execute($effect->id);
            $this->assertSame('unknown', $effect->fresh()->status);

            return $payload['message_id'];
        });
        $service->execute($effect->id);
        $this->assertSame('unknown', $effect->fresh()->status);
        $this->assertArrayNotHasKey('acceptance', $effect->fresh()->meta);
    }

    public function test_transport_preserves_message_identity_without_external_delivery(): void
    {
        $id = (new PaymentNotificationTransport)->send([
            'mailer' => 'array', 'recipient' => 'buyer@example.test', 'sender' => 'payments@merchant.test',
            'subject' => 'Test acceptance', 'body' => 'Test message', 'message_id' => 'stable-id@merchant.test',
        ]);
        $this->assertSame('stable-id@merchant.test', $id);
    }

    private function effect(): SideEffectExecution
    {
        $user = User::factory()->create(['email' => 'buyer@example.test']);
        $order = Order::query()->create(['user_id' => $user->id, 'currency' => 'RUB',
            'amount_total' => 10000, 'amount_final' => 10000, 'amount_paid' => 10000,
            'payment_status' => 'paid', 'access_status' => 'granted', 'paid_at' => now()]);
        $event = OutboxEvent::query()->create(['order_id' => $order->id,
            'event_type' => 'payment.transition', 'status' => 'pending', 'payload' => []]);

        return SideEffectExecution::query()->create(['order_id' => $order->id, 'outbox_event_id' => $event->id,
            'handler' => 'user_notification.payment_success', 'dedup_key' => 'order:'.$order->id.':notify:payment_success',
            'status' => 'pending', 'meta' => ['delivery_version' => 1]]);
    }
}
