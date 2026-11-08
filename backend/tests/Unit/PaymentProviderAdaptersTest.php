<?php

namespace Tests\Unit;

use App\Services\Payments\CloudpaymentsPaymentProviderAdapter;
use App\Services\Payments\PaymentProviderRegistry;
use App\Services\Payments\PodeliPaymentProviderAdapter;
use App\Services\Payments\RobokassaPaymentProviderAdapter;
use App\Services\Payments\TestProviderAdapter;
use App\Services\Payments\YandexSplitPaymentProviderAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PaymentProviderAdaptersTest extends TestCase
{
    use RefreshDatabase;

    public function test_registry_returns_provider_specific_adapter(): void
    {
        config(['yandex_split.enabled' => true, 'yandex_split.sandbox' => true, 'yandex_split.merchant_id' => 'merchant-test']);
        config(['robokassa.enabled' => true, 'robokassa.merchant_login' => 'merchant-test',
            'robokassa.password1' => 'password1', 'robokassa.password2' => 'password2']);
        config(['services.payments.providers.cloudpayments.webhook_secret' => 'secret-4']);

        $registry = new PaymentProviderRegistry;

        $this->assertInstanceOf(YandexSplitPaymentProviderAdapter::class, $registry->forProvider('yandex_split'));
        $this->assertNull($registry->forProvider('podeli'));
        $this->assertInstanceOf(RobokassaPaymentProviderAdapter::class, $registry->forProvider('robokassa'));
        $this->assertInstanceOf(CloudpaymentsPaymentProviderAdapter::class, $registry->forProvider('cloudpayments'));
    }

    public function test_hmac_signature_verification_works(): void
    {
        $adapter = new TestProviderAdapter('test_provider', 'top-secret');
        $rawPayload = '{"event_id":"evt-1","status":"paid"}';
        $signature = hash_hmac('sha256', $rawPayload, 'top-secret');

        $this->assertTrue($adapter->verifyWebhookSignature($rawPayload, $signature));
        $this->assertFalse($adapter->verifyWebhookSignature($rawPayload, 'bad-signature'));
    }

    public function test_test_provider_is_unavailable_outside_local_and_testing(): void
    {
        $this->app->instance('env', 'production');
        $this->assertNull((new PaymentProviderRegistry)->forProvider('test_provider'));
    }

    public function test_unconfigured_providers_cannot_create_payment_sessions(): void
    {
        Http::preventStrayRequests();
        foreach ([
            [new RobokassaPaymentProviderAdapter('robokassa', ''), 'ROBOKASSA_NOT_CONFIGURED'],
            [new YandexSplitPaymentProviderAdapter('yandex_split', ''), 'PAYMENT_PROVIDER_NOT_CONFIGURED'],
            [new PodeliPaymentProviderAdapter('podeli', ''), 'PAYMENT_PROVIDER_NOT_CONFIGURED'],
        ] as [$adapter, $error]) {
            try {
                $adapter->startPayment([]);
                $this->fail('Unconfigured provider must fail closed.');
            } catch (\DomainException $exception) {
                $this->assertSame($error, $exception->getMessage());
            }
        }
        Http::assertNothingSent();
    }

    public function test_cloudpayments_start_payment_returns_widget_payload_without_charging(): void
    {
        $adapter = new CloudpaymentsPaymentProviderAdapter('cloudpayments', 'webhook-secret', [
            'public_id' => 'pk_test_123',
        ]);

        $session = $adapter->startPayment([
            'order_id' => 42,
            'attempt_id' => 7,
            'amount' => 150000,
            'currency' => 'RUB',
            'return_url' => 'https://school.example/return',
            'cancel_url' => 'https://school.example/cancel',
        ]);

        $this->assertNull($session['provider_payment_id']);
        $this->assertNull($session['provider_payment_url']);
        $this->assertSame('widget', $session['provider_substatus']['mode']);
        $this->assertSame('pk_test_123', $session['provider_substatus']['public_id']);
        $this->assertSame('cp-42-7', $session['provider_substatus']['invoice_id']);
        $this->assertSame('1500.00', $session['provider_substatus']['amount']);
    }

    public function test_status_mapping_for_yandex_split_contract(): void
    {
        $adapter = new YandexSplitPaymentProviderAdapter('yandex_split', 'secret');

        $completed = $adapter->mapProviderStatus([
            'provider_event_id' => 'evt-1',
            'event_type' => 'payment',
            'provider_status' => 'CAPTURED',
            'raw_payload' => [],
        ]);
        $this->assertSame('paid', $completed['target_payment_status']);
        $this->assertSame('completed', $completed['target_attempt_status']);

        $cancelled = $adapter->mapProviderStatus([
            'provider_event_id' => 'evt-2',
            'event_type' => 'payment',
            'provider_status' => 'FAILED',
            'raw_payload' => [],
        ]);
        $this->assertSame('cancelled', $cancelled['target_payment_status']);

        $pending = $adapter->mapProviderStatus([
            'provider_event_id' => 'evt-3',
            'event_type' => 'payment',
            'provider_status' => 'PENDING',
            'raw_payload' => [],
        ]);
        $this->assertNull($pending['target_payment_status']);
        $this->assertSame('pending', $pending['target_attempt_status']);
    }

    public function test_podeli_rejects_untyped_callbacks(): void
    {
        $adapter = new PodeliPaymentProviderAdapter('podeli', 'secret');
        $this->expectExceptionMessage('PAYMENT_PODELI_TYPED_WEBHOOK_REQUIRED');
        $adapter->parseWebhookEvent([
            'event_id' => 'evt-10',
            'event_type' => 'payment',
            'status' => 'COMPLETED',
        ]);
    }

    public function test_robokassa_signature_uses_password2_and_result_fields(): void
    {
        config(['robokassa.enabled' => true, 'robokassa.merchant_login' => 'merchant-test',
            'robokassa.password1' => 'password1', 'robokassa.password2' => 'password2']);
        $adapter = new RobokassaPaymentProviderAdapter('robokassa', 'password2');
        $raw = http_build_query(['OutSum' => '1000.00', 'InvId' => '42']);
        $signature = md5('1000.00:42:password2');

        $this->assertTrue($adapter->verifyWebhookSignature($raw, $signature));
        $parsed = $adapter->parseWebhookEvent(['OutSum' => '1000.00', 'InvId' => '42']);
        $this->assertSame(100000, (int) $parsed['amount']);
    }
}
