<?php

namespace App\Services;

use App\Exceptions\AdminImpersonationException;
use App\Models\AdminImpersonationEvent;
use App\Models\User;
use App\Support\ImpersonationContext;
use App\Support\Rbac;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AdminImpersonationService
{
    private const STATUS_ACTIVE = 'active';

    private const STATUS_EXPIRED = 'expired';

    private const STATUS_INACTIVE = 'inactive';

    private const STATUS_STOPPED = 'stopped';

    public function start(Request $request, User $actor, User $subject, string $returnPath): array
    {
        $returnPath = $this->normalizeReturnPath($returnPath);
        $existing = $this->resolve($request);

        if ($existing['status'] === self::STATUS_ACTIVE) {
            $this->deny($request, $actor, $subject, 'nested_context', 'Просмотр пользователя уже запущен.', 409);
        }

        $actor = User::query()->findOrFail($actor->id);
        $subject = User::query()->findOrFail($subject->id);

        if (! Rbac::canImpersonate($actor, $subject)) {
            $this->deny($request, $actor, $subject, 'target_forbidden', 'Нельзя открыть просмотр этого пользователя.', 403);
        }

        if (! $request->hasSession()) {
            throw new AdminImpersonationException(
                'IMPERSONATION_SESSION_UNAVAILABLE',
                'Сессия недоступна. Войдите в админку снова.',
                409
            );
        }

        $now = now();
        $context = [
            'version' => (int) config('impersonation.version', 1),
            'context_id' => (string) Str::uuid(),
            'state' => self::STATUS_ACTIVE,
            'actor_id' => (int) $actor->id,
            'subject_id' => (int) $subject->id,
            'started_at' => $now->toISOString(),
            'expires_at' => $now->copy()->addMinutes($this->ttlMinutes())->toISOString(),
            'return_path' => $returnPath,
        ];

        $this->recordEvent($request, 'started', $context, $actor, $subject, null);
        $request->session()->regenerate();
        $request->session()->forget(ImpersonationContext::EXPIRED_SESSION_KEY);
        $request->session()->put(ImpersonationContext::SESSION_KEY, $context);

        return $this->payload(self::STATUS_ACTIVE, $context, $actor, $subject, $returnPath);
    }

    public function current(Request $request): array
    {
        $resolution = $this->resolve($request);

        return $this->payload(
            $resolution['status'],
            $resolution['context'],
            $resolution['actor'],
            $resolution['subject'],
            $resolution['return_path'],
        );
    }

    public function stop(Request $request): array
    {
        $resolution = $this->resolve($request);
        if ($resolution['status'] === self::STATUS_EXPIRED) {
            $this->clearContext($request);

            return $this->payload(
                self::STATUS_STOPPED,
                $resolution['context'],
                $resolution['actor'],
                $resolution['subject'],
                $resolution['return_path'],
            );
        }

        if ($resolution['status'] !== self::STATUS_ACTIVE) {
            return $this->payload(
                $resolution['status'],
                $resolution['context'],
                $resolution['actor'],
                $resolution['subject'],
                $resolution['return_path'],
            );
        }

        $this->recordEventBestEffort(
            $request,
            'stopped',
            $resolution['context'],
            $resolution['actor'],
            $resolution['subject'],
            null,
        );
        $this->clearContext($request);

        return $this->payload(
            self::STATUS_STOPPED,
            $resolution['context'],
            $resolution['actor'],
            $resolution['subject'],
            $resolution['return_path'],
        );
    }

    /**
     * @return array{status:string, actor:?User, subject:?User, context:?array, return_path:string}
     */
    public function resolve(Request $request): array
    {
        if (! $request->hasSession()) {
            return $this->resolution(self::STATUS_INACTIVE, null, null, null, $this->defaultReturnPath());
        }

        if (! $request->session()->has(ImpersonationContext::SESSION_KEY)) {
            $expiredContext = $request->session()->get(ImpersonationContext::EXPIRED_SESSION_KEY);
            if (is_array($expiredContext)) {
                $actor = $request->user();
                $actor = $actor instanceof User ? User::query()->find($actor->id) : null;
                $subject = $this->subjectFromRawContext($expiredContext);
                $returnPath = $this->safeReturnPath($expiredContext['return_path'] ?? null) ?? $this->defaultReturnPath();

                return $this->resolution(self::STATUS_EXPIRED, $actor, $subject, $expiredContext, $returnPath);
            }

            if ($expiredContext !== null) {
                $this->clearContext($request);
            }

            return $this->resolution(self::STATUS_INACTIVE, null, null, null, $this->defaultReturnPath());
        }

        $rawContext = $request->session()->get(ImpersonationContext::SESSION_KEY);
        if (! is_array($rawContext)) {
            $actor = $request->user();
            $actor = $actor instanceof User ? User::query()->find($actor->id) : null;

            return $this->expireContext($request, [], $actor, null, $this->defaultReturnPath(), 'damaged');
        }

        $fallbackReturnPath = $this->safeReturnPath($rawContext['return_path'] ?? null) ?? $this->defaultReturnPath();
        $actor = $request->user();
        $actor = $actor instanceof User ? User::query()->find($actor->id) : null;
        $subject = $this->subjectFromRawContext($rawContext);
        $context = $this->validContext($rawContext);

        if (! $context || ! $actor || (int) $actor->id !== $context['actor_id']) {
            return $this->expireContext($request, $rawContext, $actor, $subject, $fallbackReturnPath, 'damaged');
        }

        if ($subject === null || ! Rbac::canImpersonate($actor, $subject)) {
            return $this->expireContext($request, $context, $actor, $subject, $fallbackReturnPath, 'revoked');
        }

        $expiresAt = Carbon::parse($context['expires_at']);
        if ($expiresAt->lte(now())) {
            return $this->expireContext($request, $context, $actor, $subject, $fallbackReturnPath, 'ttl_expired');
        }

        $request->attributes->set('impersonation.active', true);
        $request->attributes->set('impersonation.context', $context);
        $request->attributes->set('impersonation.subject', $subject);

        return $this->resolution(self::STATUS_ACTIVE, $actor, $subject, $context, $fallbackReturnPath);
    }

    private function ttlMinutes(): int
    {
        return max(1, min(240, (int) config('impersonation.ttl_minutes', 30)));
    }

    private function defaultReturnPath(): string
    {
        $path = config('impersonation.default_return_path', '/admin/users');

        return is_string($path) && $this->safeReturnPath($path) ? $path : '/admin/users';
    }

    private function normalizeReturnPath(string $path): string
    {
        $normalized = $this->safeReturnPath($path);
        if ($normalized !== null) {
            return $normalized;
        }

        throw new AdminImpersonationException(
            'IMPERSONATION_RETURN_PATH_INVALID',
            'Некорректный путь возврата в админку.',
            422
        );
    }

    private function safeReturnPath(mixed $path): ?string
    {
        if (! is_string($path)) {
            return null;
        }

        $path = trim($path);
        if ($path === '' || strlen($path) > 512 || str_contains($path, '\\') || preg_match('/[\x00-\x1F\x7F]/', $path)) {
            return null;
        }

        $parts = parse_url($path);
        if (! is_array($parts) || isset($parts['scheme'], $parts['host']) || str_starts_with($path, '//')) {
            return null;
        }

        $pathname = $parts['path'] ?? '';
        if ($pathname !== '/admin' && ! str_starts_with($pathname, '/admin/')) {
            return null;
        }

        $query = isset($parts['query']) && $parts['query'] !== '' ? '?'.$parts['query'] : '';

        return $pathname.$query;
    }

    private function validContext(array $context): ?array
    {
        if (($context['version'] ?? null) !== (int) config('impersonation.version', 1)) {
            return null;
        }

        if (($context['state'] ?? null) !== self::STATUS_ACTIVE) {
            return null;
        }

        foreach (['actor_id', 'subject_id'] as $key) {
            if (! isset($context[$key]) || ! is_int($context[$key]) || $context[$key] < 1) {
                return null;
            }
        }

        if (! isset($context['context_id']) || ! is_string($context['context_id']) || ! Str::isUuid($context['context_id'])) {
            return null;
        }

        if ($this->safeReturnPath($context['return_path'] ?? null) === null) {
            return null;
        }

        try {
            $startedAt = Carbon::parse($context['started_at'] ?? '');
            $expiresAt = Carbon::parse($context['expires_at'] ?? '');
        } catch (\Throwable) {
            return null;
        }

        if ($expiresAt->lte($startedAt) || $startedAt->gt(now()->addMinute())) {
            return null;
        }

        return [
            'version' => (int) $context['version'],
            'context_id' => (string) $context['context_id'],
            'state' => self::STATUS_ACTIVE,
            'actor_id' => (int) $context['actor_id'],
            'subject_id' => (int) $context['subject_id'],
            'started_at' => $startedAt->toISOString(),
            'expires_at' => $expiresAt->toISOString(),
            'return_path' => (string) $context['return_path'],
        ];
    }

    private function subjectFromRawContext(array $context): ?User
    {
        $subjectId = $context['subject_id'] ?? null;
        if (! is_int($subjectId) || $subjectId < 1) {
            return null;
        }

        return User::query()->find($subjectId);
    }

    /**
     * @return array{status:string, actor:?User, subject:?User, context:?array, return_path:string}
     */
    private function expireContext(Request $request, array $context, ?User $actor, ?User $subject, string $returnPath, string $reason): array
    {
        $this->recordEventBestEffort($request, 'expired', $context, $actor, $subject, $reason);
        $this->markExpiredContext($request, $context, $actor, $subject, $returnPath);

        return $this->resolution(self::STATUS_EXPIRED, $actor, $subject, $context, $returnPath);
    }

    private function markExpiredContext(
        Request $request,
        array $context,
        ?User $actor,
        ?User $subject,
        string $returnPath,
    ): void {
        $expiredContext = [
            'version' => (int) ($context['version'] ?? config('impersonation.version', 1)),
            'context_id' => isset($context['context_id']) && is_string($context['context_id'])
                ? $context['context_id']
                : null,
            'state' => self::STATUS_EXPIRED,
            'actor_id' => $actor?->id ?? ($context['actor_id'] ?? null),
            'subject_id' => $subject?->id ?? ($context['subject_id'] ?? null),
            'started_at' => isset($context['started_at']) && is_string($context['started_at'])
                ? $context['started_at']
                : null,
            'expires_at' => isset($context['expires_at']) && is_string($context['expires_at'])
                ? $context['expires_at']
                : null,
            'return_path' => $returnPath,
        ];

        $request->attributes->remove('impersonation.active');
        $request->attributes->remove('impersonation.context');
        $request->attributes->remove('impersonation.subject');

        if ($request->hasSession()) {
            $request->session()->forget(ImpersonationContext::SESSION_KEY);
            $request->session()->regenerate();
            $request->session()->put(ImpersonationContext::EXPIRED_SESSION_KEY, $expiredContext);
        }
    }

    private function clearContext(Request $request): void
    {
        $request->attributes->remove('impersonation.active');
        $request->attributes->remove('impersonation.context');
        $request->attributes->remove('impersonation.subject');

        if ($request->hasSession()) {
            $request->session()->forget(ImpersonationContext::SESSION_KEY);
            $request->session()->forget(ImpersonationContext::EXPIRED_SESSION_KEY);
            $request->session()->regenerate();
        }
    }

    /**
     * @return array{status:string, actor:?User, subject:?User, context:?array, return_path:string}
     */
    private function resolution(string $status, ?User $actor, ?User $subject, ?array $context, string $returnPath): array
    {
        return [
            'status' => $status,
            'actor' => $actor,
            'subject' => $subject,
            'context' => $context,
            'return_path' => $returnPath,
        ];
    }

    private function payload(string $status, ?array $context, ?User $actor, ?User $subject, string $returnPath): array
    {
        return [
            'status' => $status,
            'read_only' => in_array($status, [self::STATUS_ACTIVE, self::STATUS_EXPIRED], true),
            'actor' => $this->identity($actor),
            'subject' => $this->identity($subject),
            'started_at' => isset($context['started_at']) && is_string($context['started_at']) ? $context['started_at'] : null,
            'expires_at' => isset($context['expires_at']) && is_string($context['expires_at']) ? $context['expires_at'] : null,
            'return_path' => $returnPath,
        ];
    }

    private function identity(?User $user): ?array
    {
        return $user ? [
            'id' => (int) $user->id,
            'name' => (string) $user->name,
            'role' => (string) $user->role,
        ] : null;
    }

    private function deny(Request $request, ?User $actor, ?User $subject, string $reason, string $message, int $status): never
    {
        $this->recordEventBestEffort($request, 'denied', null, $actor, $subject, $reason);

        throw new AdminImpersonationException('IMPERSONATION_FORBIDDEN', $message, $status);
    }

    private function recordEventBestEffort(
        Request $request,
        string $event,
        ?array $context,
        ?User $actor,
        ?User $subject,
        ?string $reason,
    ): void {
        try {
            $this->recordEvent($request, $event, $context, $actor, $subject, $reason);
        } catch (\Throwable $exception) {
            Log::warning('Admin impersonation audit event was not recorded.', [
                'event' => $event,
                'reason' => $reason,
                'exception' => $exception::class,
            ]);
        }
    }

    private function recordEvent(
        Request $request,
        string $event,
        ?array $context,
        ?User $actor,
        ?User $subject,
        ?string $reason,
    ): AdminImpersonationEvent {
        return AdminImpersonationEvent::query()->create([
            'actor_id' => $actor?->id,
            'subject_id' => $subject?->id,
            'context_id' => is_array($context) && isset($context['context_id']) && is_string($context['context_id'])
                ? $context['context_id']
                : null,
            'event' => $event,
            'state' => is_array($context) && isset($context['state']) && is_string($context['state'])
                ? $context['state']
                : null,
            'reason' => $reason,
            'return_path' => is_array($context) && isset($context['return_path']) && is_string($context['return_path'])
                ? $this->safeReturnPath($context['return_path'])
                : null,
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 1024),
            'metadata' => [
                'method' => $request->method(),
                'path' => '/'.$request->path(),
            ],
            'created_at' => now(),
        ]);
    }
}
