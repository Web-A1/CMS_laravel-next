<?php

namespace App\Services\Payments;

use App\Contracts\Payments\PaymentProviderAdapter;

class PaymentProviderRegistry
{
    public function forProvider(string $provider): ?PaymentProviderAdapter
    {
        $provider = strtolower(trim($provider));
        if ($provider === 'test_provider' && ! app()->environment(['local', 'testing'])) {
            return null;
        }
        if ($provider === 'yandex_split') {
            return app(YandexSplitClient::class)->configured()
                ? new YandexSplitPaymentProviderAdapter($provider, '')
                : null;
        }
        if ($provider === 'podeli') {
            return app(PodeliClient::class)->configured()
                ? new PodeliPaymentProviderAdapter($provider, '')
                : null;
        }
        if ($provider === 'robokassa') {
            $adapter = new RobokassaPaymentProviderAdapter($provider, '');
            try {
                $adapter->assertConfigured();
            } catch (\DomainException) {
                return null;
            }

            return $adapter;
        }
        $config = config("services.payments.providers.{$provider}", []);
        $secret = (string) ($config['webhook_secret'] ?? '');

        if ($provider === 'yandex_split' && $secret === '') {
            $secret = (string) ($config['webhook_public_key'] ?? '');
        }

        if ($provider === 'cloudpayments') {
            if ($secret === '') {
                return null;
            }

            return new CloudpaymentsPaymentProviderAdapter($provider, $secret, (array) $config);
        }

        if ($provider === 'test_provider' && $secret === '') {
            return new TestProviderAdapter($provider, $secret);
        }

        if ($secret === '') {
            return null;
        }

        return match ($provider) {
            'podeli' => new PodeliPaymentProviderAdapter($provider, $secret),
            'yandex_split' => new YandexSplitPaymentProviderAdapter($provider, $secret),
            'robokassa' => new RobokassaPaymentProviderAdapter($provider, $secret),
            'test_provider' => new TestProviderAdapter($provider, $secret),
            default => null,
        };
    }
}
