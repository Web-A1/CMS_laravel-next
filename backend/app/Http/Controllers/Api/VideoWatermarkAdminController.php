<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\VideoWatermarks\VideoWatermarkSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class VideoWatermarkAdminController extends Controller
{
    public function __invoke(Request $request, VideoWatermarkSessionService $watermarks): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:64'],
        ]);
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        $session = $watermarks->lookupByInput((string) $data['code'], $actor);
        if (! $session) {
            return response()->json(['data' => null]);
        }

        $session->load('user:id,name');
        $user = $session->user;

        return response()->json([
            'data' => [
                'code' => $session->code,
                'status' => $session->computedStatus(),
                'startedAt' => $session->started_at?->toJSON(),
                'lastUsedAt' => $session->last_used_at?->toJSON(),
                'endedAt' => $session->ended_at?->toJSON(),
                'user' => [
                    'id' => $user->id,
                    'displayName' => $user->name ?: "ID {$user->id}",
                    'adminHref' => "/admin/users/{$user->id}",
                ],
            ],
        ]);
    }
}
