<?php

namespace App\Services\LegacyUsers;

use App\Jobs\SendPreRegistrationEmailCodeJob;
use App\Models\User;
use App\Services\RedsmsApiClient;
use Carbon\Carbon;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use JsonException;
use Throwable;

final class LegacyUserActivationService
{
    public function __construct(private readonly RedsmsApiClient $redsms) {}

    public function start(string $email): array
    {
        $email = mb_strtolower(trim($email));
        $capability = Str::random(64);
        $code = (string) random_int(100000, 999999);
        $result = DB::transaction(function () use ($email, $capability, $code): array {
            LegacyUserMigrationLock::shared();
            $profiles = DB::table('legacy_user_profiles')->whereRaw('LOWER(TRIM(source_email)) = ?', [$email])->lockForUpdate()->get();
            if ($profiles->count() !== 1) {
                $this->deny('LEGACY_ACTIVATION_INELIGIBLE');
            }
            $profile = $profiles->first();
            $user = User::query()->lockForUpdate()->find($profile->user_id);
            $this->assertEligible($profile, $user);
            if (DB::table('legacy_user_activations')->where('legacy_user_profile_id', $profile->id)->whereNotNull('completed_at')->exists()) {
                $this->deny('LEGACY_ACTIVATION_CONSUMED');
            }
            $recent = DB::table('legacy_user_activations')->where('legacy_user_profile_id', $profile->id)->where('created_at', '>=', now()->subMinutes(10));
            $last = (clone $recent)->latest('id')->first();
            if ($last && Carbon::parse($last->created_at)->addSeconds(60)->isFuture() || $recent->count() >= 3) {
                $this->deny('VERIFY_RATE_LIMIT', 429);
            }
            $id = DB::table('legacy_user_activations')->insertGetId([
                'activation_id' => (string) Str::uuid(), 'legacy_user_profile_id' => $profile->id, 'user_id' => $user->id,
                'source_email' => $email, 'source_fingerprint' => $profile->source_fingerprint,
                'capability_hash' => hash('sha256', $capability), 'expires_at' => now()->addMinutes(30),
                'email_code_hash' => Hash::make($code), 'attempts_left' => 5, 'email_code_expires_at' => now()->addMinutes(10),
                'created_at' => now(), 'updated_at' => now(),
            ]);

            return $this->view(DB::table('legacy_user_activations')->find($id)) + ['capability' => $capability];
        });

        // Delivery is outside both the row locks and the importer coordination lock.
        SendPreRegistrationEmailCodeJob::dispatch($email, $code, 10);
        $result['debug_code'] = in_array(config('app.env'), ['local', 'testing'], true) ? $code : null;

        return $result;
    }

    public function verifyEmail(string $id, string $capability, string $code): array
    {
        return $this->locked($id, $capability, function (object $row) use ($code): array {
            if ($row->email_confirmed_at !== null) {
                $this->deny('VERIFY_ALREADY_CONFIRMED', 409);
            }
            if ($row->attempts_left < 1 || ! Carbon::parse($row->email_code_expires_at)->isFuture()) {
                $this->deny('VERIFY_CODE_INVALID', 422);
            }
            if (! Hash::check($code, $row->email_code_hash)) {
                DB::table('legacy_user_activations')->where('id', $row->id)->update(['attempts_left' => $row->attempts_left - 1, 'updated_at' => now()]);

                // Return rather than throw: the failed-attempt decrement must commit.
                return ['error' => ['code' => 'VERIFY_CODE_INVALID', 'message' => 'Неверный код или код истёк.'], 'http_status' => 422];
            }
            DB::table('legacy_user_activations')->where('id', $row->id)->update(['email_confirmed_at' => now(), 'email_code_hash' => null, 'updated_at' => now()]);

            return $this->freshView($row);
        });
    }

    public function password(string $id, string $capability, string $password): array
    {
        $hash = Hash::make($password);

        return $this->locked($id, $capability, function (object $row) use ($hash): array {
            if ($this->view($row)['step'] !== 'password') {
                $this->deny('LEGACY_ACTIVATION_STEP_REQUIRED', 409);
            }
            DB::table('legacy_user_activations')->where('id', $row->id)->update(['password_hash' => $hash, 'updated_at' => now()]);

            return $this->freshView($row);
        });
    }

    public function status(string $id, string $capability): array
    {
        return $this->locked($id, $capability, fn (object $row): array => $this->view($row));
    }

    public function startPhone(string $id, string $capability, string $input): array
    {
        $phone = User::normalizePhone($input);
        $verificationId = (string) Str::uuid();
        $this->locked($id, $capability, function (object $row, User $user) use ($phone, $verificationId): void {
            if ($this->view($row)['step'] !== 'phone') {
                $this->deny('LEGACY_ACTIVATION_STEP_REQUIRED', 409);
            }
            if (! User::isValidE164Phone($phone)) {
                $this->deny('AUTH_PHONE_INVALID', 422);
            }
            if (User::query()->where('phone', $phone)->whereKeyNot($user->id)->exists()) {
                $this->deny('AUTH_PHONE_EXISTS', 409);
            }
            $recent = DB::table('legacy_activation_phone_attempts as p')
                ->join('legacy_user_activations as a', 'a.id', '=', 'p.activation_id')
                ->where('a.user_id', $user->id)->where('p.created_at', '>=', now()->subMinutes(10));
            $last = (clone $recent)->orderByDesc('p.id')->first(['p.created_at']);
            if ($last && Carbon::parse($last->created_at)->addSeconds(60)->isFuture() || $recent->count() >= 3) {
                $this->deny('VERIFY_RATE_LIMIT', 429);
            }
            if ($row->current_phone_attempt) {
                DB::table('legacy_activation_phone_attempts')->where('verification_id', $row->current_phone_attempt)->update(['consumed_at' => now(), 'updated_at' => now()]);
            }
            DB::table('legacy_activation_phone_attempts')->insert([
                'verification_id' => $verificationId, 'activation_id' => $row->id, 'phone' => $phone,
                'user_phone_at_start' => (string) $user->phone, 'status' => 'pending', 'expires_at' => now()->addMinutes(10),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('legacy_user_activations')->where('id', $row->id)->update(['current_phone_attempt' => $verificationId, 'updated_at' => now()]);
        });

        $mock = config('services.redsms.mode', 'mock') === 'mock';
        try {
            if ($mock && ! in_array(config('app.env'), ['local', 'testing'], true)) {
                throw new \RuntimeException('Mock verification is restricted to isolated development.');
            }
            $provider = $mock
                ? ['provider_uuid' => 'mock_'.Str::uuid(), 'call_number' => null]
                : $this->redsms->startWaitCall($phone, $verificationId);
            if (! is_string($provider['provider_uuid'] ?? null) || $provider['provider_uuid'] === '' || strlen($provider['provider_uuid']) > 120
                || (($provider['call_number'] ?? null) !== null && (! is_string($provider['call_number']) || ! preg_match('/^\+?[\d ()-]{5,30}$/D', $provider['call_number'])))) {
                throw new \RuntimeException('Invalid phone verification provider binding.');
            }
        } catch (Throwable) {
            $this->locked($id, $capability, function (object $row) use ($verificationId): void {
                if ($row->current_phone_attempt === $verificationId) {
                    DB::table('legacy_activation_phone_attempts')->where('verification_id', $verificationId)->update(['status' => 'failed', 'consumed_at' => now(), 'updated_at' => now()]);
                }
            });
            $this->deny('VERIFY_PROVIDER_FAILED', 503);
        }

        return $this->locked($id, $capability, function (object $row, User $user) use ($verificationId, $provider, $mock): array {
            $attempt = DB::table('legacy_activation_phone_attempts')->where('verification_id', $verificationId)->lockForUpdate()->first();
            if ($row->current_phone_attempt === $verificationId && $this->currentAttempt($row, $user, $attempt)) {
                DB::table('legacy_activation_phone_attempts')->where('id', $attempt->id)->update([
                    'provider_uuid' => $provider['provider_uuid'], 'call_number' => $provider['call_number'],
                    'status' => $mock ? 'confirmed' : 'pending', 'confirmed_at' => $mock ? now() : null,
                    'consumed_at' => $mock ? now() : null, 'updated_at' => now(),
                ]);
            }

            return $this->freshView($row);
        });
    }

    public function callback(array $payload): void
    {
        $providerUuid = $payload['provider_uuid'] ?? $payload['uuid'] ?? '';
        if (! is_string($providerUuid) || $providerUuid === '') {
            return;
        }
        $query = DB::table('legacy_activation_phone_attempts');
        if (! empty($payload['verification_id'])) {
            $query->where('verification_id', $payload['verification_id']);
        } else {
            $query->where('provider_uuid', $providerUuid);
        }
        $attempts = $query->get();
        if ($attempts->count() !== 1) {
            return;
        }
        $attempt = $attempts->first();
        $route = DB::table('legacy_user_activations')->find($attempt->activation_id);
        if (! $route) {
            return;
        }
        try {
            $this->transaction($route, function (object $row, User $user) use ($attempt, $payload, $providerUuid): void {
                $attempt = DB::table('legacy_activation_phone_attempts')->where('id', $attempt->id)->lockForUpdate()->first();
                if (! $this->currentAttempt($row, $user, $attempt) || ! $attempt->provider_uuid || $attempt->provider_uuid !== $providerUuid) {
                    return;
                }
                foreach (['provider_uuid', 'uuid'] as $key) {
                    if (! empty($payload[$key]) && $payload[$key] !== $attempt->provider_uuid) {
                        return;
                    }
                }
                foreach (['verification_id', 'ext_id'] as $key) {
                    if (isset($payload[$key]) && $payload[$key] !== $attempt->verification_id) {
                        return;
                    }
                }
                if (isset($payload['phone']) && User::normalizePhone($payload['phone']) !== $attempt->phone) {
                    return;
                }
                $callNumber = $payload['replacedFrom'] ?? $attempt->call_number;
                if ($callNumber !== null && (! is_string($callNumber) || ! preg_match('/^\+?[\d ()-]{5,30}$/D', $callNumber))) {
                    return;
                }
                $status = match (mb_strtolower(trim($payload['status'] ?? ''))) {
                    'confirmed', 'wcall_delivered', 'delivered', 'success', 'ok' => 'confirmed',
                    'failed', 'timeout', 'wcall_timeout', 'error', 'rejected' => 'failed',
                    default => 'pending',
                };
                if ($status === 'pending') {
                    DB::table('legacy_activation_phone_attempts')->where('id', $attempt->id)->update(['call_number' => $callNumber, 'updated_at' => now()]);

                    return;
                }
                DB::table('legacy_activation_phone_attempts')->where('id', $attempt->id)->update([
                    'status' => $status, 'call_number' => $callNumber, 'provider_event_id' => $payload['provider_event_id'] ?? $providerUuid,
                    'confirmed_at' => $status === 'confirmed' ? now() : null, 'consumed_at' => now(), 'updated_at' => now(),
                ]);
            });
        } catch (HttpResponseException) {
            // A stale or ineligible callback is acknowledged without confirming anything.
        }
    }

    public function complete(string $id, string $capability): array
    {
        try {
            return $this->locked($id, $capability, function (object $row, User $user): array {
                $attempt = DB::table('legacy_activation_phone_attempts')->where('verification_id', $row->current_phone_attempt)->lockForUpdate()->first();
                if ($this->view($row)['step'] !== 'complete' || ! $attempt || $attempt->user_phone_at_start !== (string) $user->phone) {
                    $this->deny('LEGACY_ACTIVATION_STEP_REQUIRED', 409);
                }
                if (User::query()->where('phone', $attempt->phone)->whereKeyNot($user->id)->exists()) {
                    $this->failPhone($attempt);

                    return ['error' => ['code' => 'AUTH_PHONE_EXISTS', 'message' => 'Телефон уже используется. Подтвердите другой номер.'], 'http_status' => 409];
                }
                $user->forceFill([
                    'password' => $row->password_hash, 'password_state' => 'set', 'migration_hold' => false,
                    'email_verified_at' => $row->email_confirmed_at, 'phone' => $attempt->phone,
                    'phone_verified_at' => $attempt->confirmed_at, 'legacy_auto_verified' => false,
                    'remember_token' => Str::random(60), 'last_auth_method' => 'email',
                ])->save();
                $user->tokens()->delete();
                DB::table('legacy_user_activations')->where('id', $row->id)->update(['completed_at' => now(), 'password_hash' => null, 'email_code_hash' => null, 'updated_at' => now()]);

                return ['status' => 'completed', 'step' => 'done', 'user_id' => $user->id];
            });
        } catch (QueryException $exception) {
            if (($exception->errorInfo[0] ?? '') !== '23505'
                && ! (($exception->errorInfo[0] ?? '') === '23000' && str_contains($exception->getMessage(), 'UNIQUE constraint failed: users.phone'))) {
                throw $exception;
            }
            $this->locked($id, $capability, function (object $row): void {
                $attempt = DB::table('legacy_activation_phone_attempts')->where('verification_id', $row->current_phone_attempt)->lockForUpdate()->first();
                if ($attempt) {
                    $this->failPhone($attempt);
                }
            });
            $this->deny('AUTH_PHONE_EXISTS', 409);
        }
    }

    private function locked(string $id, string $capability, Closure $operation): mixed
    {
        $route = DB::table('legacy_user_activations')->where('activation_id', $id)->first();
        if (! $route || ! hash_equals($route->capability_hash, hash('sha256', $capability))) {
            $this->deny('LEGACY_ACTIVATION_INVALID', 401);
        }

        return $this->transaction($route, $operation);
    }

    private function transaction(object $route, Closure $operation): mixed
    {
        return DB::transaction(function () use ($route, $operation): mixed {
            LegacyUserMigrationLock::shared();
            $profile = DB::table('legacy_user_profiles')->where('id', $route->legacy_user_profile_id)->lockForUpdate()->first();
            $user = User::query()->lockForUpdate()->find($route->user_id);
            $row = DB::table('legacy_user_activations')->where('id', $route->id)->lockForUpdate()->first();
            if (! $row || $row->completed_at || ! hash_equals($route->capability_hash, $row->capability_hash)) {
                $this->deny('LEGACY_ACTIVATION_CONSUMED');
            }
            $this->assertEligible($profile, $user);
            if (! Carbon::parse($row->expires_at)->isFuture()) {
                $this->deny('LEGACY_ACTIVATION_EXPIRED', 401);
            }
            if ($row->source_fingerprint !== $profile->source_fingerprint || $row->source_email !== mb_strtolower(trim($profile->source_email))
                || DB::table('legacy_user_activations')->where('legacy_user_profile_id', $profile->id)->where('id', '>', $row->id)->exists()) {
                $this->deny('LEGACY_ACTIVATION_INELIGIBLE');
            }

            return $operation($row, $user, $profile);
        });
    }

    private function assertEligible(?object $profile, ?User $user): void
    {
        try {
            $payload = json_decode($profile?->payload ?? '{}', true, 64, JSON_THROW_ON_ERROR);
            $issues = json_decode($profile?->issues ?? '[]', true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->deny('LEGACY_ACTIVATION_INELIGIBLE');
        }
        $email = mb_strtolower(trim($profile?->source_email ?? ''));
        if (! $profile || ! $user || $profile->mapping_status !== 'linked' || (int) $profile->user_id !== $user->id
            || ! $user->blocksAuthenticationForMigration() || ! in_array($user->role, ['user_no_purchase', 'user_with_purchase'], true)
            || ! is_array($payload) || ! is_array($issues) || ! is_string($payload['email'] ?? null)
            || ($payload['id'] ?? null) !== (int) $profile->source_member_id
            || ! in_array($payload['isBanned'] ?? null, [0, '0', false], true)
            || ! filter_var($email, FILTER_VALIDATE_EMAIL) || $email !== mb_strtolower(trim($user->email ?? ''))
            || $email !== mb_strtolower(trim($payload['email'] ?? ''))
            || ! preg_match('/^[a-f0-9]{64}$/D', $profile->source_fingerprint)
            || DB::table('legacy_user_profiles')->whereRaw('LOWER(TRIM(source_email)) = ?', [$email])->count() !== 1
            || User::query()->whereRaw('LOWER(TRIM(email)) = ?', [$email])->count() !== 1) {
            $this->deny('LEGACY_ACTIVATION_INELIGIBLE');
        }
        foreach ($issues as $issue) {
            if (! is_array($issue)) {
                $this->deny('LEGACY_ACTIVATION_INELIGIBLE');
            }
            if (($issue['reason'] ?? '') === 'target_contact_conflict'
                || ($issue['field'] ?? '') === 'email' && in_array($issue['reason'] ?? '', ['invalid_or_nonunique_email', 'contact_change_requires_review', 'source_value_quarantined'], true)) {
                $this->deny('LEGACY_ACTIVATION_INELIGIBLE');
            }
        }
    }

    private function currentAttempt(object $row, User $user, ?object $attempt): bool
    {
        return $attempt && $row->current_phone_attempt === $attempt->verification_id && (int) $attempt->activation_id === (int) $row->id
            && $attempt->user_phone_at_start === (string) $user->phone && $attempt->status === 'pending'
            && $attempt->consumed_at === null && Carbon::parse($attempt->expires_at)->isFuture();
    }

    private function failPhone(object $attempt): void
    {
        DB::table('legacy_activation_phone_attempts')->where('id', $attempt->id)->update(['status' => 'failed', 'consumed_at' => now(), 'updated_at' => now()]);
    }

    private function freshView(object $row): array
    {
        return $this->view(DB::table('legacy_user_activations')->find($row->id));
    }

    private function view(object $row): array
    {
        $attempt = $row->current_phone_attempt ? DB::table('legacy_activation_phone_attempts')->where('verification_id', $row->current_phone_attempt)->first() : null;
        $validPhone = $attempt && $attempt->status === 'confirmed' && Carbon::parse($attempt->expires_at)->isFuture();
        $step = ! $row->email_confirmed_at ? 'email' : (! $row->password_hash ? 'password' : ($validPhone ? 'complete' : 'phone'));

        return ['activation_id' => $row->activation_id, 'step' => $step, 'status' => 'pending',
            'expires_at' => Carbon::parse($row->expires_at)->toISOString(), 'verification_id' => $attempt?->verification_id,
            'phone_status' => $attempt && ! Carbon::parse($attempt->expires_at)->isFuture() ? 'expired' : $attempt?->status,
            'call_number' => $attempt?->call_number, 'phone' => $attempt?->phone];
    }

    private function deny(string $code, int $status = 403): never
    {
        throw new HttpResponseException(response()->json(['error' => ['code' => $code,
            'message' => match ($code) {
                'LEGACY_ACTIVATION_STEP_REQUIRED' => 'Сначала завершите предыдущий шаг.',
                'AUTH_PHONE_EXISTS' => 'Телефон уже используется другим профилем.',
                'VERIFY_RATE_LIMIT' => 'Повторите запрос позже.',
                'VERIFY_PROVIDER_FAILED' => 'Провайдер подтверждения временно недоступен.',
                'VERIFY_CODE_INVALID' => 'Неверный код или код истёк.',
                'VERIFY_ALREADY_CONFIRMED' => 'Email уже подтверждён.',
                default => 'Подтверждение недоступно или устарело. Начните заново.',
            }]], $status)->header('Cache-Control', 'private, no-store'));
    }
}
