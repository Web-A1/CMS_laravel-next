<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AdminTestUserDeletionRequest;
use App\Http\Requests\AdminUserMutationRequest;
use App\Models\User;
use App\Services\AccessResolver;
use App\Services\AdminTestUserDeletion;
use App\Services\AdminUserManagement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminUserManagementController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(app(\App\Services\AdminUserQueries::class)->index($request->user(), $request->all()));
    }

    public function show(Request $request, User $user): JsonResponse
    {
        return response()->json(app(\App\Services\AdminUserQueries::class)->show($request->user(), $request->all(), $user));
    }

    public function managers(Request $request): JsonResponse
    {
        return response()->json(app(\App\Services\AdminUserQueries::class)->managers($request->user(), $request->all()));
    }

    public function orders(Request $request, User $user): JsonResponse
    {
        return response()->json(app(\App\Services\AdminUserQueries::class)->orders($request->user(), $request->all(), $user));
    }

    public function entitlements(Request $request, User $user): JsonResponse
    {
        return response()->json(app(\App\Services\AdminUserQueries::class)->entitlements($request->user(), $request->all(), $user));
    }

    public function courses(Request $request, User $user, AccessResolver $resolver): JsonResponse
    {
        return response()->json(app(\App\Services\AdminUserQueries::class)->courses($request->user(), $request->all(), $user, $resolver));
    }

    public function events(Request $request, User $user): JsonResponse
    {
        return response()->json(app(\App\Services\AdminUserQueries::class)->events($request->user(), $request->all(), $user));
    }

    public function updateProfile(AdminUserMutationRequest $request, User $user, AdminUserManagement $service): JsonResponse
    {
        return response()->json($service->mutate($request->user(), $user->id, 'profile', $request->validated()));
    }

    public function updateManager(AdminUserMutationRequest $request, User $user, AdminUserManagement $service): JsonResponse
    {
        return response()->json($service->mutate($request->user(), $user->id, 'manager', $request->validated()));
    }

    public function updateTags(AdminUserMutationRequest $request, User $user, AdminUserManagement $service): JsonResponse
    {
        return response()->json($service->mutate($request->user(), $user->id, 'tags', $request->validated()));
    }

    public function comment(AdminUserMutationRequest $request, User $user, AdminUserManagement $service): JsonResponse
    {
        return response()->json($service->mutate($request->user(), $user->id, 'comment', $request->validated()));
    }

    public function deleteTestAccount(
        AdminTestUserDeletionRequest $request,
        User $user,
        AdminTestUserDeletion $service,
    ): JsonResponse {
        return response()->json($service->delete($request->user(), $user->id, $request->validated()));
    }

}
