<?php

namespace App\Services;

use App\Http\Requests\AdminUserMutationRequest;
use App\Models\AdminUserEvent;
use App\Models\User;
use App\Models\UserProfile;
use App\Models\UserTag;
use App\Support\Rbac;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdminUserManagement
{
    /** Include shared User fields so writes through existing self-profile flows invalidate stale cards. */
    public static function revisionFor(User $user, int $profileRevision): string
    {
        return hash('sha256', json_encode([
            (int) $user->id, $user->name, $user->first_name, $user->last_name,
            $user->manager_id === null ? null : (int) $user->manager_id,
            $profileRevision,
        ], JSON_THROW_ON_ERROR));
    }

    public function mutate(User $actor, int $userId, string $operation, array $data): array
    {
        return DB::transaction(function () use ($actor, $userId, $operation, $data): array {
            $user = User::query()->lockForUpdate()->findOrFail($userId);
            $actor = $actor->fresh();
            $permission = $operation === 'manager' ? 'users.assign_manager' : 'users.update';
            abort_unless($actor && Rbac::hasPermission($actor, 'users.read')
                && Rbac::hasPermission($actor, $permission)
                && Rbac::canAccessUserScope($actor, $user), 403);
            if ($operation === 'profileParameters') {
                $data = AdminUserMutationRequest::validateFor('profileParameters', $data);
            }

            if ($operation === 'comment') {
                $existing = AdminUserEvent::query()->where('user_id', $userId)
                    ->where('actor_id', $actor->id)->where('idempotency_key', $data['idempotency_key'])->first();
                if ($existing) {
                    abort_if($existing->comment !== $data['comment'], 409, 'Ключ повтора уже использован для другого комментария.');

                    return ['event_id' => $existing->id];
                }
                $event = $this->event($actor, $user, 'comment', [
                    'comment' => $data['comment'], 'idempotency_key' => $data['idempotency_key'],
                ]);

                return ['event_id' => $event->id];
            }

            $profile = UserProfile::query()->firstOrNew(['user_id' => $userId]);
            $profile->user_id = $userId;
            $revision = (int) ($profile->revision ?? 0);
            abort_unless(hash_equals(self::revisionFor($user, $revision), $data['revision']), 409, 'Карточка уже изменена. Обновите данные и повторите правку.');
            $changes = [];

            if (in_array($operation, ['profile', 'profileParameters'], true)) {
                $data = app(AdminStaffProfile::class)->validateNames($user, $data);
                foreach (['name', 'first_name', 'last_name', ...UserProfile::FIELDS] as $field) {
                    if (! array_key_exists($field, $data)) {
                        continue;
                    }
                    $model = in_array($field, ['name', 'first_name', 'last_name'], true) ? $user : $profile;
                    $before = $model->getAttribute($field);
                    $model->setAttribute($field, $data[$field]);
                    $after = $model->getAttribute($field);
                    if ($before !== $after) {
                        $changes[$field] = ['before' => $before, 'after' => $after];
                    }
                }
                if ($user->isDirty()) {
                    $user->save();
                }
            } elseif ($operation === 'manager') {
                $managerId = $data['manager_id'] === null ? null : (int) $data['manager_id'];
                if ($managerId !== null && ($managerId === $userId || ! User::query()
                    ->whereKey($managerId)->whereIn('role', ['manager', 'teacher'])->exists())) {
                    throw ValidationException::withMessages(['manager_id' => 'Выберите менеджера или преподавателя.']);
                }
                if ($user->manager_id !== $managerId) {
                    $changes['manager_id'] = ['before' => $user->manager_id, 'after' => $managerId];
                    $user->forceFill(['manager_id' => $managerId])->save();
                }
            } elseif ($operation === 'tags') {
                $names = collect($data['tags'])->map(fn ($name) => mb_strtolower(trim($name)))
                    ->unique()->sort()->values();
                $before = $user->tags()->pluck('name')->all();
                if ($before !== $names->all()) {
                    $ids = $names->map(fn ($name) => UserTag::query()->firstOrCreate(['name' => $name])->id);
                    $user->tags()->sync($ids);
                    $changes['tags'] = ['before' => $before, 'after' => $names->all()];
                }
            }
            if ($changes !== []) {
                $profile->revision = $revision + 1;
                $profile->save();
                $this->event($actor, $user, $operation === 'profileParameters' ? 'profile' : $operation, ['changes' => $changes]);
            }

            return ['revision' => self::revisionFor($user, (int) ($profile->revision ?? 0))];
        }, 3);
    }

    private function event(User $actor, User $user, string $type, array $attributes): AdminUserEvent
    {
        return AdminUserEvent::query()->create([
            'user_id' => $user->id, 'actor_id' => $actor->id, 'actor_name' => $actor->name,
            'type' => $type, 'created_at' => now(),
            ...$attributes,
        ]);
    }
}
