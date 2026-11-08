<?php

namespace App\Services;

use App\Models\AdminUserEvent;
use App\Models\User;
use App\Support\ImpersonationContext;
use App\Support\Rbac;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class AdminStaffProfile
{
    public function update(User $actor, array $data, ?array $expectedSnapshot = null): User
    {
        abort_if(ImpersonationContext::isActive(request()), 403);

        return DB::transaction(function () use ($actor, $data, $expectedSnapshot): User {
            $current = User::query()->lockForUpdate()->findOrFail($actor->getKey());
            abort_unless(! $current->blocksAuthenticationForMigration()
                && Rbac::hasPermission($current, 'admin.access')
                && Rbac::hasPermission($current, 'admin.self.update'), 403);
            abort_if($expectedSnapshot !== null && $current->only(array_keys($expectedSnapshot)) !== $expectedSnapshot,
                409, 'Профиль изменился. Откройте страницу заново.');

            if (array_diff(array_keys($data), ['name', 'first_name', 'last_name']) !== [] || ! array_key_exists('name', $data)) {
                throw ValidationException::withMessages(['request' => 'The request contains unexpected fields.']);
            }
            if (is_string($data['name'])) {
                $data['name'] = trim($data['name']);
            }
            $data = $this->validateNames($current, $data);
            $validated = Validator::make($data, ['name' => ['required', 'string', 'max:255']] + self::nameRules())->validate();
            $before = $current->only(array_keys($validated));
            $current->forceFill($validated);
            $changes = [];
            foreach ($validated as $field => $value) {
                if ($before[$field] !== $value) {
                    $changes[$field] = ['before' => $before[$field], 'after' => $value];
                }
            }
            if ($changes === []) {
                return $current;
            }
            $current->save();
            if (array_intersect(array_keys($changes), ['first_name', 'last_name']) !== []) {
                AdminUserEvent::query()->create(['user_id' => $current->id, 'actor_id' => $current->id,
                    'actor_name' => $current->name, 'type' => 'profile', 'changes' => $changes, 'created_at' => now()]);
            }

            return $current->refresh();
        });
    }

    public static function nameRules(): array
    {
        return array_fill_keys(['first_name', 'last_name'], ['sometimes', 'nullable', 'string', 'max:120', 'not_regex:/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u']);
    }

    public function validateNames(User $user, array $data): array
    {
        $fields = array_intersect_key($data, self::nameRules());
        if ($fields !== [] && ! $user->hasStaffNameFields()) {
            throw ValidationException::withMessages(['first_name' => 'Раздельные имя и фамилия доступны преподавателю и администратору.']);
        }
        foreach ($fields as $field => $value) {
            $data[$field] = is_string($value) ? (trim($value) === '' ? null : trim($value)) : $value;
        }
        Validator::make($data, self::nameRules())->validate();

        return $data;
    }
}
