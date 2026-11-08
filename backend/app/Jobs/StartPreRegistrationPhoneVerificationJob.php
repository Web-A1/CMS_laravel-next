<?php

namespace App\Jobs;

use App\Services\RedsmsApiClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class StartPreRegistrationPhoneVerificationJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $verificationId,
        private readonly string $phone,
        private readonly int $ttlMinutes
    ) {}

    public function handle(RedsmsApiClient $redsmsApiClient): void
    {
        if ((string) config('services.redsms.mode', 'mock') === 'mock') {
            $this->applyResult('mock_'.Str::uuid(), 'confirmed', ['source' => 'mock']);

            return;
        }

        try {
            $providerResult = $redsmsApiClient->startWaitCall($this->phone, $this->verificationId);
        } catch (Throwable $e) {
            Log::warning('redsms.preverify.start_failed', [
                'verification_id' => $this->verificationId,
                'message' => $e->getMessage(),
            ]);

            $this->applyResult('provider_failed_'.Str::uuid(), 'failed', [
                'error' => 'provider_failed',
            ]);

            return;
        }

        $this->applyResult($providerResult['provider_uuid'], 'pending', $providerResult['raw'], $providerResult['call_number']);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function applyResult(string $providerUuid, string $status, array $payload, ?string $callNumber = null): void
    {
        DB::table('pre_registration_verifications')
            ->where('channel', 'phone')
            ->where('verification_id', $this->verificationId)
            ->update([
                'status' => $status,
                'provider_uuid' => $providerUuid,
                'call_number' => $callNumber,
                'provider_payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                'confirmed_at' => $status === 'confirmed' ? now() : null,
                'verification_token_expires_at' => $status === 'confirmed' ? now()->addMinutes($this->ttlMinutes) : null,
                'updated_at' => now(),
            ]);
    }
}
