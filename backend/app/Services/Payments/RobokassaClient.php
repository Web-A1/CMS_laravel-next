<?php

namespace App\Services\Payments;

use DomainException;
use Illuminate\Support\Facades\Http;
use Throwable;

class RobokassaClient
{
    public function operation(string $invoice): array
    {
        $this->configured();
        if (config('robokassa.test_mode')) {
            throw new DomainException('ROBOKASSA_TEST_LOOKUP_UNSUPPORTED');
        }
        $fields = ['MerchantLogin' => config('robokassa.merchant_login'), 'InvoiceID' => $invoice];
        $fields['Signature'] = hash(config('robokassa.hash'), $fields['MerchantLogin'].':'.$invoice.':'.config('robokassa.password2'));
        try {
            $response = Http::asForm()->timeout(15)->connectTimeout(5)->withoutRedirecting()
                ->get('https://auth.robokassa.ru/Merchant/WebService/Service.asmx/OpStateExt', $fields);
        } catch (Throwable) {
            throw new DomainException('ROBOKASSA_LOOKUP_UNKNOWN');
        }
        $body = $response->body();
        if (! $response->successful() || str_contains(strtoupper($body), '<!DOCTYPE') || str_contains(strtoupper($body), '<!ENTITY')) {
            throw new DomainException('ROBOKASSA_LOOKUP_INVALID');
        }
        $xml = @simplexml_load_string($body, \SimpleXMLElement::class, LIBXML_NONET);
        if ($xml === false || (string) $xml->Result->Code !== '0' || (string) $xml->State->Code !== '100' || (string) $xml->Info->OpKey === '') {
            throw new DomainException('ROBOKASSA_PAYMENT_NOT_CONFIRMED');
        }
        $custom = [];
        foreach ($xml->UserFields->Field as $field) {
            $name = (string) $field->Name;
            if (isset($custom[$name])) {
                throw new DomainException('ROBOKASSA_LOOKUP_INVALID');
            }
            $custom[$name] = (string) $field->Value;
        }

        return ['op_key' => (string) $xml->Info->OpKey, 'amount' => RobokassaPaymentProviderAdapter::minor((string) $xml->Info->OutSum), 'custom' => $custom];
    }

    public function createRefund(string $opKey): string
    {
        $this->configured();
        if (! config('robokassa.refund_enabled') || ! config('robokassa.password3') || $opKey === '') {
            throw new DomainException('ROBOKASSA_REFUND_NOT_CONFIGURED');
        }
        $encode = fn (string $value) => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
        $jwt = $encode('{"alg":"HS256","typ":"JWT"}').'.'.$encode(json_encode(['OpKey' => $opKey], JSON_THROW_ON_ERROR));
        $jwt .= '.'.$encode(hash_hmac('sha256', $jwt, config('robokassa.password3'), true));
        try {
            $response = Http::timeout(15)->connectTimeout(5)->withoutRedirecting()->withBody($jwt, 'application/jwt')
                ->post('https://services.robokassa.ru/RefundService/Refund/Create');
        } catch (Throwable) {
            throw new DomainException('ROBOKASSA_REFUND_UNKNOWN');
        }
        $body = $response->json();
        if (! $response->successful() || ! is_array($body)) {
            throw new DomainException('ROBOKASSA_REFUND_UNKNOWN');
        }
        if (($body['success'] ?? null) === false) {
            throw new DomainException('ROBOKASSA_REFUND_REJECTED');
        }
        if (($body['success'] ?? null) !== true || ! is_string($body['requestId'] ?? null) || ! preg_match('/^[a-f0-9-]{36}$/Di', $body['requestId'])) {
            throw new DomainException('ROBOKASSA_REFUND_UNKNOWN');
        }

        return $body['requestId'];
    }

    public function refundState(string $id): array
    {
        $this->configured();
        try {
            $response = Http::timeout(15)->connectTimeout(5)->withoutRedirecting()->get('https://services.robokassa.ru/RefundService/Refund/GetState', ['id' => $id]);
        } catch (Throwable) {
            throw new DomainException('ROBOKASSA_REFUND_LOOKUP_UNKNOWN');
        }
        $body = $response->json();
        if (! $response->successful() || ! is_array($body) || ($body['requestId'] ?? null) !== $id
            || ! in_array($body['label'] ?? null, ['finished', 'processing', 'canceled'], true)) {
            throw new DomainException('ROBOKASSA_REFUND_LOOKUP_INVALID');
        }

        return ['status' => $body['label'], 'amount' => RobokassaPaymentProviderAdapter::minor($body['amount'] ?? null)];
    }

    private function configured(): void
    {
        (new RobokassaPaymentProviderAdapter('robokassa', ''))->assertConfigured();
    }
}
