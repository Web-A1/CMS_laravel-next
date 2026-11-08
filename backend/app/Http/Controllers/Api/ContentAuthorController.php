<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ContentAuthorResource;
use App\Models\ContentAuthor;
use App\Services\Articles\ArticleMedia;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContentAuthorController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate(['placement' => ['required', 'in:home']]);
        $authors = ContentAuthor::query()->whereNotNull('home_sort_order')->orderBy('home_sort_order')->orderBy('id')->get();
        app(ArticleMedia::class)->publicUrls($authors->flatMap(fn ($author) => [$author->photo_path, $author->avatar_path])->all());

        return response()->json(['data' => $authors->map(fn ($author) => (new ContentAuthorResource($author))->forHome()->resolve($request))->all()]);
    }
}
