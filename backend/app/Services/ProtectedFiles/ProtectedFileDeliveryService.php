<?php

namespace App\Services\ProtectedFiles;

use App\Models\LessonMaterialFile;
use App\Models\ProtectedFileDelivery;
use App\Models\User;
use Illuminate\Support\Str;

class ProtectedFileDeliveryService
{
    /**
     * @return array{delivery: ProtectedFileDelivery, dispatch: bool}
     */
    public function request(User $user, LessonMaterialFile $source): array
    {
        $delivery = ProtectedFileDelivery::query()->firstOrCreate(
            [
                'user_id' => $user->id,
                'lesson_material_file_id' => $source->id,
                'source_sha256' => $source->source_sha256,
            ],
            [
                'public_id' => (string) Str::uuid(),
                'copy_code' => 'COPY-'.strtoupper(Str::random(16)),
                'status' => ProtectedFileDelivery::STATUS_PENDING,
            ],
        );

        $maxAttempts = config('protected_files.max_attempts', 3);
        $hasAttemptsLeft = is_int($maxAttempts)
            && $maxAttempts >= 1
            && $delivery->attempt_count < $maxAttempts;
        $pendingRetrySeconds = config('protected_files.pending_retry_seconds', 30);
        $stalePending = is_int($pendingRetrySeconds)
            && $pendingRetrySeconds >= 1
            && $delivery->status === ProtectedFileDelivery::STATUS_PENDING
            && $delivery->updated_at !== null
            && $delivery->updated_at->lte(now()->subSeconds($pendingRetrySeconds));
        $recoverable = $hasAttemptsLeft && (
            $stalePending
            || $delivery->status === ProtectedFileDelivery::STATUS_FAILED
            || (
                $delivery->status === ProtectedFileDelivery::STATUS_PROCESSING
                && $delivery->lease_expires_at?->isPast()
            )
        );

        return [
            'delivery' => $delivery,
            'dispatch' => $delivery->wasRecentlyCreated || $recoverable,
        ];
    }
}
