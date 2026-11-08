<?php

namespace App\Services;

use App\Models\AdminOperationEvent;
use App\Models\User;
use App\Support\Rbac;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class AdminTestUserDeletion
{
    /**
     * These records represent an account that has moved beyond a disposable registration.
     * They are deliberately blocked instead of being purged by an admin convenience action.
     */
    private const PROTECTED_REFERENCES = [
        'orders' => ['user_id' => 'заказы'],
        'user_entitlement_grants' => ['user_id' => 'выданный доступ'],
        'protected_file_deliveries' => ['user_id' => 'выданные файлы'],
        'video_watermark_sessions' => ['user_id' => 'видеосессии'],
        'lesson_video_playback_positions' => ['user_id' => 'история просмотра'],
        'lesson_assessment_attempts' => ['user_id' => 'попытки тестов'],
        'lesson_assessment_completions' => ['user_id' => 'завершённые тесты'],
        'lesson_assessment_manual_reviews' => ['reviewer_id' => 'ручные проверки'],
        'lesson_assessment_review_events' => ['actor_id' => 'события проверки'],
        'coin_reservations' => ['user_id' => 'резервы монет'],
        'coin_ledger_entries' => ['user_id' => 'операции монет'],
        'promo_reservations' => ['user_id' => 'резервы промокодов'],
        'reviews' => ['user_id' => 'отзывы'],
        'review_moderation_events' => ['actor_user_id' => 'события модерации'],
        'review_import_batches' => ['imported_by' => 'импорт отзывов'],
        'review_duplicate_candidates' => ['reviewed_by' => 'проверка дублей отзывов'],
        'payment_audit_log' => ['actor_id' => 'платёжный журнал'],
        'refunds' => ['created_by' => 'возвраты'],
        'admin_support_tickets' => ['user_id' => 'обращения', 'assignee_id' => 'обращения'],
        'admin_tasks' => ['assignee_id' => 'задачи', 'created_by' => 'задачи'],
        'admin_short_links' => ['created_by' => 'короткие ссылки'],
        'admin_user_events' => ['user_id' => 'журнал администратора'],
        'admin_impersonation_events' => ['subject_id' => 'события impersonation', 'actor_id' => 'события impersonation'],
        'admin_operation_events' => ['actor_id' => 'журнал операций'],
        'legacy_user_profiles' => ['user_id' => 'legacy-профиль'],
    ];

    public function delete(User $actor, int $userId, array $data): array
    {
        abort_unless(app()->environment(['local', 'testing', 'staging']), 403,
            'Удаление тестовых аккаунтов разрешено только в local, testing и staging.');

        return DB::transaction(function () use ($actor, $userId, $data): array {
            abort_unless(Schema::hasTable('admin_operation_events'), 503,
                'Журнал административных операций недоступен.');

            $existing = AdminOperationEvent::query()->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing) {
                abort_unless($existing->actor_id === $actor->id
                    && $existing->operation === 'delete_test_account'
                    && $existing->resource === 'User', 409,
                    'Ключ повтора уже использован для другой операции.');

                return ['deleted' => true, 'user_id' => (int) $existing->resource_id];
            }

            $user = User::query()->lockForUpdate()->findOrFail($userId);
            $freshActor = $actor->fresh();
            abort_unless($freshActor && Rbac::hasPermission($freshActor, 'users.delete')
                && Rbac::canAccessUserScope($freshActor, $user)
                && $freshActor->id !== $user->id, 403);

            abort_unless(app()->environment(['local', 'testing', 'staging']), 403,
                'Удаление тестовых аккаунтов разрешено только в local, testing и staging.');
            abort_unless($user->role === 'user_no_purchase', 409,
                'Удаляются только свежие тестовые аккаунты без покупок.');
            abort_unless(! $user->legacy_auto_verified && ! $user->blocksAuthenticationForMigration(), 409,
                'Legacy-аккаунт или аккаунт на удержании миграции удалять нельзя.');

            $blocked = $this->protectedReference($user->id);
            abort_unless($blocked === null, 409, "Тестовый аккаунт нельзя удалить: обнаружены {$blocked}.");

            $email = mb_strtolower(trim($user->email));
            $phone = User::normalizePhone((string) ($user->phone ?? ''));

            DB::table('personal_access_tokens')
                ->where('tokenable_type', User::class)
                ->where('tokenable_id', $user->id)
                ->delete();
            DB::table('sessions')->where('user_id', $user->id)->delete();
            DB::table('password_reset_tokens')->whereIn('email', array_values(array_unique([$user->email, $email])))->delete();
            if (Schema::hasTable('pre_registration_verifications')) {
                DB::table('pre_registration_verifications')->where(function ($query) use ($email, $phone): void {
                    $query->where(fn ($emailQuery) => $emailQuery->where('channel', 'email')->where('target', $email));
                    if ($phone !== null) {
                        $query->orWhere(fn ($phoneQuery) => $phoneQuery->where('channel', 'phone')->where('target', $phone));
                    }
                })->delete();
            }
            $this->deleteTrustedBrowserState($user->id);

            $event = AdminOperationEvent::query()->create([
                'actor_id' => $freshActor->id,
                'operation' => 'delete_test_account',
                'resource' => 'User',
                'resource_id' => $user->id,
                'idempotency_key' => $data['idempotency_key'],
                'payload' => ['email' => $email, 'reason' => 'staging_registration_reset'],
            ]);

            $user->delete();

            return ['deleted' => true, 'user_id' => $userId, 'operation_id' => $event->id];
        }, 3);
    }

    private function protectedReference(int $userId): ?string
    {
        if (Schema::hasTable('admin_operation_events')
            && DB::table('admin_operation_events')->where('resource', 'User')->where('resource_id', $userId)->exists()) {
            return 'журнал операций';
        }

        foreach (self::PROTECTED_REFERENCES as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach ($columns as $column => $label) {
                if (Schema::hasColumn($table, $column)
                    && DB::table($table)->where($column, $userId)->exists()) {
                    return $label;
                }
            }
        }

        return null;
    }

    private function deleteTrustedBrowserState(int $userId): void
    {
        if (! Schema::hasTable('trusted_browser_accounts')) {
            return;
        }

        $accountIds = DB::table('trusted_browser_accounts')->where('user_id', $userId)->pluck('id');
        if (Schema::hasTable('trusted_browser_audit_events')) {
            DB::table('trusted_browser_audit_events')->where(function ($query) use ($userId, $accountIds): void {
                $query->where('user_id', $userId);
                if ($accountIds->isNotEmpty()) {
                    $query->orWhereIn('account_id', $accountIds->all());
                }
            })->delete();
        }

        DB::table('trusted_browser_accounts')->where('user_id', $userId)->delete();
    }
}
