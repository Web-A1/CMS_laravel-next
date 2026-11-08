<?php

namespace App\Services\VideoWatermarks;

use App\Models\User;
use App\Models\VideoWatermarkLookupAuditEvent;
use App\Models\VideoWatermarkSession;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

final class VideoWatermarkSessionService
{
    private const SESSION_KEY = 'video_watermark_session_id';

    private const ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    private const RETENTION_YEARS = 3;

    private const ORPHAN_AFTER_MINUTES = 60;

    private const CODE_CREATE_ATTEMPTS = 5;

    public function currentForPlayback(Request $request, User $user): VideoWatermarkSession
    {
        $sessionId = $this->requireSessionId($request);
        $authSessionHash = hash('sha256', $sessionId);
        $now = now();

        return DB::transaction(function () use ($request, $user, $authSessionHash, $now): VideoWatermarkSession {
            $current = $this->currentSessionForRequest($request, $user, $authSessionHash);

            if (! $current) {
                $current = $this->createSession($user, $authSessionHash, $now);
            }

            $current->forceFill([
                'auth_session_hash' => $authSessionHash,
                'last_used_at' => $now,
            ])->save();
            $request->session()->put(self::SESSION_KEY, $current->id);

            return $current;
        }, 3);
    }

    public function closeCurrent(Request $request, string $reason): void
    {
        if (! in_array($reason, $this->endReasons(), true)) {
            throw new InvalidArgumentException('Unsupported video watermark session end reason.');
        }

        $sessionId = $this->sessionId($request);
        if ($sessionId === null) {
            return;
        }

        $authSessionHash = hash('sha256', $sessionId);
        $now = now();

        DB::transaction(function () use ($request, $authSessionHash, $reason, $now): void {
            $currentId = $request->session()->get(self::SESSION_KEY);
            $current = is_string($currentId)
                ? VideoWatermarkSession::query()
                    ->whereKey($currentId)
                    ->whereNull('ended_at')
                    ->lockForUpdate()
                    ->first()
                : null;

            if (! $current) {
                $current = VideoWatermarkSession::query()
                    ->where('auth_session_hash', $authSessionHash)
                    ->whereNull('ended_at')
                    ->lockForUpdate()
                    ->first();
            }

            if ($current) {
                $current->forceFill([
                    'ended_at' => $now,
                    'end_reason' => $reason,
                    'purge_after' => $now->copy()->addYears(self::RETENTION_YEARS),
                ])->save();
            }

            $request->session()->forget(self::SESSION_KEY);
        }, 3);
    }

    public function lookupByInput(string $input, User $actor): ?VideoWatermarkSession
    {
        $normalized = $this->normalizeInput($input);
        $session = $normalized === null
            ? null
            : VideoWatermarkSession::query()
                ->where('code', $this->canonicalCode($normalized))
                ->first();

        VideoWatermarkLookupAuditEvent::query()->create([
            'actor_user_id' => $actor->id,
            'watermark_session_id' => $session?->id,
            'normalized_code' => $normalized,
            'result' => $session ? 'found' : 'not_found',
            'created_at' => now(),
        ]);

        return $session;
    }

    public function closeOrphanedSessions(): int
    {
        $now = now();
        $activeSessionIds = $this->activeSessionWatermarkIds($now);

        if ($activeSessionIds === null) {
            return 0;
        }

        $query = VideoWatermarkSession::query()
            ->whereNull('ended_at')
            ->where('last_used_at', '<=', $now->copy()->subMinutes(self::ORPHAN_AFTER_MINUTES));

        if ($activeSessionIds !== []) {
            $query->whereNotIn('id', $activeSessionIds);
        }

        return $query->update([
            'ended_at' => $now,
            'end_reason' => VideoWatermarkSession::END_REASON_ORPHAN_EXPIRED,
            'purge_after' => $now->copy()->addYears(self::RETENTION_YEARS),
            'updated_at' => $now,
        ]);
    }

    public function purgeExpiredSessions(): int
    {
        return VideoWatermarkSession::query()
            ->whereNotNull('ended_at')
            ->whereNotNull('purge_after')
            ->where('purge_after', '<=', now())
            ->delete();
    }

    public function normalizeInput(string $input): ?string
    {
        $value = strtoupper(preg_replace('/[\s-]+/', '', trim($input)) ?? '');
        if (preg_match('/^[2-9A-HJKMNP-Z]{8}$/', $value) === 1) {
            $value = 'CMS'.$value;
        }

        return preg_match('/^CMS[2-9A-HJKMNP-Z]{8}$/', $value) === 1 ? $value : null;
    }

    private function currentSessionForRequest(
        Request $request,
        User $user,
        string $authSessionHash,
    ): ?VideoWatermarkSession {
        $currentId = $request->session()->get(self::SESSION_KEY);
        $current = is_string($currentId)
            ? VideoWatermarkSession::query()
                ->whereKey($currentId)
                ->where('user_id', $user->id)
                ->whereNull('ended_at')
                ->lockForUpdate()
                ->first()
            : null;

        if ($current) {
            return $current;
        }

        return VideoWatermarkSession::query()
            ->where('auth_session_hash', $authSessionHash)
            ->where('user_id', $user->id)
            ->whereNull('ended_at')
            ->lockForUpdate()
            ->first();
    }

    private function createSession(User $user, string $authSessionHash, Carbon $now): VideoWatermarkSession
    {
        for ($attempt = 0; $attempt < self::CODE_CREATE_ATTEMPTS; $attempt++) {
            $id = (string) Str::uuid();
            $code = $this->generateCode();
            $created = DB::table('video_watermark_sessions')->insertOrIgnore([
                'id' => $id,
                'code' => $code,
                'auth_session_hash' => $authSessionHash,
                'user_id' => $user->id,
                'started_at' => $now,
                'last_used_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            if ($created === 1) {
                return VideoWatermarkSession::query()->findOrFail($id);
            }

            $current = VideoWatermarkSession::query()
                ->where('auth_session_hash', $authSessionHash)
                ->where('user_id', $user->id)
                ->whereNull('ended_at')
                ->lockForUpdate()
                ->first();

            if ($current) {
                return $current;
            }
        }

        throw new RuntimeException('Could not generate a unique video watermark code.');
    }

    private function generateCode(): string
    {
        do {
            $value = strtoupper(Str::random(8));
        } while (strspn($value, self::ALPHABET) !== 8);

        return $this->canonicalCode('CMS'.$value);
    }

    private function canonicalCode(string $normalized): string
    {
        return substr($normalized, 0, 3).'-'.substr($normalized, 3, 4).'-'.substr($normalized, 7, 4);
    }

    private function requireSessionId(Request $request): string
    {
        $sessionId = $this->sessionId($request);
        if ($sessionId === null) {
            throw new RuntimeException('A Laravel session is required for video watermark playback.');
        }

        return $sessionId;
    }

    /**
     * @return list<string>
     */
    private function endReasons(): array
    {
        return [
            VideoWatermarkSession::END_REASON_LOGOUT,
            VideoWatermarkSession::END_REASON_IDLE_TIMEOUT,
            VideoWatermarkSession::END_REASON_REVOKED,
            VideoWatermarkSession::END_REASON_ORPHAN_EXPIRED,
        ];
    }

    private function sessionId(Request $request): ?string
    {
        if (! $request->hasSession()) {
            return null;
        }

        $sessionId = $request->session()->getId();

        return is_string($sessionId) && $sessionId !== '' ? $sessionId : null;
    }

    /**
     * @return list<string>|null
     */
    private function activeSessionWatermarkIds(Carbon $now): ?array
    {
        return match (config('session.driver')) {
            'database' => $this->activeDatabaseSessionWatermarkIds($now),
            'file' => $this->activeFileSessionWatermarkIds($now),
            default => null,
        };
    }

    /**
     * @return list<string>|null
     */
    private function activeDatabaseSessionWatermarkIds(Carbon $now): ?array
    {
        $connection = config('session.connection');
        $database = $connection === null ? DB::connection() : DB::connection((string) $connection);
        $payloads = $database->table((string) config('session.table', 'sessions'))
            ->where('last_activity', '>=', $now->copy()->subMinutes((int) config('session.lifetime', 120))->timestamp)
            ->pluck('payload');
        $sessionIds = [];

        foreach ($payloads as $payload) {
            $serialized = base64_decode((string) $payload, true);
            if ($serialized === false) {
                return null;
            }

            $attributes = $this->sessionAttributes($serialized);
            if ($attributes === null) {
                return null;
            }

            $watermarkSessionId = $attributes[self::SESSION_KEY] ?? null;

            if (is_string($watermarkSessionId) && $watermarkSessionId !== '') {
                $sessionIds[] = $watermarkSessionId;
            }
        }

        return array_values(array_unique($sessionIds));
    }

    /**
     * @return list<string>|null
     */
    private function activeFileSessionWatermarkIds(Carbon $now): ?array
    {
        $directory = (string) config('session.files');
        if (! File::isDirectory($directory)) {
            return [];
        }

        $sessionIds = [];
        $minimumLastModified = $now->copy()->subMinutes((int) config('session.lifetime', 120))->timestamp;

        foreach (File::files($directory) as $file) {
            if ($file->getMTime() < $minimumLastModified) {
                continue;
            }

            $attributes = $this->sessionAttributes(File::get($file->getPathname()));
            if ($attributes === null) {
                return null;
            }

            $watermarkSessionId = $attributes[self::SESSION_KEY] ?? null;

            if (is_string($watermarkSessionId) && $watermarkSessionId !== '') {
                $sessionIds[] = $watermarkSessionId;
            }
        }

        return array_values(array_unique($sessionIds));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function sessionAttributes(string $serialized): ?array
    {
        if ((bool) config('session.encrypt', false)) {
            try {
                $serialized = app('encrypter')->decrypt($serialized);
            } catch (\Throwable) {
                return null;
            }
        }

        $attributes = config('session.serialization', 'php') === 'json'
            ? json_decode($serialized, true)
            : @unserialize($serialized, ['allowed_classes' => false]);

        return is_array($attributes) ? $attributes : null;
    }
}
