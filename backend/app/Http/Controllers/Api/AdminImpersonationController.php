<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\AdminImpersonationException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AdminImpersonationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminImpersonationController extends Controller
{
    public function start(Request $request, User $user, AdminImpersonationService $impersonation): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User) {
            return response()->json(['error' => ['code' => 'AUTH_UNAUTHORIZED', 'message' => 'Unauthorized']], 401);
        }

        $data = $request->validate([
            'return_path' => ['required', 'string', 'max:512'],
        ]);

        try {
            return response()->json(
                $impersonation->start($request, $actor, $user, (string) $data['return_path']),
                201,
            )->header('Cache-Control', 'private, no-store');
        } catch (AdminImpersonationException $exception) {
            return $exception->toResponse();
        }
    }

    public function current(Request $request, AdminImpersonationService $impersonation): JsonResponse
    {
        return response()->json($impersonation->current($request))
            ->header('Cache-Control', 'private, no-store');
    }

    public function stop(Request $request, AdminImpersonationService $impersonation): JsonResponse
    {
        return response()->json($impersonation->stop($request))
            ->header('Cache-Control', 'private, no-store');
    }
}
