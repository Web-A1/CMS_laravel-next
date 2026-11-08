<?php

namespace App\Services\TrustedBrowser;

use App\Models\TrustedBrowserAccount;
use App\Models\TrustedBrowserAuditEvent;
use App\Models\TrustedBrowserChallenge;
use App\Models\TrustedBrowserCredential;
use App\Models\TrustedBrowserSessionLink;
use App\Models\TrustedBrowserSlot;
use App\Models\User;
use App\Services\SmtpBzApiClient;
use App\Support\Rbac;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

final class TrustedBrowserService
{
    private const REQUIRED_CODE = 'TRUSTED_BROWSER_REQUIRED';

    private const SUPPORT_REQUIRED_CODE = 'TRUSTED_BROWSER_SUPPORT_REQUIRED';

    private const RISK_LOCKED_CODE = 'TRUSTED_BROWSER_RISK_LOCKED';

    public function __construct(
        private readonly TrustedBrowserCookie $cookie,
        private readonly SmtpBzApiClient $smtpBzApiClient,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function status(Request $request, User $user): array
    {
        $context = $this->resolveState($request, $user);

        return [
            'mode' => $this->mode(),
            'state' => $context['state'],
            'slot_limit' => $this->slotLimit(),
            'occupied_slots' => $context['occupied_slots'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function listSlots(Request $request, User $user): array
    {
        $context = $this->resolveState($request, $user);
        $slots = TrustedBrowserSlot::query()
            ->where('account_id', $context['account']->id)
            ->orderBy('slot_number')
            ->get()
            ->keyBy('slot_number');
        $currentCredentialId = $context['credential']?->id;
        $result = [];

        for ($slotNumber = 1; $slotNumber <= $this->slotLimit(); $slotNumber++) {
            /** @var TrustedBrowserSlot|null $slot */
            $slot = $slots->get($slotNumber);
            if (! $slot) {
                $result[] = [
                    'slot' => $slotNumber,
                    'status' => 'empty',
                    'generation' => null,
                    'is_current' => false,
                    'browser_label' => null,
                    'os_label' => null,
                    'device_label' => null,
                    'last_seen_at' => null,
                    'expires_at' => null,
                ];

                continue;
            }

            $credential = $slot->current_generation === null
                ? null
                : TrustedBrowserCredential::query()
                    ->where('slot_id', $slot->id)
                    ->where('generation', $slot->current_generation)
                    ->first();

            $result[] = [
                'slot' => $slotNumber,
                'status' => $slot->status,
                'generation' => $slot->current_generation,
                'is_current' => $credential !== null && $credential->id === $currentCredentialId,
                'browser_label' => $credential?->browser_label,
                'os_label' => $credential?->os_label,
                'device_label' => $credential?->device_label,
                'last_seen_at' => $credential?->last_seen_at?->toISOString(),
                'expires_at' => $credential?->expires_at?->toISOString(),
            ];
        }

        return [
            'mode' => $this->mode(),
            'state' => $context['state'],
            'slots' => $result,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function startUserChallenge(Request $request, User $user, array $input): array
    {
        $purpose = (string) ($input['purpose'] ?? '');
        if (! in_array($purpose, ['enroll', 'revoke'], true)) {
            throw new TrustedBrowserException('TRUSTED_BROWSER_PURPOSE_INVALID', 'Недопустимая цель подтверждения.', 422);
        }

        $email = $this->verifiedEmail($user);
        $sessionHash = $this->requireSessionHash($request);
        $ipHash = $this->ipHash($request);
        $context = $this->resolveState($request, $user);

        if ($purpose === 'enroll' && $context['state'] !== 'unknown_can_confirm') {
            throw $this->stateException($context['state']);
        }

        if ($purpose === 'revoke') {
            if ($context['state'] === 'trusted') {
                throw new TrustedBrowserException(
                    'TRUSTED_BROWSER_DIRECT_REVOKE_AVAILABLE',
                    'Этот браузер уже подтверждён. Выполните отзыв без кода.',
                    409,
                );
            }

            $password = (string) ($input['password'] ?? '');
            if ($password === '' || ! Hash::check($password, (string) $user->password)) {
                throw new TrustedBrowserException(
                    'TRUSTED_BROWSER_PASSWORD_REQUIRED',
                    'Для аварийного отзыва подтвердите текущий пароль.',
                    422,
                );
            }
        }

        $account = $context['account'];
        $code = $this->generateOtp();
        $codeHash = Hash::make($code);
        $expiresAt = now()->addMinutes((int) config('trusted_browser.otp.ttl_minutes', 10));

        $challenge = DB::transaction(function () use (
            $request,
            $user,
            $input,
            $purpose,
            $account,
            $sessionHash,
            $ipHash,
            $email,
            $codeHash,
            $expiresAt,
        ): TrustedBrowserChallenge {
            $lockedAccount = $this->lockAccount($account);
            $slots = $this->lockSlots($lockedAccount);

            if ($purpose === 'enroll' && $this->occupiedCount($slots) >= $this->slotLimit()) {
                throw $this->stateException('unknown_support_required');
            }

            $targetSlot = null;
            if ($purpose === 'revoke') {
                $slotNumber = (int) ($input['slot_number'] ?? 0);
                $targetSlot = $slots->firstWhere('slot_number', $slotNumber);
                if (! $targetSlot instanceof TrustedBrowserSlot || ! in_array($targetSlot->status, ['active', 'risk_locked'], true)) {
                    throw new TrustedBrowserException(
                        'TRUSTED_BROWSER_SLOT_NOT_REVOCABLE',
                        'Этот слот нельзя отозвать.',
                        409,
                    );
                }
            }

            $this->assertSendAllowed($user, $purpose, $sessionHash, $ipHash);
            $this->supersedePendingUserChallenges(
                $request,
                $lockedAccount,
                $user,
                $purpose,
                $sessionHash,
            );

            $challenge = TrustedBrowserChallenge::query()->create([
                'id' => (string) Str::uuid(),
                'account_id' => $lockedAccount->id,
                'user_id' => $user->id,
                'purpose' => $purpose,
                'target_slot_id' => $targetSlot?->id,
                'initiated_by_user_id' => $user->id,
                'session_hash' => $sessionHash,
                'email_hash' => $this->contextHash('email', mb_strtolower($email)),
                'code_hash' => $codeHash,
                'attempts_left' => (int) config('trusted_browser.otp.max_attempts', 5),
                'max_attempts' => (int) config('trusted_browser.otp.max_attempts', 5),
                'status' => 'pending',
                'expires_at' => $expiresAt,
                'send_ip_hash' => $ipHash,
                'metadata' => $purpose === 'enroll' ? $this->displayMetadata($input) : null,
            ]);

            $this->audit($request, $lockedAccount, $user, 'challenge.sent', [
                'challenge_id' => $challenge->id,
                'challenge' => $challenge,
                'slot' => $targetSlot,
                'metadata' => ['purpose' => $purpose],
            ]);

            return $challenge;
        }, 3);

        $this->sendChallengeCode($email, $code, $purpose);

        return $this->challengePayload($challenge, $code);
    }

    /**
     * @return array<string, mixed>
     */
    public function verifyUserChallenge(Request $request, User $user, string $challengeId, string $code): array
    {
        $account = $this->accountFor($user);
        $sessionHash = $this->requireSessionHash($request);
        $ipHash = $this->ipHash($request);
        $currentSessionId = $this->sessionId($request);
        [$inspectedCredential] = $this->inspectCredential($request, $account);

        $outcome = DB::transaction(function () use (
            $request,
            $user,
            $challengeId,
            $code,
            $account,
            $sessionHash,
            $ipHash,
            $currentSessionId,
            $inspectedCredential,
        ): array {
            $lockedAccount = $this->lockAccount($account);
            $slots = $this->lockSlots($lockedAccount);
            $challenge = TrustedBrowserChallenge::query()
                ->whereKey($challengeId)
                ->where('account_id', $lockedAccount->id)
                ->where('user_id', $user->id)
                ->whereIn('purpose', ['enroll', 'revoke'])
                ->lockForUpdate()
                ->first();

            if (! $challenge || ! hash_equals($challenge->session_hash, $sessionHash) || $challenge->status !== 'pending') {
                return ['error' => $this->invalidChallengeException()];
            }

            $rateError = $this->verifyRateError($challenge, $sessionHash, $ipHash);
            if ($rateError) {
                return ['error' => $rateError];
            }

            $this->audit($request, $lockedAccount, $user, 'challenge.verify_attempt', [
                'challenge_id' => $challenge->id,
                'challenge' => $challenge,
                'slot' => $challenge->targetSlot,
                'metadata' => ['purpose' => $challenge->purpose],
            ]);

            if ($challenge->expires_at->isPast()) {
                $challenge->forceFill(['status' => 'expired', 'used_at' => now()])->save();

                return ['error' => $this->invalidChallengeException()];
            }

            if (! Hash::check($code, $challenge->code_hash)) {
                $attemptsLeft = max(0, $challenge->attempts_left - 1);
                $challenge->forceFill([
                    'attempts_left' => $attemptsLeft,
                    'status' => $attemptsLeft === 0 ? 'failed' : 'pending',
                    'used_at' => $attemptsLeft === 0 ? now() : null,
                    'last_verify_ip_hash' => $ipHash,
                ])->save();

                return ['error' => $this->invalidChallengeException()];
            }

            if ($challenge->purpose === 'enroll') {
                $this->supersedePendingUserChallenges(
                    $request,
                    $lockedAccount,
                    $user,
                    $challenge->purpose,
                    $sessionHash,
                    $challenge->id,
                );

                if ($this->occupiedCount($slots) >= $this->slotLimit()) {
                    $challenge->forceFill(['status' => 'failed', 'used_at' => now()])->save();

                    return ['error' => $this->stateException('unknown_support_required')];
                }

                $enrollment = $this->enrollLocked(
                    $request,
                    $lockedAccount,
                    $slots,
                    $user,
                    $currentSessionId,
                    $challenge->metadata ?? [],
                    $challenge,
                );
                $challenge->forceFill([
                    'status' => 'verified',
                    'verified_at' => now(),
                    'used_at' => now(),
                    'last_verify_ip_hash' => $ipHash,
                ])->save();

                return ['enrollment' => $enrollment, 'challenge' => $challenge];
            }

            $targetSlot = $slots->firstWhere('id', $challenge->target_slot_id);
            if (! $targetSlot instanceof TrustedBrowserSlot || ! in_array($targetSlot->status, ['active', 'risk_locked'], true)) {
                $challenge->forceFill(['status' => 'failed', 'used_at' => now()])->save();

                return [
                    'error' => new TrustedBrowserException(
                        'TRUSTED_BROWSER_SLOT_NOT_REVOCABLE',
                        'Этот слот нельзя отозвать.',
                        409,
                    ),
                ];
            }

            $revocation = $this->revokeLocked(
                $request,
                $lockedAccount,
                $targetSlot,
                $user,
                'email_challenge',
                $inspectedCredential,
                $challenge,
            );
            $challenge->forceFill([
                'status' => 'verified',
                'verified_at' => now(),
                'used_at' => now(),
                'last_verify_ip_hash' => $ipHash,
            ])->save();

            return ['revocation' => $revocation, 'challenge' => $challenge];
        }, 3);

        if (($outcome['error'] ?? null) instanceof TrustedBrowserException) {
            throw $outcome['error'];
        }

        if (isset($outcome['enrollment'])) {
            $enrollment = $outcome['enrollment'];
            $this->cookie->queue($request, $account, $enrollment['cookie_value']);

            return [
                'status' => 'verified',
                'purpose' => 'enroll',
                'state' => 'trusted',
                'slot' => $enrollment['slot_number'],
            ];
        }

        $revocation = $outcome['revocation'];
        $this->finishSessionInvalidation($request, $revocation);
        if ($revocation['forget_cookie']) {
            $this->cookie->forget($request, $account);
        }

        return [
            'status' => 'verified',
            'purpose' => 'revoke',
            'slot' => $revocation['slot_number'],
            'slot_status' => 'revoked_locked',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function revoke(Request $request, User $user, int $slotNumber): array
    {
        $context = $this->resolveState($request, $user);
        if ($context['state'] !== 'trusted') {
            throw new TrustedBrowserException(
                'TRUSTED_BROWSER_REVOKE_CONFIRMATION_REQUIRED',
                'Для отзыва из неподтверждённого браузера запросите код и подтвердите пароль.',
                403,
            );
        }

        $account = $context['account'];
        $inspectedCredential = $context['credential'];
        $revocation = DB::transaction(function () use (
            $request,
            $user,
            $slotNumber,
            $account,
            $inspectedCredential,
        ): array {
            $lockedAccount = $this->lockAccount($account);
            $slots = $this->lockSlots($lockedAccount);
            $targetSlot = $slots->firstWhere('slot_number', $slotNumber);

            if (! $targetSlot instanceof TrustedBrowserSlot || ! in_array($targetSlot->status, ['active', 'risk_locked'], true)) {
                throw new TrustedBrowserException(
                    'TRUSTED_BROWSER_SLOT_NOT_REVOCABLE',
                    'Этот слот нельзя отозвать.',
                    409,
                );
            }

            return $this->revokeLocked(
                $request,
                $lockedAccount,
                $targetSlot,
                $user,
                'trusted_session',
                $inspectedCredential,
            );
        }, 3);

        $this->finishSessionInvalidation($request, $revocation);
        if ($revocation['forget_cookie']) {
            $this->cookie->forget($request, $account);
        }

        return [
            'status' => 'revoked',
            'slot' => $slotNumber,
            'slot_status' => 'revoked_locked',
        ];
    }

    public function rebindCurrentSession(Request $request, User $user): bool
    {
        $sessionId = $this->sessionId($request);
        if ($sessionId === null) {
            return false;
        }

        $account = $this->accountFor($user);
        [$credential, $parsed] = $this->inspectCredential($request, $account);
        if (! $credential || ! $parsed) {
            return false;
        }

        $result = DB::transaction(function () use (
            $request,
            $user,
            $sessionId,
            $account,
            $credential,
        ): array {
            $lockedAccount = $this->lockAccount($account);
            $slots = $this->lockSlots($lockedAccount);
            $slot = $slots->firstWhere('id', $credential->slot_id);
            $lockedCredential = TrustedBrowserCredential::query()->whereKey($credential->id)->lockForUpdate()->first();

            if (
                ! $slot instanceof TrustedBrowserSlot
                || ! $lockedCredential
                || $slot->status !== 'active'
                || $slot->current_generation !== $lockedCredential->generation
                || $lockedCredential->invalidated_at !== null
                || $lockedCredential->expires_at->isPast()
            ) {
                return ['bound' => false, 'refresh_cookie' => false];
            }

            $existingLink = TrustedBrowserSessionLink::query()->where('session_id', $sessionId)->first();
            $wasBound = $existingLink
                && $existingLink->credential_id === $lockedCredential->id
                && $existingLink->revoked_at === null;

            TrustedBrowserSessionLink::query()->updateOrCreate(
                ['session_id' => $sessionId],
                [
                    'account_id' => $lockedAccount->id,
                    'slot_id' => $slot->id,
                    'credential_id' => $lockedCredential->id,
                    'bound_at' => $wasBound ? $existingLink->bound_at : now(),
                    'last_seen_at' => now(),
                    'revoked_at' => null,
                ],
            );

            $refreshCookie = $lockedCredential->last_refreshed_at
                ->lte(now()->subHours((int) config('trusted_browser.credential.refresh_interval_hours', 24)));
            $credentialUpdates = [
                'last_seen_at' => now(),
                'last_ip_hash' => $this->ipHash($request),
            ];
            if ($refreshCookie) {
                $credentialUpdates['last_refreshed_at'] = now();
                $credentialUpdates['expires_at'] = now()->addDays(
                    (int) config('trusted_browser.credential.rolling_expiry_days', 400),
                );
            }
            $lockedCredential->forceFill($credentialUpdates)->save();

            if (! $wasBound) {
                $this->audit($request, $lockedAccount, $user, 'session.rebound', [
                    'slot' => $slot,
                    'credential' => $lockedCredential,
                ]);
            }

            return ['bound' => true, 'refresh_cookie' => $refreshCookie];
        }, 3);

        if ($result['bound'] && $result['refresh_cookie']) {
            $this->cookie->queue($request, $account, $parsed['value']);
        }

        return $result['bound'];
    }

    /**
     * @return array{
     *     state: string,
     *     account: TrustedBrowserAccount,
     *     credential: TrustedBrowserCredential|null,
     *     slot: TrustedBrowserSlot|null,
     *     occupied_slots: int
     * }
     */
    public function resolveState(Request $request, User $user): array
    {
        $account = $this->accountFor($user);
        $this->rebindCurrentSession($request, $user);
        [$credential] = $this->inspectCredential($request, $account);
        $occupiedSlots = TrustedBrowserSlot::query()
            ->where('account_id', $account->id)
            ->whereIn('status', TrustedBrowserSlot::OCCUPIED_STATUSES)
            ->count();
        $slot = $credential?->slot;
        $state = $occupiedSlots >= $this->slotLimit()
            ? 'unknown_support_required'
            : 'unknown_can_confirm';

        if ($credential && $slot && $slot->account_id === $account->id) {
            $isCurrentGeneration = $slot->current_generation === $credential->generation;

            if ($isCurrentGeneration && $slot->status === 'revoked_locked') {
                $state = 'revoked_current';
            } elseif ($isCurrentGeneration && $slot->status === 'risk_locked') {
                $state = 'risk_step_up_required';
            } elseif (
                $isCurrentGeneration
                && $slot->status === 'active'
                && $credential->invalidated_at === null
                && $credential->expires_at->isFuture()
            ) {
                $sessionId = $this->sessionId($request);
                $hasActiveLink = $sessionId !== null && TrustedBrowserSessionLink::query()
                    ->where('session_id', $sessionId)
                    ->where('credential_id', $credential->id)
                    ->whereNull('revoked_at')
                    ->exists();

                if ($hasActiveLink) {
                    $state = 'trusted';
                }
            }
        }

        return [
            'state' => $state,
            'account' => $account,
            'credential' => $credential,
            'slot' => $slot,
            'occupied_slots' => $occupiedSlots,
        ];
    }

    public function auditObservedDecision(
        Request $request,
        User $user,
        TrustedBrowserAccessDecision $decision,
    ): void {
        if ($decision->mode !== 'observe' || $decision->state === 'trusted') {
            return;
        }

        $context = $this->resolveState($request, $user);
        $this->audit($request, $context['account'], $user, 'access.observed', [
            'slot' => $context['slot'],
            'credential' => $context['credential'],
            'mode' => $decision->mode,
            'state' => $decision->state,
            'metadata' => ['code' => $decision->code],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function startSupportRelease(
        Request $request,
        User $operator,
        User $targetUser,
        int $slotNumber,
        string $caseId,
        string $reason,
        string $idempotencyKey,
    ): array {
        $this->assertReleasePermission($operator);
        $email = $this->verifiedEmail($targetUser);
        $sessionHash = $this->requireSessionHash($request);
        $ipHash = $this->ipHash($request);
        $idempotencyHash = $this->contextHash('idempotency', $idempotencyKey);
        $account = $this->accountFor($targetUser);
        $existing = TrustedBrowserChallenge::query()
            ->where('initiated_by_user_id', $operator->id)
            ->where('idempotency_key_hash', $idempotencyHash)
            ->first();

        if ($existing) {
            $this->assertSupportIdempotencyContext($existing, $account, $slotNumber, $caseId, $reason);

            return $existing->status === 'completed' && is_array($existing->result_payload)
                ? $existing->result_payload
                : $this->challengePayload($existing);
        }

        $code = $this->generateOtp();
        $codeHash = Hash::make($code);
        $expiresAt = now()->addMinutes((int) config('trusted_browser.otp.ttl_minutes', 10));

        $challenge = DB::transaction(function () use (
            $request,
            $operator,
            $targetUser,
            $slotNumber,
            $caseId,
            $reason,
            $idempotencyHash,
            $sessionHash,
            $ipHash,
            $email,
            $codeHash,
            $expiresAt,
            $account,
        ): TrustedBrowserChallenge {
            $lockedAccount = $this->lockAccount($account);
            $slots = $this->lockSlots($lockedAccount);
            $targetSlot = $slots->firstWhere('slot_number', $slotNumber);
            if (! $targetSlot instanceof TrustedBrowserSlot || ! in_array($targetSlot->status, TrustedBrowserSlot::OCCUPIED_STATUSES, true)) {
                throw new TrustedBrowserException(
                    'TRUSTED_BROWSER_SLOT_NOT_RELEASABLE',
                    'Этот слот нельзя освободить.',
                    409,
                );
            }

            $sameKey = TrustedBrowserChallenge::query()
                ->where('initiated_by_user_id', $operator->id)
                ->where('idempotency_key_hash', $idempotencyHash)
                ->lockForUpdate()
                ->first();
            if ($sameKey) {
                $this->assertSupportIdempotencyContext($sameKey, $lockedAccount, $slotNumber, $caseId, $reason);

                return $sameKey;
            }

            $this->assertSendAllowed($targetUser, 'support_release', $sessionHash, $ipHash);

            $challenge = TrustedBrowserChallenge::query()->create([
                'id' => (string) Str::uuid(),
                'account_id' => $lockedAccount->id,
                'user_id' => $targetUser->id,
                'purpose' => 'support_release',
                'target_slot_id' => $targetSlot->id,
                'initiated_by_user_id' => $operator->id,
                'session_hash' => $sessionHash,
                'email_hash' => $this->contextHash('email', mb_strtolower($email)),
                'code_hash' => $codeHash,
                'attempts_left' => (int) config('trusted_browser.otp.max_attempts', 5),
                'max_attempts' => (int) config('trusted_browser.otp.max_attempts', 5),
                'status' => 'pending',
                'expires_at' => $expiresAt,
                'send_ip_hash' => $ipHash,
                'case_id' => $caseId,
                'reason' => $reason,
                'idempotency_key_hash' => $idempotencyHash,
            ]);

            $this->audit($request, $lockedAccount, $targetUser, 'support.release_challenge_sent', [
                'actor' => $operator,
                'slot' => $targetSlot,
                'challenge' => $challenge,
                'metadata' => ['case_id' => $caseId],
            ]);

            return $challenge;
        }, 3);

        if ($challenge->wasRecentlyCreated) {
            $this->sendChallengeCode($email, $code, 'support_release');
        }

        return $this->challengePayload($challenge, $challenge->wasRecentlyCreated ? $code : null);
    }

    /**
     * @return array<string, mixed>
     */
    public function approveSupportRelease(Request $request, User $operator, string $challengeId): array
    {
        $this->assertReleasePermission($operator);
        $this->requireSessionHash($request);
        $challengeSnapshot = TrustedBrowserChallenge::query()
            ->whereKey($challengeId)
            ->where('purpose', 'support_release')
            ->first();
        if (! $challengeSnapshot) {
            throw $this->invalidChallengeException();
        }

        $account = TrustedBrowserAccount::query()->findOrFail($challengeSnapshot->account_id);

        return DB::transaction(function () use ($request, $operator, $challengeId, $account): array {
            $lockedAccount = $this->lockAccount($account);
            $slots = $this->lockSlots($lockedAccount);
            $challenge = TrustedBrowserChallenge::query()
                ->whereKey($challengeId)
                ->where('account_id', $lockedAccount->id)
                ->where('purpose', 'support_release')
                ->lockForUpdate()
                ->first();

            if (! $challenge || $challenge->status !== 'pending' || $challenge->expires_at->isPast()) {
                throw $this->invalidChallengeException();
            }
            if ($challenge->initiated_by_user_id === $operator->id) {
                throw new TrustedBrowserException(
                    'TRUSTED_BROWSER_SECOND_OPERATOR_REQUIRED',
                    'Подтверждение должен выполнить другой администратор.',
                    403,
                );
            }
            if ($challenge->approved_by_user_id !== null && $challenge->approved_by_user_id !== $operator->id) {
                throw new TrustedBrowserException(
                    'TRUSTED_BROWSER_ALREADY_APPROVED',
                    'Запрос уже подтверждён другим оператором.',
                    409,
                );
            }

            $targetSlot = $slots->firstWhere('id', $challenge->target_slot_id);
            if (! $targetSlot instanceof TrustedBrowserSlot) {
                throw $this->invalidChallengeException();
            }

            if ($challenge->approved_by_user_id === null) {
                $challenge->forceFill([
                    'approved_by_user_id' => $operator->id,
                    'approved_at' => now(),
                ])->save();
                $this->audit($request, $lockedAccount, $challenge->user, 'support.release_approved', [
                    'actor' => $operator,
                    'slot' => $targetSlot,
                    'challenge' => $challenge,
                    'metadata' => ['case_id' => $challenge->case_id],
                ]);
            }

            return [
                'status' => 'approved',
                'challenge_id' => $challenge->id,
                'case_id' => $challenge->case_id,
            ];
        }, 3);
    }

    /**
     * @return array<string, mixed>
     */
    public function verifySupportRelease(
        Request $request,
        User $operator,
        string $challengeId,
        string $code,
        string $idempotencyKey,
    ): array {
        $this->assertReleasePermission($operator);
        $challengeSnapshot = TrustedBrowserChallenge::query()
            ->whereKey($challengeId)
            ->where('purpose', 'support_release')
            ->first();
        if (! $challengeSnapshot) {
            throw $this->invalidChallengeException();
        }

        $account = TrustedBrowserAccount::query()->findOrFail($challengeSnapshot->account_id);
        $sessionHash = $this->requireSessionHash($request);
        $ipHash = $this->ipHash($request);
        $idempotencyHash = $this->contextHash('idempotency', $idempotencyKey);

        $outcome = DB::transaction(function () use (
            $request,
            $operator,
            $challengeId,
            $code,
            $account,
            $sessionHash,
            $ipHash,
            $idempotencyHash,
        ): array {
            $lockedAccount = $this->lockAccount($account);
            $slots = $this->lockSlots($lockedAccount);
            $challenge = TrustedBrowserChallenge::query()
                ->whereKey($challengeId)
                ->where('account_id', $lockedAccount->id)
                ->where('purpose', 'support_release')
                ->lockForUpdate()
                ->first();

            if (
                ! $challenge
                || $challenge->initiated_by_user_id !== $operator->id
                || ! hash_equals($challenge->session_hash, $sessionHash)
                || ! is_string($challenge->idempotency_key_hash)
                || ! hash_equals($challenge->idempotency_key_hash, $idempotencyHash)
            ) {
                return ['error' => $this->invalidChallengeException()];
            }

            if ($challenge->status === 'completed' && is_array($challenge->result_payload)) {
                return ['result' => $challenge->result_payload, 'notify' => false];
            }
            if ($challenge->status !== 'pending') {
                return ['error' => $this->invalidChallengeException()];
            }

            $rateError = $this->verifyRateError($challenge, $sessionHash, $ipHash);
            if ($rateError) {
                return ['error' => $rateError];
            }

            $this->audit($request, $lockedAccount, $challenge->user, 'challenge.verify_attempt', [
                'actor' => $operator,
                'slot' => $challenge->targetSlot,
                'challenge' => $challenge,
                'metadata' => ['purpose' => 'support_release', 'case_id' => $challenge->case_id],
            ]);

            if ($challenge->expires_at->isPast()) {
                $challenge->forceFill(['status' => 'expired', 'used_at' => now()])->save();

                return ['error' => $this->invalidChallengeException()];
            }

            if ($challenge->verified_at === null) {
                if (! Hash::check($code, $challenge->code_hash)) {
                    $attemptsLeft = max(0, $challenge->attempts_left - 1);
                    $challenge->forceFill([
                        'attempts_left' => $attemptsLeft,
                        'status' => $attemptsLeft === 0 ? 'failed' : 'pending',
                        'used_at' => $attemptsLeft === 0 ? now() : null,
                        'last_verify_ip_hash' => $ipHash,
                    ])->save();

                    return ['error' => $this->invalidChallengeException()];
                }

                $challenge->forceFill([
                    'verified_at' => now(),
                    'last_verify_ip_hash' => $ipHash,
                ])->save();
            }

            $approvedOperator = $challenge->approved_by_user_id
                ? User::query()->find($challenge->approved_by_user_id)
                : null;
            $hasSecondOperatorProof = $approvedOperator
                && $approvedOperator->id !== $operator->id
                && Rbac::hasPermission($approvedOperator, 'trusted-browser.release');
            $phoneProof = null;

            if (! $hasSecondOperatorProof) {
                $phoneProof = $this->freshPurposeBoundPhoneProof($challenge);
                if (! $phoneProof) {
                    return [
                        'error' => new TrustedBrowserException(
                            'TRUSTED_BROWSER_SUPPORT_SECOND_PROOF_REQUIRED',
                            'Нужно свежее подтверждение телефона владельца или подтверждение второго администратора.',
                            409,
                        ),
                    ];
                }

                $challenge->forceFill([
                    'phone_verification_code_id' => $phoneProof->id,
                    'phone_proof_bound_at' => now(),
                ])->save();
            }

            $targetSlot = $slots->firstWhere('id', $challenge->target_slot_id);
            if (! $targetSlot instanceof TrustedBrowserSlot || ! in_array($targetSlot->status, TrustedBrowserSlot::OCCUPIED_STATUSES, true)) {
                return [
                    'error' => new TrustedBrowserException(
                        'TRUSTED_BROWSER_SLOT_NOT_RELEASABLE',
                        'Этот слот нельзя освободить.',
                        409,
                    ),
                ];
            }

            $revocation = $this->invalidateSlotLinksLocked($targetSlot, 'support_release');
            $targetSlot->forceFill([
                'status' => 'released',
                'locked_at' => null,
                'released_at' => now(),
            ])->save();

            $result = [
                'status' => 'released',
                'target_user_id' => $challenge->user_id,
                'slot' => $targetSlot->slot_number,
                'slot_status' => 'released',
                'case_id' => $challenge->case_id,
            ];
            $challenge->forceFill([
                'status' => 'completed',
                'used_at' => now(),
                'result_payload' => $result,
            ])->save();

            $this->audit($request, $lockedAccount, $challenge->user, 'slot.released', [
                'actor' => $operator,
                'slot' => $targetSlot,
                'challenge' => $challenge,
                'metadata' => [
                    'case_id' => $challenge->case_id,
                    'reason' => $challenge->reason,
                    'proof' => $hasSecondOperatorProof ? 'second_operator' : 'phone_verification',
                    'second_operator_id' => $hasSecondOperatorProof ? $approvedOperator->id : null,
                ],
            ]);

            return [
                'result' => $result,
                'notify' => true,
                'target_user' => $challenge->user,
                'revocation' => $revocation,
            ];
        }, 3);

        if (($outcome['error'] ?? null) instanceof TrustedBrowserException) {
            throw $outcome['error'];
        }

        if (isset($outcome['revocation'])) {
            $this->finishSessionInvalidation($request, $outcome['revocation']);
        }
        if (($outcome['notify'] ?? false) && ($outcome['target_user'] ?? null) instanceof User) {
            $this->notifyOwnerAboutRelease($outcome['target_user'], $outcome['result']);
        }

        return $outcome['result'];
    }

    public function mode(): string
    {
        $mode = (string) config('trusted_browser.mode', 'off');

        return in_array($mode, ['off', 'observe', 'enforce'], true) ? $mode : 'off';
    }

    private function slotLimit(): int
    {
        return 3;
    }

    private function accountFor(User $user): TrustedBrowserAccount
    {
        $existing = TrustedBrowserAccount::query()->where('user_id', $user->id)->first();
        if ($existing) {
            return $existing;
        }

        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                return TrustedBrowserAccount::query()->create([
                    'user_id' => $user->id,
                    'cookie_key' => bin2hex(random_bytes(16)),
                ]);
            } catch (QueryException $exception) {
                $existing = TrustedBrowserAccount::query()->where('user_id', $user->id)->first();
                if ($existing) {
                    return $existing;
                }

                if ($attempt === 2) {
                    throw $exception;
                }
            }
        }

        throw new TrustedBrowserException('TRUSTED_BROWSER_ACCOUNT_ERROR', 'Не удалось подготовить настройки браузеров.', 500);
    }

    private function lockAccount(TrustedBrowserAccount $account): TrustedBrowserAccount
    {
        return TrustedBrowserAccount::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();
    }

    /**
     * @return Collection<int, TrustedBrowserSlot>
     */
    private function lockSlots(TrustedBrowserAccount $account): Collection
    {
        return TrustedBrowserSlot::query()
            ->where('account_id', $account->id)
            ->orderBy('slot_number')
            ->lockForUpdate()
            ->get();
    }

    /**
     * @param  Collection<int, TrustedBrowserSlot>  $slots
     */
    private function occupiedCount(Collection $slots): int
    {
        return $slots->whereIn('status', TrustedBrowserSlot::OCCUPIED_STATUSES)->count();
    }

    /**
     * @return array{0: TrustedBrowserCredential|null, 1: array{selector: string, secret: string, value: string}|null}
     */
    private function inspectCredential(Request $request, TrustedBrowserAccount $account): array
    {
        $parsed = $this->cookie->read($request, $account);
        if (! $parsed) {
            return [null, null];
        }

        $credential = TrustedBrowserCredential::query()
            ->with('slot')
            ->where('selector', $parsed['selector'])
            ->first();
        $expectedHash = $credential?->secret_hash ?? hash('sha256', 'trusted-browser-dummy-secret');
        $actualHash = hash('sha256', $parsed['secret']);
        $secretMatches = hash_equals($expectedHash, $actualHash);

        if (
            ! $secretMatches
            || ! $credential
            || ! $credential->slot
            || $credential->slot->account_id !== $account->id
        ) {
            return [null, null];
        }

        return [$credential, $parsed];
    }

    /**
     * @param  Collection<int, TrustedBrowserSlot>  $slots
     * @param  array<string, mixed>  $metadata
     * @return array{slot_number: int, credential_id: int, cookie_value: string}
     */
    private function enrollLocked(
        Request $request,
        TrustedBrowserAccount $account,
        Collection $slots,
        User $user,
        ?string $sessionId,
        array $metadata,
        TrustedBrowserChallenge $challenge,
    ): array {
        if ($sessionId === null) {
            throw new TrustedBrowserException('TRUSTED_BROWSER_SESSION_REQUIRED', 'Требуется браузерная сессия.', 401);
        }

        $slot = null;
        for ($slotNumber = 1; $slotNumber <= $this->slotLimit(); $slotNumber++) {
            $candidate = $slots->firstWhere('slot_number', $slotNumber);
            if (! $candidate || $candidate->status === 'released') {
                $slot = $candidate;
                break;
            }
        }

        if ($slot instanceof TrustedBrowserSlot) {
            $generation = ((int) TrustedBrowserCredential::query()->where('slot_id', $slot->id)->max('generation')) + 1;
        } else {
            $slotNumber = collect(range(1, $this->slotLimit()))
                ->first(fn (int $number): bool => ! $slots->contains('slot_number', $number));
            if (! is_int($slotNumber)) {
                throw $this->stateException('unknown_support_required');
            }
            $slot = TrustedBrowserSlot::query()->create([
                'account_id' => $account->id,
                'slot_number' => $slotNumber,
                'status' => 'active',
                'current_generation' => null,
            ]);
            $generation = 1;
        }

        $material = $this->cookie->createCredential();
        $credential = TrustedBrowserCredential::query()->create([
            'slot_id' => $slot->id,
            'generation' => $generation,
            'selector' => $material['selector'],
            'secret_hash' => $material['secret_hash'],
            'expires_at' => now()->addDays((int) config('trusted_browser.credential.rolling_expiry_days', 400)),
            'last_refreshed_at' => now(),
            'last_seen_at' => now(),
            'browser_label' => $metadata['browser_label'] ?? null,
            'os_label' => $metadata['os_label'] ?? null,
            'device_label' => $metadata['device_label'] ?? null,
            'user_agent_hash' => $this->userAgentHash($request),
            'issued_ip_hash' => $this->ipHash($request),
            'last_ip_hash' => $this->ipHash($request),
        ]);
        $slot->forceFill([
            'status' => 'active',
            'current_generation' => $generation,
            'locked_at' => null,
            'released_at' => null,
        ])->save();
        TrustedBrowserSessionLink::query()->updateOrCreate(
            ['session_id' => $sessionId],
            [
                'account_id' => $account->id,
                'slot_id' => $slot->id,
                'credential_id' => $credential->id,
                'bound_at' => now(),
                'last_seen_at' => now(),
                'revoked_at' => null,
            ],
        );

        $this->audit($request, $account, $user, 'slot.enrolled', [
            'slot' => $slot,
            'credential' => $credential,
            'challenge' => $challenge,
            'metadata' => ['generation' => $generation],
        ]);

        return [
            'slot_number' => $slot->slot_number,
            'credential_id' => $credential->id,
            'cookie_value' => $material['value'],
        ];
    }

    /**
     * @return array{slot_number: int, invalidated_session_ids: array<int, string>, forget_cookie: bool}
     */
    private function revokeLocked(
        Request $request,
        TrustedBrowserAccount $account,
        TrustedBrowserSlot $slot,
        User $actor,
        string $reason,
        ?TrustedBrowserCredential $inspectedCredential,
        ?TrustedBrowserChallenge $challenge = null,
    ): array {
        $result = $this->invalidateSlotLinksLocked($slot, $reason);
        $slot->forceFill([
            'status' => 'revoked_locked',
            'locked_at' => now(),
            'released_at' => null,
        ])->save();

        $this->audit($request, $account, $account->user, 'slot.revoked', [
            'actor' => $actor,
            'slot' => $slot,
            'challenge' => $challenge,
            'metadata' => ['reason' => $reason],
        ]);

        return [
            'slot_number' => $slot->slot_number,
            'invalidated_session_ids' => $result['invalidated_session_ids'],
            'forget_cookie' => $inspectedCredential?->slot_id === $slot->id,
        ];
    }

    /**
     * @return array{invalidated_session_ids: array<int, string>}
     */
    private function invalidateSlotLinksLocked(TrustedBrowserSlot $slot, string $reason): array
    {
        if ($slot->current_generation !== null) {
            TrustedBrowserCredential::query()
                ->where('slot_id', $slot->id)
                ->where('generation', $slot->current_generation)
                ->whereNull('invalidated_at')
                ->update([
                    'invalidated_at' => now(),
                    'invalidation_reason' => $reason,
                    'updated_at' => now(),
                ]);
        }

        $sessionIds = TrustedBrowserSessionLink::query()
            ->where('slot_id', $slot->id)
            ->whereNull('revoked_at')
            ->pluck('session_id')
            ->filter(fn (mixed $sessionId): bool => is_string($sessionId) && $sessionId !== '')
            ->values()
            ->all();
        TrustedBrowserSessionLink::query()
            ->where('slot_id', $slot->id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'updated_at' => now()]);

        if ($sessionIds !== []) {
            DB::table('sessions')->whereIn('id', $sessionIds)->delete();
        }

        return ['invalidated_session_ids' => $sessionIds];
    }

    /**
     * @param  array{invalidated_session_ids: array<int, string>}  $revocation
     */
    private function finishSessionInvalidation(Request $request, array $revocation): void
    {
        $sessionId = $this->sessionId($request);
        if ($sessionId !== null && in_array($sessionId, $revocation['invalidated_session_ids'], true)) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }
    }

    private function verifiedEmail(User $user): string
    {
        $email = mb_strtolower(trim((string) $user->email));
        if (! $user->email_verified_at || $email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new TrustedBrowserException(
                'TRUSTED_BROWSER_VERIFIED_EMAIL_REQUIRED',
                'Для подтверждения браузера нужен подтверждённый email.',
                422,
            );
        }

        return $email;
    }

    private function generateOtp(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    private function assertSendAllowed(User $user, string $purpose, string $sessionHash, string $ipHash): void
    {
        $windowStart = now()->subMinutes((int) config('trusted_browser.rate_limits.window_minutes', 15));
        $base = TrustedBrowserChallenge::query()->where('created_at', '>=', $windowStart);
        $limits = [
            [(clone $base)->where('user_id', $user->id)->count(), (int) config('trusted_browser.rate_limits.send_per_user', 5)],
            [(clone $base)->where('session_hash', $sessionHash)->count(), (int) config('trusted_browser.rate_limits.send_per_session', 5)],
            [(clone $base)->where('send_ip_hash', $ipHash)->count(), (int) config('trusted_browser.rate_limits.send_per_ip', 10)],
        ];
        foreach ($limits as [$count, $limit]) {
            if ($count >= $limit) {
                throw $this->rateLimitException();
            }
        }

        $lastIssuedAt = TrustedBrowserChallenge::query()
            ->where('user_id', $user->id)
            ->where('purpose', $purpose)
            ->latest('created_at')
            ->value('created_at');
        if ($lastIssuedAt) {
            $nextAllowedAt = Carbon::parse($lastIssuedAt)
                ->addSeconds((int) config('trusted_browser.otp.resend_cooldown_seconds', 60));
            if ($nextAllowedAt->isFuture()) {
                throw $this->rateLimitException((int) max(1, now()->diffInSeconds($nextAllowedAt)));
            }
        }
    }

    private function supersedePendingUserChallenges(
        Request $request,
        TrustedBrowserAccount $account,
        User $user,
        string $purpose,
        string $sessionHash,
        ?string $exceptChallengeId = null,
    ): void {
        $query = TrustedBrowserChallenge::query()
            ->where('account_id', $account->id)
            ->where('user_id', $user->id)
            ->where('purpose', $purpose)
            ->where('session_hash', $sessionHash)
            ->where('status', 'pending');

        if ($exceptChallengeId !== null) {
            $query->where('id', '<>', $exceptChallengeId);
        }

        /** @var Collection<int, TrustedBrowserChallenge> $pendingChallenges */
        $pendingChallenges = $query->lockForUpdate()->get();

        foreach ($pendingChallenges as $pendingChallenge) {
            $pendingChallenge->forceFill([
                'status' => 'failed',
                'used_at' => now(),
            ])->save();
            $this->audit($request, $account, $user, 'challenge.superseded', [
                'challenge' => $pendingChallenge,
                'metadata' => ['purpose' => $purpose],
            ]);
        }
    }

    private function verifyRateError(
        TrustedBrowserChallenge $challenge,
        string $sessionHash,
        string $ipHash,
    ): ?TrustedBrowserException {
        $windowStart = now()->subMinutes((int) config('trusted_browser.rate_limits.window_minutes', 15));
        $base = TrustedBrowserAuditEvent::query()
            ->where('event_type', 'challenge.verify_attempt')
            ->where('created_at', '>=', $windowStart);
        $limits = [
            [(clone $base)->where('user_id', $challenge->user_id)->count(), (int) config('trusted_browser.rate_limits.verify_per_user', 10)],
            [(clone $base)->where('session_hash', $sessionHash)->count(), (int) config('trusted_browser.rate_limits.verify_per_session', 10)],
            [(clone $base)->where('ip_hash', $ipHash)->count(), (int) config('trusted_browser.rate_limits.verify_per_ip', 20)],
        ];
        foreach ($limits as [$count, $limit]) {
            if ($count >= $limit) {
                return $this->rateLimitException();
            }
        }

        return null;
    }

    private function rateLimitException(?int $retryAfter = null): TrustedBrowserException
    {
        $meta = $retryAfter === null ? [] : ['retry_after' => $retryAfter];

        return new TrustedBrowserException(
            'TRUSTED_BROWSER_RATE_LIMIT',
            $retryAfter === null
                ? 'Слишком много попыток. Попробуйте позже.'
                : "Повторите через {$retryAfter} сек.",
            429,
            $meta,
        );
    }

    private function invalidChallengeException(): TrustedBrowserException
    {
        return new TrustedBrowserException(
            'TRUSTED_BROWSER_CHALLENGE_INVALID',
            'Неверный или истёкший код. Запросите новый.',
            422,
        );
    }

    private function stateException(string $state): TrustedBrowserException
    {
        return match ($state) {
            'unknown_support_required', 'revoked_current' => new TrustedBrowserException(
                self::SUPPORT_REQUIRED_CODE,
                'Для восстановления доступа обратитесь в поддержку.',
                409,
            ),
            'risk_step_up_required' => new TrustedBrowserException(
                self::RISK_LOCKED_CODE,
                'Доступ временно ограничен. Требуется дополнительная проверка.',
                403,
            ),
            'trusted' => new TrustedBrowserException(
                'TRUSTED_BROWSER_ALREADY_TRUSTED',
                'Этот браузер уже подтверждён.',
                409,
            ),
            default => new TrustedBrowserException(
                self::REQUIRED_CODE,
                'Подтвердите этот браузер для доступа к защищённым материалам.',
                403,
            ),
        };
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, string>|null
     */
    private function displayMetadata(array $input): ?array
    {
        $metadata = [];
        foreach (['browser_label', 'os_label', 'device_label'] as $key) {
            $value = trim((string) ($input[$key] ?? ''));
            if ($value !== '') {
                $metadata[$key] = mb_substr($value, 0, 80);
            }
        }

        return $metadata === [] ? null : $metadata;
    }

    /**
     * @return array<string, mixed>
     */
    private function challengePayload(TrustedBrowserChallenge $challenge, ?string $debugCode = null): array
    {
        $payload = [
            'status' => $challenge->status,
            'challenge_id' => $challenge->id,
            'purpose' => $challenge->purpose,
            'expires_at' => $challenge->expires_at->toISOString(),
        ];
        if ($challenge->case_id !== null) {
            $payload['case_id'] = $challenge->case_id;
        }
        if ($debugCode !== null && $this->shouldExposeDebugCode()) {
            $payload['debug_code'] = $debugCode;
        }

        return $payload;
    }

    private function shouldExposeDebugCode(): bool
    {
        return in_array((string) config('app.env'), ['local', 'testing'], true);
    }

    private function sendChallengeCode(string $email, string $code, string $purpose): void
    {
        $subject = $purpose === 'support_release'
            ? 'Подтверждение освобождения доверенного браузера'
            : 'Подтверждение доверенного браузера';
        $title = $purpose === 'revoke'
            ? 'Код аварийного отзыва браузера'
            : ($purpose === 'support_release'
                ? 'Код для обращения в поддержку'
                : 'Код подтверждения браузера');
        $ttl = (int) config('trusted_browser.otp.ttl_minutes', 10);
        $html = '<p>'.$title.': <b>'.$code.'</b></p><p>Код действует '.$ttl.' минут.</p>';

        if (! $this->smtpBzApiClient->sendHtmlMessage($email, $subject, $html)) {
            Mail::raw(
                "{$title}: {$code}\nКод действует {$ttl} минут.",
                static function ($message) use ($email, $subject): void {
                    $message->to($email)->subject($subject);
                },
            );
        }
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function notifyOwnerAboutRelease(User $user, array $result): void
    {
        $email = mb_strtolower(trim((string) $user->email));
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        $subject = 'Доверенный браузер освобождён поддержкой';
        $slot = (int) ($result['slot'] ?? 0);
        $caseId = (string) ($result['case_id'] ?? '');
        $text = "Поддержка освободила слот доверенного браузера №{$slot}. Номер обращения: {$caseId}.";

        try {
            if (! $this->smtpBzApiClient->sendHtmlMessage($email, $subject, '<p>'.e($text).'</p>')) {
                Mail::raw($text, static function ($message) use ($email, $subject): void {
                    $message->to($email)->subject($subject);
                });
            }
        } catch (Throwable) {
            Log::warning('trusted_browser.owner_notification_failed', [
                'user_id' => $user->id,
                'slot' => $slot,
            ]);
        }
    }

    private function assertReleasePermission(User $operator): void
    {
        if (! Rbac::hasPermission($operator, 'trusted-browser.release')) {
            throw new TrustedBrowserException('AUTH_FORBIDDEN', 'Forbidden', 403);
        }
    }

    private function assertSupportIdempotencyContext(
        TrustedBrowserChallenge $challenge,
        TrustedBrowserAccount $account,
        int $slotNumber,
        string $caseId,
        string $reason,
    ): void {
        $slot = TrustedBrowserSlot::query()->find($challenge->target_slot_id);
        if (
            $challenge->purpose !== 'support_release'
            || $challenge->account_id !== $account->id
            || ! $slot
            || $slot->slot_number !== $slotNumber
            || ! hash_equals((string) $challenge->case_id, $caseId)
            || ! hash_equals((string) $challenge->reason, $reason)
        ) {
            throw new TrustedBrowserException(
                'TRUSTED_BROWSER_IDEMPOTENCY_CONFLICT',
                'Этот ключ идемпотентности уже использован с другими параметрами.',
                409,
            );
        }
    }

    private function freshPurposeBoundPhoneProof(TrustedBrowserChallenge $challenge): ?object
    {
        if (
            $challenge->purpose !== 'support_release'
            || $challenge->target_slot_id === null
            || trim((string) $challenge->case_id) === ''
        ) {
            return null;
        }

        $targetUser = $challenge->user;
        if (! $targetUser instanceof User) {
            return null;
        }

        $phone = (string) $targetUser->phone;
        if ($phone === '') {
            return null;
        }

        $notBefore = $challenge->created_at->greaterThan(now()->subMinutes(
            (int) config('trusted_browser.support.phone_proof_ttl_minutes', 10),
        ))
            ? $challenge->created_at
            : now()->subMinutes((int) config('trusted_browser.support.phone_proof_ttl_minutes', 10));

        return DB::table('phone_verification_codes')
            ->where('user_id', $targetUser->id)
            ->where('phone', $phone)
            ->where('status', 'confirmed')
            ->whereNotNull('confirmed_at')
            ->whereBetween('confirmed_at', [$notBefore, now()])
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('trusted_browser_challenges as used_challenges')
                    ->whereColumn(
                        'used_challenges.phone_verification_code_id',
                        'phone_verification_codes.id',
                    );
            })
            ->latest('confirmed_at')
            ->lockForUpdate()
            ->first();
    }

    private function sessionId(Request $request): ?string
    {
        if (! $request->hasSession()) {
            return null;
        }

        $sessionId = $request->session()->getId();

        return is_string($sessionId) && $sessionId !== '' ? $sessionId : null;
    }

    private function requireSessionHash(Request $request): string
    {
        $sessionId = $this->sessionId($request);
        if ($sessionId === null) {
            throw new TrustedBrowserException('TRUSTED_BROWSER_SESSION_REQUIRED', 'Требуется браузерная сессия.', 401);
        }

        return $this->contextHash('session', $sessionId);
    }

    private function ipHash(Request $request): string
    {
        return $this->contextHash('ip', (string) ($request->ip() ?? 'unknown'));
    }

    private function userAgentHash(Request $request): ?string
    {
        $userAgent = trim((string) $request->userAgent());

        return $userAgent === '' ? null : $this->contextHash('user-agent', $userAgent);
    }

    private function contextHash(string $purpose, string $value): string
    {
        $key = (string) config('app.key', '');

        return hash_hmac('sha256', $purpose."\0".$value, $key);
    }

    /**
     * @param  array{
     *     actor?: User,
     *     slot?: TrustedBrowserSlot|null,
     *     credential?: TrustedBrowserCredential|null,
     *     challenge?: TrustedBrowserChallenge|null,
     *     challenge_id?: string|null,
     *     mode?: string|null,
     *     state?: string|null,
     *     metadata?: array<string, mixed>|null
     * }  $context
     */
    private function audit(
        Request $request,
        TrustedBrowserAccount $account,
        User $targetUser,
        string $eventType,
        array $context = [],
    ): void {
        $actor = $context['actor'] ?? $targetUser;
        $slot = $context['slot'] ?? null;
        $credential = $context['credential'] ?? null;
        $challenge = $context['challenge'] ?? null;
        $sessionId = $this->sessionId($request);

        TrustedBrowserAuditEvent::query()->create([
            'account_id' => $account->id,
            'user_id' => $targetUser->id,
            'actor_user_id' => $actor instanceof User ? $actor->id : null,
            'slot_id' => $slot instanceof TrustedBrowserSlot ? $slot->id : null,
            'credential_id' => $credential instanceof TrustedBrowserCredential ? $credential->id : null,
            'challenge_id' => $challenge instanceof TrustedBrowserChallenge
                ? $challenge->id
                : ($context['challenge_id'] ?? null),
            'event_type' => $eventType,
            'mode' => $context['mode'] ?? null,
            'state' => $context['state'] ?? null,
            'session_hash' => $sessionId === null ? null : $this->contextHash('session', $sessionId),
            'ip_hash' => $this->ipHash($request),
            'metadata' => $context['metadata'] ?? null,
            'created_at' => now(),
        ]);
    }
}
