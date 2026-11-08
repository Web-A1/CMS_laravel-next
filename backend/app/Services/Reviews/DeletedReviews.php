<?php

namespace App\Services\Reviews;

use App\Models\AdminOperationEvent;
use Illuminate\Support\Facades\DB;

final class DeletedReviews
{
    public static function lock(): void
    {
        // Import and hard deletion share the same lock order before taking review locks.
        if (DB::getDriverName() === 'pgsql') {
            DB::select('select pg_advisory_xact_lock(19101926, 1)');
        }
    }

    public static function fingerprints(string $type, ?string $ref, string|int|null $externalId = null): array
    {
        $keys = [];
        foreach (['ref' => $ref, 'id' => $externalId] as $kind => $value) {
            if ($value !== null && (string) $value !== '') {
                $keys[] = hash('sha256', json_encode([$type, $kind, (string) $value], JSON_THROW_ON_ERROR));
            }
        }

        return $keys;
    }

    public static function contains(array $fingerprints): bool
    {
        return $fingerprints !== [] && AdminOperationEvent::where('operation', 'delete_review')->where('resource', 'Review')
            ->where(function ($query) use ($fingerprints): void {
                foreach ($fingerprints as $hash) {
                    $query->orWhereJsonContains('payload->source_fingerprints', $hash);
                }
            })->exists();
    }
}
