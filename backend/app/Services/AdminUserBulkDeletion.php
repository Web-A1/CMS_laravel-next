<?php

namespace App\Services;

use App\Models\AdminOperationEvent;
use App\Models\Review;
use App\Models\User;
use App\Services\ProtectedFiles\ProtectedFileOutputRemoval;
use App\Services\Reviews\DeletedReviews;
use App\Services\Reviews\ReviewImageRemoval;
use App\Support\ImpersonationContext;
use App\Support\Rbac;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

final class AdminUserBulkDeletion
{
    public const MAX_USERS = 100;

    public function preview(User $actor, array $ids): array
    {
        $ids = $this->ids($ids);

        return DB::transaction(function () use ($actor, $ids): array {
            $this->lock();
            $actor = $this->actor($actor);
            $this->assertReady();
            $graph = $this->graph($actor, $ids);
            $this->assertSafe($graph);
            $fingerprint = hash('sha256', Str::random(80));
            Cache::put('user-deletion-preview:'.$fingerprint, [
                'actor_id' => $actor->id, 'ids' => $ids, 'digest' => $this->digest($graph),
            ], now()->addMinutes(10));

            return ['ids' => $ids, 'users' => array_map(fn ($user) => [
                'id' => (int) $user->id, 'name' => $user->name, 'email' => $user->email,
            ], $graph['users']), 'fingerprint' => $fingerprint, 'counts' => $this->counts($graph)];
        }, 3);
    }

    public function deleteMany(User $actor, array $ids, string $fingerprint, string $confirmation, string $key): array
    {
        $ids = $this->ids($ids);
        Validator::make(compact('fingerprint', 'confirmation', 'key'), [
            'fingerprint' => ['required', 'regex:/\A[a-f0-9]{64}\z/'],
            'confirmation' => ['required', 'in:'.count($ids)],
            'key' => ['required', 'string', 'max:128'],
        ], ['confirmation.in' => 'Введите точное количество выбранных профилей.'])->validate();

        return DB::transaction(function () use ($actor, $ids, $fingerprint, $key): array {
            $this->lock();
            $actor = $this->actor($actor);
            $this->assertReady();
            $hash = hash('sha256', json_encode([$actor->id, $ids, $fingerprint], JSON_THROW_ON_ERROR));
            $receiptKey = 'user-bulk-delete:'.hash('sha256', $key);
            $previous = AdminOperationEvent::where('idempotency_key', $receiptKey)->first();
            if ($previous) {
                abort_unless($previous->actor_id === $actor->id && $previous->operation === 'delete_users'
                    && hash_equals($previous->payload['request_hash'] ?? '', $hash), 409, 'Ключ повтора уже использован для другой операции.');

                return ['ids' => $ids, 'count' => count($ids), 'deleted' => true];
            }
            $preview = Cache::get('user-deletion-preview:'.$fingerprint);
            abort_unless(is_array($preview) && $preview['actor_id'] === $actor->id && $preview['ids'] === $ids,
                409, 'Подтверждение устарело. Откройте обзор удаления заново.');
            $graph = $this->graph($actor, $ids);
            $this->assertSafe($graph);
            abort_unless(hash_equals($preview['digest'], $this->digest($graph)), 409,
                'Данные выбранных профилей изменились. Откройте обзор удаления заново.');
            $this->erase($graph);
            AdminOperationEvent::create([
                'actor_id' => $actor->id, 'operation' => 'delete_users', 'resource' => 'User',
                'idempotency_key' => $receiptKey,
                'payload' => ['ids' => $ids, 'request_hash' => $hash, 'counts' => $this->counts($graph),
                    'source_member_ids' => array_map(fn ($row) => $row->source_member_id, $graph['legacy_user_profiles'])],
            ]);
            DB::table('users')->whereIn('id', $ids)->delete();

            return ['ids' => $ids, 'count' => count($ids), 'deleted' => true];
        }, 3);
    }

    private function ids(array $ids): array
    {
        Validator::make(['ids' => $ids], ['ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_USERS],
            'ids.*' => ['required', 'integer:strict', 'min:1', 'distinct:strict']])->validate();
        sort($ids, SORT_NUMERIC);

        return array_values($ids);
    }

    private function lock(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::select('SELECT pg_advisory_xact_lock(183621719, 104)');
        }
        DeletedReviews::lock();
    }

    private function actor(User $actor): User
    {
        abort_unless(app()->environment(['local', 'testing', 'staging']), 403);
        $request = request();
        abort_if(ImpersonationContext::isActive($request)
            || $request->hasSession() && ($request->session()->has(ImpersonationContext::SESSION_KEY)
                || $request->session()->has(ImpersonationContext::EXPIRED_SESSION_KEY)), 403);
        $fresh = User::lockForUpdate()->find($actor->id);
        abort_unless($fresh && Rbac::hasPermission($fresh, 'users.delete') && ! $fresh->blocksAuthenticationForMigration(), 403);

        return $fresh;
    }

    private function graph(User $actor, array $ids): array
    {
        $users = User::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
        abort_unless($users->count() === count($ids), 409, 'Один из выбранных профилей уже удалён.');
        foreach ($users as $user) {
            abort_unless($user->id !== $actor->id && Rbac::canAccessUserScope($actor, $user)
                && in_array($user->role, ['user_no_purchase', 'user_with_purchase'], true)
                && ! $user->usesAdminWorkspace(), 403, 'Удалять можно только доступные ученические профили.');
        }
        $graph = ['users' => $users->all()];
        $load = function (string $table, string $column, array $values) use (&$graph): array {
            $key = $table === 'user_profiles' ? 'user_id' : 'id';
            $graph[$table] = DB::table($table)->whereIn($column, $values)->orderBy($key)->lockForUpdate()->get()->all();

            return array_map(fn ($row) => $row->{$key}, $graph[$table]);
        };
        $load('user_profiles', 'user_id', $ids);
        $graph['user_tag'] = DB::table('user_tag')->whereIn('user_id', $ids)->orderBy('user_id')->orderBy('user_tag_id')->lockForUpdate()->get()->all();
        $profiles = $load('legacy_user_profiles', 'user_id', $ids);
        $orders = $load('orders', 'user_id', $ids);
        $attempts = $load('payment_attempts', 'order_id', $orders);
        $refunds = $load('refunds', 'order_id', $orders);
        $load('order_items', 'order_id', $orders);
        $load('order_settlements', 'order_id', $orders);
        $load('coin_reservations', 'user_id', $ids);
        $load('promo_reservations', 'user_id', $ids);
        $assessmentAttempts = $load('lesson_assessment_attempts', 'user_id', $ids);
        $load('lesson_assessment_attempt_responses', 'lesson_assessment_attempt_id', $assessmentAttempts);
        $load('lesson_assessment_manual_reviews', 'lesson_assessment_attempt_id', $assessmentAttempts);
        $load('lesson_assessment_completions', 'user_id', $ids);
        $reviewIds = $load('reviews', 'user_id', $ids);
        $sources = $load('review_sources', 'review_id', $reviewIds);
        $load('review_media', 'review_id', $reviewIds);
        $graph['review_evidence'] = DB::table('review_evidence')->whereIn('review_id', $reviewIds)
            ->orWhereIn('review_source_id', $sources)->orderBy('id')->lockForUpdate()->get()->all();
        $graph['review_image_assets'] = DB::table('review_image_assets')->whereIn('review_id', $reviewIds)
            ->orWhereIn('owner_id', $ids)->orderBy('id')->lockForUpdate()->get()->all();
        $load('protected_file_deliveries', 'user_id', $ids);
        $accounts = $load('trusted_browser_accounts', 'user_id', $ids);
        $slots = $load('trusted_browser_slots', 'account_id', $accounts);
        $load('trusted_browser_credentials', 'slot_id', $slots);
        $load('trusted_browser_challenges', 'account_id', $accounts);
        $load('trusted_browser_session_links', 'account_id', $accounts);
        $activations = $load('legacy_user_activations', 'user_id', $ids);
        $load('legacy_activation_phone_attempts', 'activation_id', $activations);
        $load('legacy_user_orders', 'legacy_user_profile_id', $profiles);
        $load('legacy_user_order_items', 'legacy_user_profile_id', $profiles);
        $load('legacy_user_accesses', 'legacy_user_profile_id', $profiles);
        $graph['legacy_user_rights_states'] = DB::table('legacy_user_rights_states')->whereIn('legacy_user_profile_id', $profiles)
            ->orderBy('legacy_user_profile_id')->lockForUpdate()->get()->all();
        foreach (['user_entitlement_grants', 'lesson_video_playback_positions', 'admin_support_tickets', 'registration_consents',
            'phone_verification_codes', 'auth_login_codes'] as $table) {
            $load($table, 'user_id', $ids);
        }
        // Retained rows participate in operation guards and stale-preview detection, never deletion counts.
        foreach (['provider_events', 'outbox_events', 'side_effect_executions'] as $table) {
            $graph['retained_'.$table] = DB::table($table)->whereIn('order_id', $orders)
                ->orWhereIn('attempt_id', $attempts)
                ->when($table !== 'provider_events', fn ($query) => $query->orWhereIn('refund_id', $refunds))
                ->when($table === 'side_effect_executions', fn ($query) => $query->orWhereIn('outbox_event_id', array_column($graph['retained_outbox_events'], 'id')))
                ->orderBy('id')->lockForUpdate()->get()->all();
        }

        return $graph;
    }

    private function assertReady(): void
    {
        abort_unless(Schema::hasTable('protected_file_output_deletions')
            && Schema::hasTable('migrations')
            && DB::table('migrations')->where('migration', '2026_10_20_120000_preserve_user_deletion_audit_history')->exists(),
            503, 'Для массового удаления сначала примените миграции базы данных.');
    }

    private function assertSafe(array $graph): void
    {
        foreach (['payment_attempts' => ['completed', 'cancelled', 'failed', 'expired'],
            'refunds' => ['completed', 'failed', 'cancelled'], 'retained_outbox_events' => ['processed'],
            'retained_side_effect_executions' => ['completed'], 'protected_file_deliveries' => ['ready', 'failed'],
            'review_image_assets' => ['ready', 'failed']] as $table => $terminal) {
            foreach ($graph[$table] as $row) {
                abort_unless(in_array($row->status, $terminal, true), 409,
                    'Есть незавершённая операция ('.$table.'). Повторите удаление после её завершения.');
            }
        }
        foreach ($graph['retained_provider_events'] as $row) {
            abort_unless($row->processed && $row->processed_at, 409, 'Платёжное событие ещё обрабатывается.');
        }
        foreach ($graph['legacy_user_activations'] as $row) {
            abort_unless($row->completed_at || now()->greaterThan($row->expires_at), 409, 'Активация профиля ещё не завершена.');
        }
        $ids = array_map(fn ($row) => $row->id, $graph['users']);
        $orders = array_map(fn ($row) => $row->id, $graph['orders']);
        foreach (['coin_reservations', 'promo_reservations', 'user_entitlement_grants'] as $table) {
            abort_if(DB::table($table)->whereIn('order_id', $orders)->whereNotIn('user_id', $ids)->exists(), 409,
                'Обнаружена связь заказа с другим профилем.');
        }
        $attemptIds = array_column($graph['payment_attempts'], 'id');
        foreach (['refunds' => 'attempt_id', 'order_settlements' => 'payment_attempt_id'] as $table => $column) {
            abort_if(DB::table($table)->whereIn($column, $attemptIds)->whereNotIn('order_id', $orders)->exists(), 409,
                'Платёж связан с посторонним заказом.');
        }
        abort_if(DB::table('orders')->whereIn('active_attempt_id', $attemptIds)->whereNotIn('id', $orders)->exists(), 409,
            'Попытка оплаты используется другим заказом.');
        foreach (['admin_operation_events' => 'actor_id', 'admin_tasks' => 'created_by', 'admin_short_links' => 'created_by',
            'lesson_assessment_review_events' => 'actor_id', 'lesson_assessment_manual_reviews' => 'reviewer_id'] as $table => $column) {
            abort_if(DB::table($table)->whereIn($column, $ids)->exists(), 409, 'У профиля сохранено служебное авторство: '.$table.'.');
        }
        $reviewIds = array_map(fn ($row) => $row->id, $graph['reviews']);
        $assetIds = array_map(fn ($row) => $row->id, $graph['review_image_assets']);
        abort_if(DB::table('review_image_deletions')->where(function ($query) use ($reviewIds, $assetIds): void {
            $query->whereIn('review_id', $reviewIds)->orWhereIn('asset_id', $assetIds);
        })->exists(), 409, 'Удаление изображения ещё не завершено.');
    }

    private function digest(array $graph): string
    {
        return hash('sha256', json_encode($graph, JSON_THROW_ON_ERROR));
    }

    private function counts(array $graph): array
    {
        $counts = [];
        foreach ($graph as $table => $rows) {
            if (! str_starts_with($table, 'retained_')) {
                $counts[$table] = count($rows);
            }
        }

        return $counts;
    }

    private function erase(array $graph): void
    {
        $ids = array_map(fn ($row) => $row->id, $graph['users']);
        foreach ($graph['protected_file_deliveries'] as $delivery) {
            app(ProtectedFileOutputRemoval::class)->schedule($delivery->output_object_key);
        }
        foreach (['legacy_activation_phone_attempts', 'legacy_user_activations'] as $table) {
            DB::table($table)->whereIn('id', array_column($graph[$table], 'id'))->delete();
        }
        DB::table('legacy_user_rights_states')->whereIn('legacy_user_profile_id', array_column($graph['legacy_user_profiles'], 'id'))->delete();
        foreach (['lesson_assessment_completions', 'lesson_assessment_manual_reviews', 'lesson_assessment_attempts',
            'promo_reservations', 'coin_reservations'] as $table) {
            DB::table($table)->whereIn('id', array_column($graph[$table], 'id'))->delete();
        }
        foreach (Review::whereIn('id', array_column($graph['reviews'], 'id'))->orderBy('id')->get() as $review) {
            app(ReviewImageRemoval::class)->removeReview($review);
            $review->delete();
        }
        app(ReviewImageRemoval::class)->removeDetachedOwnerAssets($ids);
        foreach (['protected_file_deliveries', 'user_entitlement_grants', 'lesson_video_playback_positions', 'admin_support_tickets', 'orders',
            'legacy_user_order_items', 'legacy_user_accesses', 'legacy_user_orders', 'legacy_user_profiles'] as $table) {
            $rowIds = array_column($graph[$table], 'id');
            if (str_starts_with($table, 'legacy_user_')) {
                DB::table('legacy_import_mappings')->where('source_scope_type', 'legacy_users')->where('target_domain', 'legacy_user_history')
                    ->where('target_type', $table)->whereIn('target_id', $rowIds)->delete();
            }
            DB::table($table)->whereIn('id', $rowIds)->delete();
        }
        DB::table('video_watermark_sessions')->whereIn('user_id', $ids)->whereNull('ended_at')->update([
            'ended_at' => now(), 'end_reason' => 'revoked', 'purge_after' => now()->addYears(3), 'updated_at' => now(),
        ]);
        DB::table('personal_access_tokens')->where('tokenable_type', User::class)->whereIn('tokenable_id', $ids)->delete();
        DB::table('sessions')->whereIn('user_id', $ids)->delete();
        foreach ($graph['users'] as $user) {
            $email = mb_strtolower(trim($user->email));
            DB::table('password_reset_tokens')->whereIn('email', [$email, $user->email])->delete();
            DB::table('pre_registration_verifications')->where('channel', 'email')->where('target', $email)->delete();
            $phone = User::normalizePhone((string) $user->phone);
            if ($phone && ! User::whereNotIn('id', $ids)->where('phone', $phone)->exists()) {
                DB::table('pre_registration_verifications')->where('channel', 'phone')->where('target', $phone)->delete();
            }
        }
        DB::table('trusted_browser_accounts')->whereIn('user_id', $ids)->delete();
    }
}
