<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateAccountProfileRequest;
use App\Models\AdminUserEvent;
use App\Models\User;
use App\Support\Rbac;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class AccountProfileController extends Controller
{
    public function __invoke(UpdateAccountProfileRequest $request): JsonResponse
    {
        $user = DB::transaction(function () use ($request): User {
            $user = User::query()->lockForUpdate()->findOrFail($request->user()->id);
            abort_if($user->blocksAuthenticationForMigration(), 403);
            abort_unless(! $user->usesAdminWorkspace() || Rbac::hasPermission($user, 'admin.self.update'), 403);
            $name = $request->validated('name');

            if ($user->name !== $name) {
                $before = $user->name;
                $user->forceFill(['name' => $name])->save();
                AdminUserEvent::query()->create([
                    'user_id' => $user->id,
                    'actor_id' => $user->id,
                    'actor_name' => $before,
                    'type' => 'profile',
                    'changes' => ['name' => ['before' => $before, 'after' => $name]],
                    'created_at' => now(),
                ]);
            }

            return $user;
        }, 3);

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'role' => $user->role,
            'phone_verified' => (bool) $user->phone_verified_at,
            'email_verified' => (bool) $user->email_verified_at,
            'must_verify_phone' => $user->requiresPhoneVerification(),
            'must_verify_email' => ! (bool) $user->email_verified_at && ! ($user->usesAdminWorkspace() && ! $user->email),
        ])->header('Cache-Control', 'private, no-store');
    }
}
