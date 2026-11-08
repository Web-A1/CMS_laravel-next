<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class SmtpBzApiClient
{
    public function isEnabled(): bool
    {
        return (bool) config('services.smtpbz.enabled', false);
    }

    public function sendHtmlMessage(
        string $toEmail,
        string $subject,
        string $html,
        ?string $toName = null
    ): bool {
        if (! $this->isEnabled()) {
            return false;
        }

        $apiKey = (string) config('services.smtpbz.api_key', '');
        $baseUrl = rtrim((string) config('services.smtpbz.base_url', 'https://api.smtp.bz'), '/');
        $fromEmail = (string) config('services.smtpbz.from_email', '');
        $fromName = (string) config('services.smtpbz.from_name', config('app.name', 'App'));
        $timeout = (int) config('services.smtpbz.timeout_seconds', 15);

        if ($apiKey === '' || $fromEmail === '') {
            Log::warning('smtpbz.misconfigured');

            return false;
        }

        $payload = [
            'name' => $fromName,
            'from' => $fromEmail,
            'subject' => $subject,
            'to' => $toEmail,
            'html' => $html,
        ];

        if ($toName) {
            $payload['to_name'] = $toName;
        }

        try {
            $response = Http::asForm()
                ->timeout($timeout)
                ->withHeaders([
                    'Authorization' => $apiKey,
                ])
                ->post($baseUrl.'/v1/smtp/send', $payload);
        } catch (Throwable) {
            Log::warning('smtpbz.request_failed');

            return false;
        }

        if ($response->successful()) {
            return true;
        }

        Log::warning('smtpbz.send_failed', [
            'status' => $response->status(),
        ]);

        return false;
    }
}
