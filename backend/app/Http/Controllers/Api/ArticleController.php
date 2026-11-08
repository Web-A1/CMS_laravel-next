<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ArticleResource;
use App\Models\ContentCategory;
use App\Models\ContentEntry;
use App\Models\ContentPlacement;
use App\Models\LegacyRouteMapping;
use App\Models\Subject;
use App\Services\Articles\ArticleMedia;
use App\Services\Articles\ArticleRecommendations;
use App\Support\PublicContentSearch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class ArticleController extends Controller
{
    private const MAX_LIMIT = 50;

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'category' => ['nullable', 'string', 'max:120'],
            'subject' => ['nullable', 'string', 'max:120', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'page' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'limit' => ['nullable', 'integer', 'between:1,'.self::MAX_LIMIT],
            'q' => ['nullable', 'string', 'max:120'],
        ]);

        if (filled($filters['category'] ?? null) && filled($filters['subject'] ?? null)) {
            throw ValidationException::withMessages(['subject' => 'Выберите предмет или архивную категорию.']);
        }
        if (filled($filters['category'] ?? null)) {
            ContentCategory::query()->article()->active()->where('slug', $filters['category'])->firstOrFail();
        }
        $subject = filled($filters['subject'] ?? null) ? Subject::query()->active()->where('slug', $filters['subject'])->firstOrFail() : null;
        $limit = isset($filters['limit']) ? (int) $filters['limit'] : null;
        $query = $this->articleEntriesForPlacement(ContentPlacement::ARTICLES_INDEX, $filters['category'] ?? null);
        if ($subject) {
            $query->where('content_entries.subject_id', $subject->id);
        }
        $this->applySearch($query, trim($filters['q'] ?? ''));
        $total = (clone $query)->count();
        $page = (int) ($filters['page'] ?? 1);
        $perPage = $limit ?? 24;
        $entries = $query
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get();
        app(ArticleMedia::class)->prime($entries);

        return ArticleResource::collection($entries)->additional([
            'meta' => [
                'placement' => ContentPlacement::ARTICLES_INDEX,
                'limit' => $limit,
                'total' => $total,
                'page' => $page,
                'perPage' => $perPage,
                'lastPage' => max(1, (int) ceil($total / $perPage)),
            ],
        ]);
    }

    public function show(string $slug): ArticleResource
    {
        $entry = $this->articleEntriesForPlacement(ContentPlacement::ARTICLES_INDEX)
            ->where('content_entries.slug', $slug)
            ->firstOrFail();

        $recommendations = app(ArticleRecommendations::class)->resolve($entry);
        $entry->setRelation('related', $recommendations['articles']);
        $entry->setRelation('relatedFaqs', $recommendations['faqs']);
        app(ArticleMedia::class)->prime(collect([$entry])->merge($entry->related));

        return (new ArticleResource($entry))->withBody();
    }

    public function categories(): JsonResponse
    {
        $counts = $this->articleEntriesForPlacement(ContentPlacement::ARTICLES_INDEX)->reorder()
            ->select('content_entries.content_category_id')->selectRaw('COUNT(*) as aggregate')
            ->groupBy('content_entries.content_category_id')->pluck('aggregate', 'content_category_id');

        return response()->json(['data' => ContentCategory::query()->article()->active()->orderBy('sort_order')->orderBy('id')->get()
            ->map(fn ($category) => ['slug' => $category->slug, 'title' => $category->title, 'count' => $counts[$category->id] ?? 0])->values()]);
    }

    public function subjects(Request $request): JsonResponse
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:120']]);
        $query = $this->articleEntriesForPlacement(ContentPlacement::ARTICLES_INDEX);
        $this->applySearch($query, trim($filters['q'] ?? ''));
        $counts = $query->reorder()
            ->select('content_entries.subject_id')->selectRaw('COUNT(*) as aggregate')
            ->groupBy('content_entries.subject_id')->pluck('aggregate', 'subject_id');

        return response()->json(['data' => Subject::query()->active()->orderBy('sort_order')->orderBy('id')->get()
            ->map(fn ($subject) => ['id' => $subject->id, 'slug' => $subject->slug, 'title' => $subject->title, 'count' => (int) ($counts[$subject->id] ?? 0)])->values()]);
    }

    public function redirect(Request $request): JsonResponse
    {
        $path = $request->validate(['path' => ['required', 'string', 'max:2048']])['path'];
        $mapping = LegacyRouteMapping::query()->where('legacy_path', $path)->where('source_surface', 'public')->where('action', 'redirect')->where('requires_auth', false)->firstOrFail();
        $target = $mapping->target_path;
        if (str_starts_with($target, '/articles?category=')) {
            $category = rawurldecode(substr($target, strlen('/articles?category=')));
            abort_unless(ContentCategory::query()->article()->active()->where('slug', $category)->exists(), 404);
        } elseif (! in_array($target, ['/articles', '/about'], true)) {
            abort_unless(str_starts_with($target, '/articles/') && $this->articleEntriesForPlacement(ContentPlacement::ARTICLES_INDEX)->where('content_entries.slug', substr($target, 10))->exists(), 404);
        }

        return response()->json(['data' => ['targetPath' => $target]]);
    }

    private function applySearch(Builder $query, string $search): void
    {
        if ($search === '') {
            return;
        }

        $needle = PublicContentSearch::text($search);
        $matchingIds = [];
        // Bound body/author loading and apply matching before counts and pagination.
        $candidates = (clone $query)->withoutEagerLoads()->with('author:id,name')->select([
            'content_entries.id', 'title', 'excerpt', 'seo_description', 'body', 'body_format',
            'content_author_id', 'published_at',
        ])->lazy(100);
        foreach ($candidates as $article) {
            $fields = [
                $article->title, $article->excerpt, $article->seo_description,
                PublicContentSearch::text((string) $article->body, $article->body_format === 'html'),
                $article->author?->name,
                PublicContentSearch::date($article->published_at, 'Europe/Moscow'),
            ];
            foreach ($fields as $field) {
                if (mb_stripos(PublicContentSearch::text((string) $field), $needle, 0, 'UTF-8') !== false) {
                    $matchingIds[] = $article->id;
                    break;
                }
            }
        }
        $query->whereIntegerInRaw('content_entries.id', $matchingIds);
    }

    private function articleEntriesForPlacement(string $placement, ?string $categorySlug = null): Builder
    {
        return ContentEntry::query()
            ->select('content_entries.*')
            ->selectRaw('content_placements.sort_order as placement_sort_order')
            ->article()
            ->published()
            ->with(['category', 'subject', 'author'])
            ->join('content_placements', function ($join) use ($placement): void {
                $join
                    ->on('content_placements.content_entry_id', '=', 'content_entries.id')
                    ->where('content_placements.placement', $placement)
                    ->where('content_placements.is_enabled', true);
            })
            ->when($categorySlug, function (Builder $query, string $slug): void {
                $query->whereHas('category', fn (Builder $categoryQuery) => $categoryQuery
                    ->article()
                    ->active()
                    ->where('slug', $slug));
            })
            ->orderByDesc('content_entries.published_at')
            ->orderByDesc('content_entries.id');
    }
}
