<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Guide;
use App\Services\AccessResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GuideController extends Controller
{
    public function index(Request $request, AccessResolver $accessResolver): JsonResponse
    {
        $guides = Guide::query()->active()->orderBy('title')->orderBy('id')->get(['id', 'slug', 'title'])
            ->filter(fn (Guide $guide): bool => $accessResolver->check($request->user(), AccessResolver::ENTITY_GUIDE, (int) $guide->id)['access'] === AccessResolver::ACCESS_FULL)
            ->map(fn (Guide $guide): array => $guide->only(['id', 'slug', 'title']))->values()->all();

        return response()->json(['data' => $guides])->header('Cache-Control', 'private, no-store');
    }
}
