<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class RedsmsApiClient
{
    /**
     * @return array{provider_uuid:string,call_number:?string,raw:array<string,mixed>}
     */
    public function startWaitCall(string $phone, string $clientReference): array
    {
        $baseUrl = rtrim((string) config('services.redsms.base_url', 'https://cp.redsms.ru'), '/');
        $login = (string) config('services.redsms.login', '');
        $apiKey = (string) config('services.redsms.api_key', '');
        $timeoutSeconds = (int) config('services.redsms.timeout_seconds', 15);

        if ($login === '' || $apiKey === '') {
            throw new RuntimeException('REDSMS credentials are not configured.');
        }

        $ts = 'ts-value-'.time();
        $secret = md5($ts.$apiKey);

        $response = Http::timeout($timeoutSeconds)
            ->acceptJson()
            ->withHeaders([
                'login' => $login,
                'ts' => $ts,
                'secret' => $secret,
            ])
            ->post($baseUrl.'/api/message', [
                'route' => 'wcall',
                'to' => $phone,
                'ext_id' => $clientReference,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('REDSMS request failed with status '.$response->status().'.');
        }

        $payload = $response->json();
        if (! is_array($payload)) {
            throw new RuntimeException('REDSMS returned invalid payload.');
        }

        $providerUuid = $this->extractProviderUuid($payload);
        if ($providerUuid === '') {
            throw new RuntimeException('REDSMS payload does not contain provider uuid.');
        }

        return [
            'provider_uuid' => $providerUuid,
            'call_number' => $this->extractCallNumber($payload),
            'raw' => $payload,
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function extractProviderUuid(array $payload): string
    {
        foreach (['uuid', 'id', 'message_id'] as $key) {
            if (isset($payload[$key]) && is_string($payload[$key]) && $payload[$key] !== '') {
                return $payload[$key];
            }
        }

        $items = $payload['items'] ?? null;
        if (is_array($items)) {
            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }
                foreach (['uuid', 'id', 'message_id'] as $key) {
                    if (isset($item[$key]) && is_string($item[$key]) && $item[$key] !== '') {
                        return $item[$key];
                    }
                }
            }
        }

        return '';
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function extractCallNumber(array $payload): ?string
    {
        foreach (['replacedFrom', 'replaced_from', 'call_number', 'from'] as $key) {
            if (isset($payload[$key]) && is_string($payload[$key]) && $payload[$key] !== '') {
                return $payload[$key];
            }
        }

        $items = $payload['items'] ?? null;
        if (is_array($items)) {
            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }
                foreach (['replacedFrom', 'replaced_from', 'call_number', 'from'] as $key) {
                    if (isset($item[$key]) && is_string($item[$key]) && $item[$key] !== '') {
                        return $item[$key];
                    }
                }
            }
        }

        return null;
    }
}
