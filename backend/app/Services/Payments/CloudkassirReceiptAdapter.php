<?php

namespace App\Services\Payments;

use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class CloudkassirReceiptAdapter
{
    public function create(array $payload, string $key): array
    {
        return $this->request('receipt', $payload, $key);
    }

    public function status(string $id): array
    {
        return $this->request('receipt/status/get', ['Id' => $id]);
    }

    public function details(string $id): array
    {
        return $this->request('receipt/get', ['Id' => $id]);
    }

    private function request(string $path, array $payload, ?string $key = null): array
    {
        $request = Http::withBasicAuth((string) config('fiscal.public_id'), (string) config('fiscal.api_secret'))
            ->acceptJson()->asJson()->connectTimeout(5)->timeout(15)->withoutRedirecting();
        if ($key !== null) {
            $request = $request->withHeaders(['X-Request-ID' => $key]);
        }
        // No automatic retries: create can have an ambiguous outcome.
        try {
            $response = $request->post('https://api.cloudpayments.ru/kkt/'.$path, $payload);
        } catch (Throwable) {
            throw new RuntimeException('FISCAL_TRANSPORT_UNCERTAIN');
        }
        $body = $response->json();
        if (! $response->successful() || ! is_array($body) || ! is_bool($body['Success'] ?? null)) {
            throw new RuntimeException('FISCAL_PROVIDER_RESPONSE_INVALID');
        }

        return $body;
    }
}
