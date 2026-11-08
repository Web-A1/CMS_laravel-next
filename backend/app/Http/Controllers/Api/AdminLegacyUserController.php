<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\LegacyUsers\LegacyUserSourcePolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AdminLegacyUserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(app(\App\Services\AdminLegacyUserQueries::class)->index($request->user(), $request->all()));
    }

    public function show(Request $request, string $sourceMemberId): JsonResponse
    {
        return response()->json(app(\App\Services\AdminLegacyUserQueries::class)->show($request->user(), $request->all(), $sourceMemberId));
    }

    public function orders(Request $request, string $sourceMemberId, LegacyUserSourcePolicy $policy): JsonResponse
    {
        return response()->json(app(\App\Services\AdminLegacyUserQueries::class)->orders($request->user(), $request->all(), $sourceMemberId, $policy));
    }

    public function accesses(Request $request, string $sourceMemberId): JsonResponse
    {
        return response()->json(app(\App\Services\AdminLegacyUserQueries::class)->accesses($request->user(), $request->all(), $sourceMemberId));
    }

    public function records(Request $request, string $sourceMemberId): JsonResponse
    {
        return response()->json(app(\App\Services\AdminLegacyUserQueries::class)->records($request->user(), $request->all(), $sourceMemberId));
    }

    public function unresolved(Request $request): JsonResponse
    {
        return response()->json(app(\App\Services\AdminLegacyUserQueries::class)->unresolved($request->user(), $request->all()));
    }

}
