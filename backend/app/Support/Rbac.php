<?php

namespace App\Support;

use App\Models\User;

class Rbac
{
    public static function mapLegacyRole(?string $legacyRole): string
    {
        $key = strtolower(trim((string) $legacyRole));
        $map = config('rbac.legacy_role_map', []);

        if ($key === '') {
            return 'user_no_purchase';
        }

        return $map[$key] ?? 'user_no_purchase';
    }

    public static function hasPermission(User $user, string $permission): bool
    {
        $permissions = config("rbac.permissions.{$user->role}", []);

        return in_array($permission, $permissions, true);
    }

    public static function canAccessUserScope(User $actor, User $target): bool
    {
        if ($actor->role === 'polina') {
            return in_array($target->role, ['user_with_purchase', 'user_no_purchase'], true);
        }
        if ($actor->role === 'admin_full' || $actor->role === 'admin_limited') {
            return true;
        }

        if (in_array($actor->role, ['manager', 'teacher'], true)) {
            return $actor->id === $target->manager_id;
        }

        return $actor->id === $target->id;
    }

    public static function canImpersonate(User $actor, User $target): bool
    {
        $subjectRoles = config('impersonation.subject_roles', ['user_with_purchase', 'user_no_purchase']);
        $actorRoles = config('impersonation.actor_roles', ['admin_full']);

        return in_array($actor->role, $actorRoles, true)
            && ! $actor->blocksAuthenticationForMigration()
            && self::hasPermission($actor, 'admin.access')
            && self::hasPermission($actor, 'users.impersonate')
            && $actor->id !== $target->id
            && in_array($target->role, $subjectRoles, true)
            && ! $target->usesAdminWorkspace()
            && self::canAccessUserScope($actor, $target);
    }

    public static function canReviewAssessmentUserScope(User $actor, User $student): bool
    {
        if ($actor->role === 'admin_full') {
            return true;
        }

        return in_array($actor->role, ['manager', 'teacher'], true)
            && $actor->id === $student->manager_id;
    }
}
