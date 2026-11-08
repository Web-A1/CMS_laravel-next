<?php

namespace App\Services\Kinescope;

use App\Exceptions\KinescopePlaybackUnavailableException;
use App\Models\KinescopeLegacyVideoAllowlistBatch;
use App\Models\KinescopeLegacyVideoAllowlistEntry;
use Illuminate\Database\QueryException;

final class LegacyKinescopeVideoAllowlist
{
    public function allows(string $embedLocator, string $providerVideoId): bool
    {
        try {
            $activeBatchIds = KinescopeLegacyVideoAllowlistBatch::query()
                ->where('is_active', true)
                ->orderBy('id')
                ->limit(2)
                ->pluck('id');

            if ($activeBatchIds->count() !== 1) {
                throw new KinescopePlaybackUnavailableException(
                    'Kinescope legacy video allowlist has no unique active batch.',
                );
            }

            $activeBatchId = $activeBatchIds->first();
            if (KinescopeLegacyVideoAllowlistEntry::query()
                ->where('batch_id', $activeBatchId)
                ->whereNull('provider_video_id')
                ->exists()) {
                throw new KinescopePlaybackUnavailableException(
                    'Kinescope legacy video allowlist contains incomplete pairs.',
                );
            }

            return KinescopeLegacyVideoAllowlistEntry::query()
                ->where('batch_id', $activeBatchId)
                ->where('embed_locator', $embedLocator)
                ->where('provider_video_id', $providerVideoId)
                ->exists();
        } catch (QueryException $exception) {
            throw new KinescopePlaybackUnavailableException(
                'Kinescope legacy video allowlist is unavailable.',
                previous: $exception,
            );
        }
    }
}
