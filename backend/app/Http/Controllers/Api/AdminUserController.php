<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateAdminProfileRequest;
use App\Models\User;
use App\Services\AdminStaffProfile;
use App\Support\Rbac;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminUserController extends Controller
{
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return $this->profileResponse($user);
    }

    public function updateMe(UpdateAdminProfileRequest $request, AdminStaffProfile $profile): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return $this->profileResponse($profile->update($user, $request->validated()));
    }

    private function profileResponse(User $user): JsonResponse
    {
        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'phone' => $user->phone,
            'role' => $user->role,
            'permissions' => config("rbac.permissions.{$user->role}", []),
        ]);
    }

    public function show(Request $request, User $user): JsonResponse
    {
        $actor = $request->user();

        if (! Rbac::canAccessUserScope($actor, $user)) {
            abort(403, 'Forbidden by scope');
        }

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'phone' => $user->phone,
            'role' => $user->role,
            'manager_id' => $user->manager_id,
        ]);
    }
}
