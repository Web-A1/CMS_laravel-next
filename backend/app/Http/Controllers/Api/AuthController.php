<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SendPreRegistrationEmailCodeJob;
use App\Jobs\StartPreRegistrationPhoneVerificationJob;
use App\Models\User;
use App\Models\VideoWatermarkSession;
use App\Services\AdminImpersonationService;
use App\Services\RedsmsApiClient;
use App\Services\SessionTermination;
use App\Services\SmtpBzApiClient;
use App\Services\TrustedBrowser\TrustedBrowserService;
use App\Support\ImpersonationContext;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\IpUtils;
use Throwable;

class AuthController extends Controller
{
    private const LOGIN_CODE_TTL_MINUTES = 10;

    private const PHONE_VERIFY_CODE_TTL_MINUTES = 10;

    private const RESEND_COOLDOWN_SECONDS = 30;

    private const PREVERIFY_TTL_MINUTES = 5;

    private const PREVERIFY_RESEND_COOLDOWN_SECONDS = 60;

    private const PREVERIFY_START_LIMIT_PER_WINDOW = 5;

    private const PREVERIFY_START_LIMIT_WINDOW_MINUTES = 15;

    private const PREVERIFY_EMAIL_ATTEMPTS = 10;

    private const PRIVACY_POLICY_VERSION = 'placeholder-2026-05';

    private const OFFER_VERSION = 'placeholder-2026-05';

    public function __construct(
        private readonly SmtpBzApiClient $smtpBzApiClient,
        private readonly RedsmsApiClient $redsmsApiClient,
        private readonly TrustedBrowserService $trustedBrowsers,
        private readonly SessionTermination $sessionTermination,
        private readonly AdminImpersonationService $impersonation,
    ) {}

    private function migrationHoldResponse(): JsonResponse
    {
        return $this->errorResponse(
            'AUTH_ACCOUNT_MIGRATION_HELD',
            'Аккаунт импортирован для проверки данных. Вход будет включён отдельно.',
            403
        );
    }

    private function migrationHeldUserByEmail(string $email): ?User
    {
        return User::query()
            ->where('email', $email)
            ->where(function ($query): void {
                $query->where('migration_hold', true)
                    ->orWhere('password_state', User::PASSWORD_STATE_MIGRATION_HOLD);
            })
            ->first();
    }

    private function migrationHeldUserByPhone(string $phone): ?User
    {
        return User::query()
            ->where('phone', $phone)
            ->where(function ($query): void {
                $query->where('migration_hold', true)
                    ->orWhere('password_state', User::PASSWORD_STATE_MIGRATION_HOLD);
            })
            ->first();
    }

    private function phoneVerificationRowBelongsToHeldUser(object $row): bool
    {
        return User::query()
            ->whereKey((int) $row->user_id)
            ->where(function ($query): void {
                $query->where('migration_hold', true)
                    ->orWhere('password_state', User::PASSWORD_STATE_MIGRATION_HOLD);
            })
            ->exists();
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'login' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'max:255'],
        ]);

        [$channel, $target] = $this->resolveLoginChannel((string) $data['login']);
        if (! $channel || ! $target) {
            return $this->errorResponse('AUTH_INVALID_CREDENTIALS', 'Неверный логин или пароль.', 401);
        }

        $user = User::query()->where($channel, $target)->first();
        if ($channel === 'username' && ! $user?->usesAdminWorkspace()) {
            $user = null;
        }

        if ($user instanceof User && $user->blocksAuthenticationForMigration()) {
            return $this->migrationHoldResponse();
        }

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            return $this->errorResponse('AUTH_INVALID_CREDENTIALS', 'Неверный логин или пароль.', 401);
        }

        $user->forceFill(['last_auth_method' => $channel])->save();
        $nextStep = $user->usesAdminWorkspace() ? 'admin' : 'lk';
        Auth::guard('web')->login($user);
        $this->regenerateSessionIfAvailable($request);
        $this->trustedBrowsers->rebindCurrentSession($request, $user);

        return response()->json([
            'status' => 'ok',
            'next_step' => $nextStep,
            'user' => $user->only(['id', 'name', 'email', 'phone', 'role']),
        ]);
    }

    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'max:255'],
            'email_verification_token' => ['required', 'string', 'max:255'],
            'privacy_policy_accepted' => ['accepted'],
            'offer_accepted' => ['accepted'],
            'marketing_accepted' => ['sometimes', 'boolean'],
        ]);

        $email = mb_strtolower((string) $data['email']);
        $existingEmailUser = User::query()->where('email', $email)->first();
        if ($existingEmailUser) {
            if ($existingEmailUser->blocksAuthenticationForMigration()) {
                return $this->migrationHoldResponse();
            }

            return $this->errorResponse('AUTH_EMAIL_EXISTS', 'Пользователь с таким email уже существует.', 409);
        }
        try {
            $user = DB::transaction(function () use ($request, $data, $email): User {
                if (! $this->consumePreRegistrationVerificationToken('email', $email, (string) $data['email_verification_token'])) {
                    throw new HttpResponseException($this->errorResponse('VERIFY_EMAIL_REQUIRED', 'Сначала подтвердите email.', 422));
                }

                $user = User::create([
                    // The legacy schema keeps name non-null; registration no longer asks for it.
                    'name' => Str::before($email, '@') ?: 'Пользователь',
                    'email' => $email,
                    'phone' => null,
                    'password' => $data['password'],
                    'role' => 'user_no_purchase',
                    'email_verified_at' => now(),
                    'phone_verified_at' => null,
                ]);

                DB::table('registration_consents')->insert([
                    'user_id' => $user->id,
                    'privacy_policy_version' => self::PRIVACY_POLICY_VERSION,
                    'offer_version' => self::OFFER_VERSION,
                    'privacy_policy_accepted_at' => now(),
                    'offer_accepted_at' => now(),
                    'marketing_opt_in' => (bool) ($data['marketing_accepted'] ?? false),
                    'marketing_accepted_at' => (bool) ($data['marketing_accepted'] ?? false) ? now() : null,
                    'ip_address' => $request->ip(),
                    'user_agent' => mb_substr((string) $request->userAgent(), 0, 1024),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                return $user;
            });
        } catch (QueryException $e) {
            if ($this->isUniqueConstraintViolation($e)) {
                return $this->errorResponse('AUTH_EMAIL_EXISTS', 'Пользователь с таким email уже существует.', 409);
            }

            throw $e;
        }

        Auth::guard('web')->login($user);
        $this->regenerateSessionIfAvailable($request);
        $this->trustedBrowsers->rebindCurrentSession($request, $user);

        return response()->json([
            'status' => 'created',
            'user_id' => $user->id,
            'next_step' => 'lk',
        ], 201);
    }

    public function logout(Request $request): JsonResponse
    {
        return $this->logoutWithWatermarkReason($request, VideoWatermarkSession::END_REASON_LOGOUT);
    }

    public function idleLogout(Request $request): JsonResponse
    {
        return $this->logoutWithWatermarkReason($request, VideoWatermarkSession::END_REASON_IDLE_TIMEOUT);
    }

    private function logoutWithWatermarkReason(Request $request, string $reason): JsonResponse
    {
        $this->sessionTermination->terminate($request, $reason);

        return response()->json(['status' => 'ok']);
    }

    public function startPreRegistrationEmailVerification(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        $email = mb_strtolower((string) $data['email']);
        $existingUser = User::query()->where('email', $email)->first();
        if ($existingUser) {
            if ($existingUser->blocksAuthenticationForMigration()) {
                return $this->migrationHoldResponse();
            }

            return $this->errorResponse('AUTH_EMAIL_EXISTS', 'Пользователь с таким email уже существует.', 409);
        }

        $cooldown = $this->secondsUntilNextPreVerificationCode('email', $email);
        if ($cooldown > 0) {
            return $this->errorResponse('VERIFY_RATE_LIMIT', "Повторный запрос через {$cooldown} сек.", 429, [
                'retry_after' => $cooldown,
            ]);
        }
        if ($this->countRecentPreVerificationStarts('email', $email) >= self::PREVERIFY_START_LIMIT_PER_WINDOW) {
            return $this->errorResponse('VERIFY_RATE_LIMIT', 'Слишком много попыток. Попробуйте позже.', 429);
        }

        $code = (string) random_int(100000, 999999);
        $verificationId = (string) Str::uuid();
        DB::table('pre_registration_verifications')->insert([
            'verification_id' => $verificationId,
            'channel' => 'email',
            'target' => $email,
            'code_hash' => Hash::make($code),
            'attempts_left' => self::PREVERIFY_EMAIL_ATTEMPTS,
            'status' => 'pending',
            'expires_at' => now()->addMinutes(self::PREVERIFY_TTL_MINUTES),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        SendPreRegistrationEmailCodeJob::dispatch($email, $code, self::PREVERIFY_TTL_MINUTES);

        return response()->json([
            'status' => 'pending',
            'verification_id' => $verificationId,
            'expires_at' => now()->addMinutes(self::PREVERIFY_TTL_MINUTES)->toISOString(),
            'debug_code' => $this->shouldExposeDebugCode() ? $code : null,
        ], 202);
    }

    public function verifyPreRegistrationEmailCode(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'code' => ['required', 'string', 'size:6'],
        ]);

        $email = mb_strtolower((string) $data['email']);
        if ($this->migrationHeldUserByEmail($email)) {
            return $this->migrationHoldResponse();
        }

        $row = DB::table('pre_registration_verifications')
            ->where('channel', 'email')
            ->where('target', $email)
            ->where('status', 'pending')
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->latest('id')
            ->first();
        if (! $row || ! is_string($row->code_hash)) {
            return $this->errorResponse('VERIFY_CODE_INVALID', 'Неверный код или код истек. Запросите новый.', 422);
        }

        if (! Hash::check((string) $data['code'], $row->code_hash)) {
            $attemptsLeft = max(0, ((int) $row->attempts_left) - 1);
            DB::table('pre_registration_verifications')->where('id', $row->id)->update([
                'attempts_left' => $attemptsLeft,
                'consumed_at' => $attemptsLeft === 0 ? now() : null,
                'status' => $attemptsLeft === 0 ? 'failed' : 'pending',
                'updated_at' => now(),
            ]);

            return $this->errorResponse('VERIFY_CODE_INVALID', 'Неверный код или код истек. Запросите новый.', 422);
        }

        $verificationToken = Str::random(64);
        DB::table('pre_registration_verifications')->where('id', $row->id)->update([
            'status' => 'confirmed',
            'confirmed_at' => now(),
            'verification_token_hash' => Hash::make($verificationToken),
            'verification_token_expires_at' => now()->addMinutes(self::PREVERIFY_TTL_MINUTES),
            'updated_at' => now(),
        ]);

        return response()->json([
            'status' => 'confirmed',
            'verification_token' => $verificationToken,
            'expires_at' => now()->addMinutes(self::PREVERIFY_TTL_MINUTES)->toISOString(),
        ]);
    }

    public function startPreRegistrationPhoneVerification(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:30'],
        ]);

        $phone = User::normalizePhone($data['phone']);
        if (! User::isValidE164Phone($phone)) {
            return $this->errorResponse('AUTH_PHONE_INVALID', 'Введите корректный номер телефона.', 422);
        }
        $existingUser = User::query()->where('phone', $phone)->first();
        if ($existingUser) {
            if ($existingUser->blocksAuthenticationForMigration()) {
                return $this->migrationHoldResponse();
            }

            return $this->errorResponse('AUTH_PHONE_EXISTS', 'Пользователь с таким телефоном уже существует.', 409);
        }

        $cooldown = $this->secondsUntilNextPreVerificationCode('phone', $phone);
        if ($cooldown > 0) {
            return $this->errorResponse('VERIFY_RATE_LIMIT', "Повторный запрос через {$cooldown} сек.", 429, [
                'retry_after' => $cooldown,
            ]);
        }
        if ($this->countRecentPreVerificationStarts('phone', $phone) >= self::PREVERIFY_START_LIMIT_PER_WINDOW) {
            return $this->errorResponse('VERIFY_RATE_LIMIT', 'Слишком много попыток. Попробуйте позже.', 429);
        }

        $verificationId = (string) Str::uuid();
        $statusToken = Str::random(64);
        DB::table('pre_registration_verifications')->insert([
            'verification_id' => $verificationId,
            'channel' => 'phone',
            'target' => $phone,
            'attempts_left' => 1,
            'status' => 'pending',
            'status_token_hash' => Hash::make($statusToken),
            'expires_at' => now()->addMinutes(self::PREVERIFY_TTL_MINUTES),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        StartPreRegistrationPhoneVerificationJob::dispatch($verificationId, $phone, self::PREVERIFY_TTL_MINUTES);

        return response()->json([
            'status' => 'pending',
            'verification_id' => $verificationId,
            'status_token' => $statusToken,
            'call_number' => null,
            'expires_at' => now()->addMinutes(self::PREVERIFY_TTL_MINUTES)->toISOString(),
        ], 202);
    }

    public function preRegistrationPhoneVerificationStatus(Request $request): JsonResponse
    {
        $data = $request->validate([
            'verification_id' => ['required', 'uuid'],
            'status_token' => ['required', 'string', 'min:32', 'max:255'],
        ]);

        $row = DB::table('pre_registration_verifications')
            ->where('channel', 'phone')
            ->where('verification_id', $data['verification_id'])
            ->first();
        if (! $row) {
            return $this->errorResponse('VERIFY_NOT_FOUND', 'Верификация не найдена.', 404);
        }
        if (! is_string($row->status_token_hash) || ! Hash::check((string) $data['status_token'], $row->status_token_hash)) {
            return $this->errorResponse('VERIFY_NOT_FOUND', 'Верификация не найдена.', 404);
        }
        if ($this->migrationHeldUserByPhone((string) $row->target)) {
            return $this->migrationHoldResponse();
        }
        $status = (string) $row->status;

        return response()->json([
            'status' => $status,
            'verification_token' => $status === 'confirmed' ? (string) $row->verification_id : null,
            'call_number' => $status === 'pending' ? $row->call_number : null,
            'expires_at' => Carbon::parse((string) $row->expires_at)->toISOString(),
        ]);
    }

    public function preRegistrationPhoneVerificationCallback(Request $request): JsonResponse
    {
        $sourceError = $this->validateRedsmsWebhookSource($request);
        if ($sourceError) {
            return $sourceError;
        }

        $data = $request->validate([
            'verification_id' => ['nullable', 'uuid'],
            'provider_event_id' => ['nullable', 'string', 'max:120'],
            'provider_uuid' => ['nullable', 'string', 'max:120'],
            'uuid' => ['nullable', 'string', 'max:120'],
            'status' => ['required', 'string', 'max:64'],
        ]);

        $providerUuid = $this->resolveProviderUuidFromCallbackPayload($data);
        $row = $this->findPreRegistrationPhoneVerificationRow($data['verification_id'] ?? null, $providerUuid);
        if (! $row) {
            return $this->errorResponse('VERIFY_NOT_FOUND', 'Верификация не найдена.', 404);
        }
        if ($this->migrationHeldUserByPhone((string) $row->target)) {
            return response()->json(['status' => 'accepted']);
        }
        $providerEventId = (string) ($data['provider_event_id'] ?? ($providerUuid !== '' ? $providerUuid : 'evt_'.Str::uuid()));
        if (is_string($row->provider_event_id) && $row->provider_event_id !== '' && $row->provider_event_id === $providerEventId) {
            return response()->json(['status' => 'accepted']);
        }

        $this->applyPreRegistrationPhoneVerificationResult(
            (string) $row->verification_id,
            $providerEventId,
            $this->mapRedsmsStatusToVerificationStatus((string) $data['status']),
            $request->all()
        );

        return response()->json(['status' => 'accepted']);
    }

    public function startEmailVerification(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return $this->errorResponse('AUTH_UNAUTHORIZED', 'Unauthorized', 401);
        }
        if ($user->blocksAuthenticationForMigration()) {
            return $this->migrationHoldResponse();
        }

        if ($user->email_verified_at) {
            return $this->errorResponse('VERIFY_ALREADY_CONFIRMED', 'Email уже подтвержден.', 409);
        }

        $email = mb_strtolower((string) $user->email);
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->errorResponse('AUTH_EMAIL_INVALID', 'Email не задан или невалиден.', 422);
        }

        $cooldown = $this->secondsUntilNextLoginCode($user->id, 'email', $email);
        if ($cooldown > 0) {
            return $this->errorResponse('VERIFY_RATE_LIMIT', "Повторный запрос через {$cooldown} сек.", 429, [
                'retry_after' => $cooldown,
            ]);
        }

        $code = $this->issueLoginCode($user->id, 'email', $email);
        $this->sendEmailLoginCode($email, $code, 'verify_email');

        return response()->json([
            'status' => 'pending',
            'debug_code' => $this->shouldExposeDebugCode() ? $code : null,
        ], 202);
    }

    public function verifyEmailVerificationCode(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return $this->errorResponse('AUTH_UNAUTHORIZED', 'Unauthorized', 401);
        }
        if ($user->blocksAuthenticationForMigration()) {
            return $this->migrationHoldResponse();
        }

        $data = $request->validate([
            'code' => ['required', 'string', 'size:6'],
        ]);

        $email = mb_strtolower((string) $user->email);
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->errorResponse('AUTH_EMAIL_INVALID', 'Email не задан или невалиден.', 422);
        }

        if (! $this->consumeLoginCode($user->id, 'email', $email, $data['code'])) {
            return $this->errorResponse('VERIFY_CODE_INVALID', 'Неверный код или код истек. Запросите новый.', 422);
        }

        $user->forceFill(['email_verified_at' => now()])->save();

        return response()->json(['status' => 'confirmed']);
    }

    public function me(Request $request): JsonResponse
    {
        $user = ImpersonationContext::effectiveUser($request);
        if (! ImpersonationContext::isActive($request) && $user->blocksAuthenticationForMigration()) {
            return $this->migrationHoldResponse();
        }

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'phone_verified_at' => $user->phone_verified_at,
            'email_verified_at' => $user->email_verified_at,
            'email_verified' => (bool) $user->email_verified_at,
            'phone_verified' => (bool) $user->phone_verified_at,
            'must_verify_phone' => $user->requiresPhoneVerification(),
            'must_verify_email' => ! (bool) $user->email_verified_at && ! ($user->usesAdminWorkspace() && ! $user->email),
            'identity_status' => $user->identityStatus(),
            'role' => $user->role,
            'manager_id' => $user->manager_id,
            'impersonation' => $this->impersonation->current($request),
        ]);
    }

    public function startPhoneVerification(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user instanceof User && $user->blocksAuthenticationForMigration()) {
            return $this->migrationHoldResponse();
        }

        $data = $request->validate([
            'phone' => ['nullable', 'string', 'max:30'],
        ]);

        $verificationId = (string) Str::uuid();
        $reservation = DB::transaction(function () use ($user, $data, $verificationId): array|JsonResponse {
            $user = User::query()->lockForUpdate()->findOrFail($user->id);
            if ($user->blocksAuthenticationForMigration()) {
                return $this->migrationHoldResponse();
            }

            $phone = User::normalizePhone((string) ($data['phone'] ?? $user->phone));
            if (! User::isValidE164Phone($phone)) {
                return $this->errorResponse('AUTH_PHONE_INVALID', 'Введите корректный номер телефона.', 422);
            }

            $belongsToOtherUser = User::query()
                ->where('phone', $phone)
                ->where('id', '!=', $user->id)
                ->exists();
            if ($belongsToOtherUser) {
                return $this->errorResponse('AUTH_PHONE_EXISTS', 'Телефон уже используется другим пользователем.', 409);
            }

            if ($user->phone_verified_at && $user->phone === $phone) {
                return $this->errorResponse('VERIFY_ALREADY_CONFIRMED', 'Телефон уже подтвержден.', 409);
            }

            $cooldown = $this->secondsUntilNextPhoneVerificationCode($user->id, $phone);
            if ($cooldown > 0) {
                return $this->errorResponse('VERIFY_RATE_LIMIT', "Повторный запрос через {$cooldown} сек.", 429, [
                    'retry_after' => $cooldown,
                ]);
            }

            $recentRequests = DB::table('phone_verification_codes')
                ->where('user_id', $user->id)
                ->where('created_at', '>=', now()->subMinutes(10))
                ->count();
            if ($recentRequests >= 3) {
                return $this->errorResponse('VERIFY_RATE_LIMIT', 'Достигнут лимит попыток. Попробуйте позже.', 429);
            }

            $expiresAt = now()->addMinutes(self::PHONE_VERIFY_CODE_TTL_MINUTES);
            DB::table('phone_verification_codes')->insert([
                'verification_id' => $verificationId,
                'user_id' => $user->id,
                'phone' => $phone,
                'user_phone_at_start' => (string) $user->phone,
                'code_hash' => Hash::make(Str::random(40)),
                'attempts_left' => 3,
                'status' => 'pending',
                'expires_at' => $expiresAt,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return ['phone' => $phone, 'expires_at' => $expiresAt];
        });
        if ($reservation instanceof JsonResponse) {
            return $reservation;
        }
        $phone = $reservation['phone'];

        if ($this->isRedsmsMockMode()) {
            $this->applyPhoneVerificationResult($verificationId, 'mock_'.Str::uuid(), 'confirmed', [
                'source' => 'mock',
            ]);
        } else {
            try {
                $providerResult = $this->redsmsApiClient->startWaitCall($phone, $verificationId);
            } catch (Throwable $e) {
                Log::warning('redsms.phone_verify.start_failed', [
                    'verification_id' => $verificationId,
                    'user_id' => $user->id,
                    'exception' => $e::class,
                ]);

                $this->finishPhoneVerificationStart($verificationId, [
                    'status' => 'failed',
                    'updated_at' => now(),
                ]);

                return $this->errorResponse('VERIFY_PROVIDER_FAILED', 'Провайдер верификации временно недоступен.', 503);
            }

            $this->finishPhoneVerificationStart($verificationId, [
                'provider_uuid' => $providerResult['provider_uuid'],
                'call_number' => $providerResult['call_number'],
                'provider_payload' => json_encode($providerResult['raw'], JSON_UNESCAPED_UNICODE),
                'updated_at' => now(),
            ]);
        }

        return response()->json([
            'status' => 'pending',
            'verification_id' => $verificationId,
            'call_number' => DB::table('phone_verification_codes')
                ->where('verification_id', $verificationId)
                ->value('call_number'),
            'expires_at' => $reservation['expires_at']->toISOString(),
        ], 202);
    }

    public function phoneVerificationStatus(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user instanceof User && $user->blocksAuthenticationForMigration()) {
            return $this->migrationHoldResponse();
        }

        $data = $request->validate([
            'verification_id' => ['required', 'uuid'],
        ]);

        $row = DB::table('phone_verification_codes')
            ->where('user_id', $user->id)
            ->where('verification_id', $data['verification_id'])
            ->first();

        if (! $row) {
            return $this->errorResponse('VERIFY_NOT_FOUND', 'Верификация не найдена.', 404);
        }

        return response()->json([
            'status' => (string) $row->status,
            'call_number' => $row->call_number,
            'expires_at' => Carbon::parse((string) $row->expires_at)->toISOString(),
        ]);
    }

    public function phoneVerificationCallback(Request $request): JsonResponse
    {
        $sourceError = $this->validateRedsmsWebhookSource($request);
        if ($sourceError) {
            return $sourceError;
        }

        $data = $request->validate([
            'verification_id' => ['nullable', 'uuid'],
            'provider_event_id' => ['nullable', 'string', 'max:120'],
            'provider_uuid' => ['nullable', 'string', 'max:120'],
            'uuid' => ['nullable', 'string', 'max:120'],
            'status' => ['required', 'string', 'max:64'],
        ]);

        $providerUuid = $this->resolveProviderUuidFromCallbackPayload($data);
        $row = $this->findPhoneVerificationRow($data['verification_id'] ?? null, $providerUuid);
        if (! $row) {
            return $this->errorResponse('VERIFY_NOT_FOUND', 'Верификация не найдена.', 404);
        }
        if ($this->phoneVerificationRowBelongsToHeldUser($row)) {
            return response()->json(['status' => 'accepted']);
        }

        $providerEventId = (string) ($data['provider_event_id'] ?? ($providerUuid !== '' ? $providerUuid : 'evt_'.Str::uuid()));
        if (is_string($row->provider_event_id) && $row->provider_event_id !== '' && $row->provider_event_id === $providerEventId) {
            return response()->json(['status' => 'accepted']);
        }

        $this->applyPhoneVerificationResult(
            (string) $row->verification_id,
            $providerEventId,
            $this->mapRedsmsStatusToVerificationStatus((string) $data['status']),
            $request->all()
        );

        return response()->json(['status' => 'accepted']);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);
        $email = mb_strtolower((string) $data['email']);
        $user = User::query()->where('email', $email)->first();

        if ($user) {
            if ($user->blocksAuthenticationForMigration()) {
                return response()->json(['status' => 'accepted'], 202);
            }

            $token = Password::broker()->createToken($user);
            $frontendUrl = rtrim((string) config('app.frontend_url', config('app.url')), '/');
            $resetUrl = $frontendUrl.'/auth?mode=reset&token='.urlencode($token).'&email='.urlencode($email);
            $subject = 'Сброс пароля';
            $html = '<p>Для сброса пароля перейдите по ссылке:</p><p><a href="'.$resetUrl.'">'.$resetUrl.'</a></p>';
            $sentViaSmtpBz = $this->smtpBzApiClient->sendHtmlMessage($email, $subject, $html);

            if (! $sentViaSmtpBz) {
                try {
                    Mail::raw(
                        "Для сброса пароля перейдите по ссылке:\n".$resetUrl,
                        static function ($message) use ($email, $subject): void {
                            $message->to($email)->subject($subject);
                        }
                    );
                } catch (Throwable) {
                    logger()->warning('mail.reset.dispatch_failed');
                }
            }

            if ($this->shouldExposeDebugCode()) {
                return response()->json([
                    'status' => 'accepted',
                    'debug_reset_token' => $token,
                ], 202);
            }
        }

        return response()->json(['status' => 'accepted'], 202);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'token' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'max:255'],
        ]);

        $email = mb_strtolower((string) $data['email']);
        $matched = DB::table('password_reset_tokens')
            ->where('email', $email)
            ->first(['email', 'token', 'created_at']);

        if (! $matched || ! Hash::check($data['token'], (string) $matched->token)) {
            return $this->errorResponse('RESET_TOKEN_INVALID', 'Токен сброса пароля невалиден.', 400);
        }

        $createdAt = Carbon::parse((string) $matched->created_at);
        $ttlMinutes = (int) config('auth.passwords.users.expire', 60);
        if ($createdAt->lt(now()->subMinutes($ttlMinutes))) {
            return $this->errorResponse('RESET_TOKEN_EXPIRED', 'Токен сброса пароля истек.', 410);
        }

        $user = User::query()->where('email', $matched->email)->first();
        if (! $user) {
            return $this->errorResponse('RESET_TOKEN_INVALID', 'Токен сброса пароля невалиден.', 400);
        }
        if ($user->blocksAuthenticationForMigration()) {
            return $this->migrationHoldResponse();
        }

        $user->forceFill([
            'password' => $data['password'],
            'password_state' => 'set',
        ])->save();
        DB::table('password_reset_tokens')->where('email', $matched->email)->delete();

        return response()->json(['status' => 'password_updated']);
    }

    public function onboardingStatus(Request $request): JsonResponse
    {
        $user = ImpersonationContext::effectiveUser($request);
        if (! ImpersonationContext::isActive($request) && $user->blocksAuthenticationForMigration()) {
            return $this->migrationHoldResponse();
        }

        $identityStatus = $user->identityStatus();
        $staffRoles = User::STAFF_ROLES;

        if (in_array($user->role, $staffRoles, true)) {
            $nextStep = 'dashboard';
        } else {
            $nextStep = match ($identityStatus) {
                'phone_missing', 'phone_invalid' => 'repair_phone',
                'phone_unverified' => 'verify_phone',
                default => 'dashboard',
            };
        }

        return response()->json([
            'identity_status' => $identityStatus,
            'next_step' => $nextStep,
        ]);
    }

    private function countRecentPreVerificationStarts(string $channel, string $target): int
    {
        return DB::table('pre_registration_verifications')
            ->where('channel', $channel)
            ->where('target', $target)
            ->where('created_at', '>=', now()->subMinutes(self::PREVERIFY_START_LIMIT_WINDOW_MINUTES))
            ->count();
    }

    private function secondsUntilNextPreVerificationCode(string $channel, string $target): int
    {
        $lastIssuedAt = DB::table('pre_registration_verifications')
            ->where('channel', $channel)
            ->where('target', $target)
            ->max('created_at');
        if (! $lastIssuedAt) {
            return 0;
        }

        $seconds = now()->diffInSeconds(Carbon::parse($lastIssuedAt), false);
        $elapsed = abs(min(0, $seconds));
        $left = self::PREVERIFY_RESEND_COOLDOWN_SECONDS - $elapsed;

        return max(0, $left);
    }

    private function consumePreRegistrationVerificationToken(string $channel, string $target, string $token): bool
    {
        if (($channel === 'email' && $this->migrationHeldUserByEmail($target))
            || ($channel === 'phone' && $this->migrationHeldUserByPhone($target))) {
            return false;
        }

        $row = DB::table('pre_registration_verifications')
            ->where('channel', $channel)
            ->where('target', $target)
            ->where('status', 'confirmed')
            ->whereNull('consumed_at')
            ->where('verification_token_expires_at', '>', now())
            ->latest('id')
            ->first();
        if (! $row) {
            return false;
        }

        $isValid = false;
        if ($channel === 'email' && is_string($row->verification_token_hash)) {
            $isValid = Hash::check($token, $row->verification_token_hash);
        }
        if ($channel === 'phone') {
            $isValid = hash_equals((string) $row->verification_id, $token);
        }
        if (! $isValid) {
            return false;
        }

        DB::table('pre_registration_verifications')->where('id', $row->id)->update([
            'consumed_at' => now(),
            'updated_at' => now(),
        ]);

        return true;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function applyPreRegistrationPhoneVerificationResult(
        string $verificationId,
        string $providerEventId,
        string $status,
        array $payload
    ): void {
        $row = DB::table('pre_registration_verifications')
            ->where('channel', 'phone')
            ->where('verification_id', $verificationId)
            ->first();
        if (! $row) {
            return;
        }
        if ($this->migrationHeldUserByPhone((string) $row->target)) {
            return;
        }

        DB::table('pre_registration_verifications')
            ->where('id', $row->id)
            ->update([
                'status' => $status,
                'provider_event_id' => $providerEventId,
                'provider_uuid' => $this->resolveProviderUuidFromCallbackPayload($payload) ?: $row->provider_uuid,
                'call_number' => isset($payload['replacedFrom']) && is_string($payload['replacedFrom']) ? $payload['replacedFrom'] : $row->call_number,
                'provider_payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                'confirmed_at' => $status === 'confirmed' ? now() : null,
                'verification_token_expires_at' => $status === 'confirmed' ? now()->addMinutes(self::PREVERIFY_TTL_MINUTES) : null,
                'updated_at' => now(),
            ]);
    }

    private function issueLoginCode(int $userId, string $channel, string $target): string
    {
        $code = (string) random_int(100000, 999999);

        DB::table('auth_login_codes')->insert([
            'user_id' => $userId,
            'channel' => $channel,
            'target' => $target,
            'code_hash' => Hash::make($code),
            'attempts_left' => 5,
            'expires_at' => now()->addMinutes(self::LOGIN_CODE_TTL_MINUTES),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $code;
    }

    private function consumeLoginCode(int $userId, string $channel, string $target, string $code): bool
    {
        $row = DB::table('auth_login_codes')
            ->where('user_id', $userId)
            ->where('channel', $channel)
            ->where('target', $target)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->latest('id')
            ->first();

        if (! $row) {
            return false;
        }

        if (! Hash::check($code, $row->code_hash)) {
            $attemptsLeft = max(0, ((int) $row->attempts_left) - 1);
            DB::table('auth_login_codes')->where('id', $row->id)->update([
                'attempts_left' => $attemptsLeft,
                'consumed_at' => $attemptsLeft === 0 ? now() : null,
                'updated_at' => now(),
            ]);

            return false;
        }

        DB::table('auth_login_codes')->where('id', $row->id)->update([
            'consumed_at' => now(),
            'updated_at' => now(),
        ]);

        return true;
    }

    /**
     * @return array{0: 'email'|'phone'|'username'|null, 1: string|null}
     */
    private function resolveLoginChannel(string $login): array
    {
        $value = trim($login);
        if ($value === '') {
            return [null, null];
        }

        if (filter_var($value, FILTER_VALIDATE_EMAIL)) {
            return ['email', mb_strtolower($value)];
        }

        if ($username = User::normalizeUsername($value)) {
            return ['username', $username];
        }

        $phone = User::normalizePhone($value);
        if (User::isValidE164Phone($phone)) {
            return ['phone', $phone];
        }

        return [null, null];
    }

    private function secondsUntilNextLoginCode(int $userId, string $channel, string $target): int
    {
        $lastIssuedAt = DB::table('auth_login_codes')
            ->where('user_id', $userId)
            ->where('channel', $channel)
            ->where('target', $target)
            ->max('created_at');

        if (! $lastIssuedAt) {
            return 0;
        }

        $seconds = now()->diffInSeconds(Carbon::parse($lastIssuedAt), false);
        $elapsed = abs(min(0, $seconds));
        $left = self::RESEND_COOLDOWN_SECONDS - $elapsed;

        return max(0, $left);
    }

    private function secondsUntilNextPhoneVerificationCode(int $userId, string $phone): int
    {
        $lastIssuedAt = DB::table('phone_verification_codes')
            ->where('user_id', $userId)
            ->where('phone', $phone)
            ->max('created_at');

        if (! $lastIssuedAt) {
            return 0;
        }

        $seconds = now()->diffInSeconds(Carbon::parse($lastIssuedAt), false);
        $elapsed = abs(min(0, $seconds));
        $left = self::RESEND_COOLDOWN_SECONDS - $elapsed;

        return max(0, $left);
    }

    private function shouldExposeDebugCode(): bool
    {
        return in_array((string) config('app.env'), ['local', 'testing'], true);
    }

    private function isRedsmsMockMode(): bool
    {
        return (string) config('services.redsms.mode', 'mock') === 'mock';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveProviderUuidFromCallbackPayload(array $payload): string
    {
        foreach (['provider_uuid', 'uuid'] as $key) {
            if (isset($payload[$key]) && is_string($payload[$key]) && $payload[$key] !== '') {
                return $payload[$key];
            }
        }

        return '';
    }

    private function mapRedsmsStatusToVerificationStatus(string $providerStatus): string
    {
        $status = mb_strtolower(trim($providerStatus));

        return match (true) {
            in_array($status, ['confirmed', 'wcall_delivered', 'delivered', 'success', 'ok'], true) => 'confirmed',
            in_array($status, ['failed', 'timeout', 'wcall_timeout', 'error', 'rejected'], true) => 'failed',
            default => 'pending',
        };
    }

    private function findPhoneVerificationRow(?string $verificationId, string $providerUuid): ?object
    {
        if (is_string($verificationId) && $verificationId !== '') {
            $row = DB::table('phone_verification_codes')->where('verification_id', $verificationId)->first();
            if ($row) {
                return $row;
            }
        }

        if ($providerUuid !== '') {
            return DB::table('phone_verification_codes')->where('provider_uuid', $providerUuid)->first();
        }

        return null;
    }

    private function findPreRegistrationPhoneVerificationRow(?string $verificationId, string $providerUuid): ?object
    {
        $query = DB::table('pre_registration_verifications')->where('channel', 'phone');
        if (is_string($verificationId) && $verificationId !== '') {
            $row = (clone $query)->where('verification_id', $verificationId)->first();
            if ($row) {
                return $row;
            }
        }

        if ($providerUuid !== '') {
            return (clone $query)->where('provider_uuid', $providerUuid)->first();
        }

        return null;
    }

    private function regenerateSessionIfAvailable(Request $request): void
    {
        if ($request->hasSession()) {
            $request->session()->regenerate();
        }
    }

    private function validateRedsmsWebhookSource(Request $request): ?JsonResponse
    {
        if (in_array((string) config('app.env'), ['local', 'testing'], true)) {
            return null;
        }

        $clientIp = (string) $request->ip();
        $allowedIps = config('services.redsms.webhook_allowed_ips', []);
        if (! is_array($allowedIps)) {
            $allowedIps = [];
        }

        if ($clientIp === '' || $allowedIps === [] || ! IpUtils::checkIp($clientIp, $allowedIps)) {
            Log::warning('redsms.webhook_source_forbidden', [
                'client_ip' => $clientIp,
                'path' => $request->path(),
            ]);

            return $this->errorResponse('AUTH_FORBIDDEN', 'Forbidden', 403);
        }

        return null;
    }

    private function isUniqueConstraintViolation(QueryException $e): bool
    {
        $sqlState = (string) ($e->errorInfo[0] ?? '');
        $driverCode = (string) ($e->errorInfo[1] ?? '');

        return $sqlState === '23505' || $driverCode === '1062' || str_contains($e->getMessage(), 'UNIQUE constraint failed');
    }

    private function sendEmailLoginCode(string $email, string $code, string $purpose = 'login'): void
    {
        $subject = $purpose === 'verify_email' ? 'Подтверждение email' : 'Код входа';
        $title = $purpose === 'verify_email' ? 'Код для подтверждения email' : 'Ваш код входа';
        $html = '<p>'.$title.': <b>'.$code.'</b></p><p>Код действует '.self::LOGIN_CODE_TTL_MINUTES.' минут.</p>';

        $sentViaSmtpBz = $this->smtpBzApiClient->sendHtmlMessage($email, $subject, $html);
        if (! $sentViaSmtpBz) {
            Mail::raw(
                "{$title}: {$code}\nКод действует ".self::LOGIN_CODE_TTL_MINUTES.' минут.',
                static function ($message) use ($email, $subject): void {
                    $message->to($email)->subject($subject);
                }
            );
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function applyPhoneVerificationResult(
        string $verificationId,
        string $providerEventId,
        string $status,
        array $payload
    ): void {
        $row = DB::table('phone_verification_codes')->where('verification_id', $verificationId)->first();
        if (! $row) {
            return;
        }
        try {
            DB::transaction(function () use ($row, $verificationId, $providerEventId, $status, $payload): void {
                $user = User::query()->lockForUpdate()->find($row->user_id);
                $row = DB::table('phone_verification_codes')->where('id', $row->id)->lockForUpdate()->first();
                if (! $user || ! $row || $row->verification_id !== $verificationId
                    || ! $this->isCurrentPhoneVerification($row, $user)) {
                    return;
                }
                if ($row->provider_event_id === $providerEventId) {
                    return;
                }
                if (isset($payload['verification_id']) && $payload['verification_id'] !== $row->verification_id) {
                    return;
                }
                foreach (['provider_uuid', 'uuid'] as $key) {
                    if (isset($payload[$key]) && $payload[$key] !== ''
                        && is_string($row->provider_uuid) && $row->provider_uuid !== ''
                        && $payload[$key] !== $row->provider_uuid) {
                        return;
                    }
                }

                // REDSMS can use the same UUID for pending and terminal callbacks.
                if ($status === 'pending') {
                    return;
                }

                DB::table('phone_verification_codes')
                    ->where('id', $row->id)
                    ->update([
                        'status' => $status,
                        'provider_event_id' => $providerEventId,
                        'provider_uuid' => $this->resolveProviderUuidFromCallbackPayload($payload) ?: $row->provider_uuid,
                        'call_number' => isset($payload['replacedFrom']) && is_string($payload['replacedFrom']) ? $payload['replacedFrom'] : $row->call_number,
                        'provider_payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                        'confirmed_at' => $status === 'confirmed' ? now() : null,
                        'consumed_at' => now(),
                        'updated_at' => now(),
                    ]);

                if ($status === 'confirmed') {
                    $user->forceFill([
                        'phone' => User::normalizePhone((string) $row->phone),
                        'phone_verified_at' => now(),
                    ])->save();
                }
            });
        } catch (QueryException $e) {
            if (! $this->isUniqueConstraintViolation($e)) {
                throw $e;
            }

            // Another account won the unique phone race. Keep the callback
            // idempotent and expose a retryable failed verification state.
            DB::table('phone_verification_codes')
                ->where('verification_id', $verificationId)
                ->update([
                    'status' => 'failed',
                    'confirmed_at' => null,
                    'consumed_at' => now(),
                    'provider_event_id' => $providerEventId,
                    'provider_payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                    'updated_at' => now(),
                ]);
        }
    }

    private function isCurrentPhoneVerification(object $row, User $user): bool
    {
        return ! $user->blocksAuthenticationForMigration()
            && (int) $row->user_id === $user->id
            && $row->user_phone_at_start !== null
            && $row->user_phone_at_start === (string) $user->phone
            && $row->status === 'pending'
            && $row->consumed_at === null
            && Carbon::parse((string) $row->expires_at)->isFuture()
            && ! DB::table('phone_verification_codes')
                ->where('user_id', $user->id)
                ->where('id', '>', $row->id)
                ->exists();
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function finishPhoneVerificationStart(string $verificationId, array $values): void
    {
        $row = DB::table('phone_verification_codes')->where('verification_id', $verificationId)->first();
        if (! $row) {
            return;
        }

        DB::transaction(function () use ($row, $values): void {
            $user = User::query()->lockForUpdate()->find($row->user_id);
            $row = DB::table('phone_verification_codes')->where('id', $row->id)->lockForUpdate()->first();
            if ($user && $row && $this->isCurrentPhoneVerification($row, $user)) {
                DB::table('phone_verification_codes')->where('id', $row->id)->update($values);
            }
        });
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function errorResponse(string $code, string $message, int $status, array $meta = []): JsonResponse
    {
        return response()->json([
            'error' => array_merge([
                'code' => $code,
                'message' => $message,
                'request_id' => (string) Str::uuid(),
            ], $meta),
        ], $status);
    }
}
