<?php

namespace Tests\Feature\Payments;

use App\Models\Order;
use App\Models\OrderSettlement;
use App\Models\OutboxEvent;
use App\Models\PaymentAuditLog;
use App\Models\PromoCode;
use App\Models\SideEffectExecution;
use App\Models\User;
use App\Services\Bonuses\AutoPurchasePromoService;
use App\Services\Payments\PaymentNotificationService;
use App\Services\Payments\PaymentNotificationTransport;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class PurchasePromoNotificationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        config(['payment_notifications.enabled' => true, 'payment_notifications.mailer' => 'smtp',
            'mail.mailers.smtp.transport' => 'smtp', 'mail.mailers.smtp.timeout' => 30,
            'mail.from.address' => 'payments@merchant.test']);
        $this->freezeTime();
    }

    public function test_code_is_only_decrypted_for_transport_and_never_persisted_in_notification(): void
    {
        [$effect, $code] = $this->fixture();
        $transport = Mockery::mock(PaymentNotificationTransport::class);
        $transport->shouldReceive('send')->once()->andReturnUsing(function (array $payload) use ($effect, $code): string {
            $this->assertSame(0, DB::transactionLevel());
            $this->assertSame('submitting', $effect->fresh()->status);
            $this->assertStringContainsString($code, $payload['body']);
            $this->assertStringContainsString('10%', $payload['body']);
            $this->assertSafe($effect, $code);

            return $payload['message_id'];
        });
        $service = new PaymentNotificationService($transport);
        $service->execute($effect->id);
        $service->execute($effect->id);
        $this->assertSame('completed', $effect->fresh()->status);
        $this->assertSafe($effect, $code);
    }

    public function test_unknown_acceptance_never_retries_or_persists_raw_transport_exception(): void
    {
        [$effect, $code] = $this->fixture();
        $transport = Mockery::mock(PaymentNotificationTransport::class);
        $transport->shouldReceive('send')->once()->andThrow(new RuntimeException('SMTP secret '.$code));
        $service = new PaymentNotificationService($transport);
        $service->execute($effect->id);
        $this->travel(3)->minutes();
        $service->execute($effect->id);
        $this->assertSame('unknown', $effect->fresh()->status);
        $this->assertSafe($effect, $code);
    }

    public function test_invalid_promo_source_owner_status_expiry_and_cipher_never_send(): void
    {
        foreach (['unverified', 'foreign', 'revoked', 'expired', 'cipher', 'hash', 'refunded'] as $failure) {
            [$effect, $code] = $this->fixture();
            $promo = PromoCode::query()->findOrFail($effect->meta['promo_code_id']);
            if ($failure === 'unverified') {
                $source = SideEffectExecution::query()->where('order_id', $effect->order_id)->where('handler', 'bonus.purchase_reward')->sole();
                $source->update(['meta' => [...$source->meta, 'verified' => false]]);
            } elseif ($failure === 'foreign') {
                $promo->update(['bound_email_hash' => str_repeat('f', 64)]);
            } elseif ($failure === 'revoked') {
                $promo->update(['status' => 'revoked']);
            } elseif ($failure === 'expired') {
                $this->travel(8)->days();
            } elseif ($failure === 'refunded') {
                Order::query()->whereKey($effect->order_id)->update(['payment_status' => 'refunded']);
            } else {
                $marker = PaymentAuditLog::query()->where('order_id', $effect->order_id)->where('action', 'auto_purchase_promo_applied')->sole();
                $marker->update(['meta' => [...$marker->meta, 'encrypted_code' => $failure === 'cipher' ? 'invalid' : Crypt::encryptString('WRONG_CODE')]]);
            }
            $transport = Mockery::mock(PaymentNotificationTransport::class);
            $transport->shouldNotReceive('send');
            (new PaymentNotificationService($transport))->execute($effect->id);
            $this->assertSame('manual_review', $effect->fresh()->status, $failure);
            $this->assertSafe($effect, $code);
        }
    }

    private function assertSafe(SideEffectExecution $effect, string $code): void
    {
        $this->assertArrayNotHasKey('body', $effect->fresh()->meta['payload'] ?? []);
        $this->assertStringNotContainsString($code, json_encode(SideEffectExecution::query()->get()->toArray(), JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString($code, json_encode(PaymentAuditLog::query()->get()->toArray(), JSON_THROW_ON_ERROR));
    }

    private function fixture(): array
    {
        $user = User::factory()->create();
        $order = Order::query()->create(['user_id' => $user->id, 'currency' => 'RUB', 'payment_status' => 'paid',
            'amount_total' => 100000, 'amount_final' => 100000, 'amount_paid' => 100000, 'paid_at' => now()]);
        OrderSettlement::query()->create(['order_id' => $order->id, 'settlement_type' => 'provider_payment',
            'idempotency_key' => 'settle:'.$order->id, 'amount' => 100000, 'currency' => 'RUB', 'status' => 'confirmed',
            'confirmed_at' => now(), 'fingerprint_hash' => str_repeat('a', 64), 'metadata' => ['grant_status' => 'granted']]);
        $issued = app(AutoPurchasePromoService::class)->apply($order->id);
        $marker = PaymentAuditLog::query()->where('order_id', $order->id)->where('action', 'auto_purchase_promo_applied')->sole();
        $event = OutboxEvent::query()->create(['order_id' => $order->id, 'event_type' => 'payment.transition.applied',
            'status' => 'pending', 'payload' => ['from_status' => 'pending_payment', 'to_status' => 'paid'], 'available_at' => now()]);
        SideEffectExecution::query()->create(['order_id' => $order->id, 'outbox_event_id' => $event->id,
            'handler' => 'bonus.purchase_reward', 'dedup_key' => "order:{$order->id}:bonus:reward", 'status' => 'completed',
            'processed_at' => now(), 'meta' => ['delivery_version' => 1, 'verified' => true, 'results' => [[], $issued]]]);
        $effect = SideEffectExecution::query()->create(['order_id' => $order->id, 'outbox_event_id' => $event->id,
            'handler' => 'user_notification.purchase_promo', 'dedup_key' => "order:{$order->id}:notify:purchase_promo", 'status' => 'pending',
            'processed_at' => now(), 'meta' => ['delivery_version' => 1, 'promo_code_id' => $issued['promo_code_id']]]);

        return [$effect, Crypt::decryptString($marker->meta['encrypted_code'])];
    }
}
