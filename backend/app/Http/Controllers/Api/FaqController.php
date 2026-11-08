<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\FaqResource;
use App\Models\ContentEntry;
use App\Models\ContentPlacement;
use App\Models\Subject;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class FaqController extends Controller
{
    private const MAX_LIMIT = 50;

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'placement' => ['nullable', 'string', Rule::in(ContentPlacement::ALLOWED_PUBLIC_FAQ_PLACEMENTS)],
            'category' => ['nullable', 'string', 'max:120'],
            'subject' => ['nullable', 'string', 'max:120', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'limit' => ['nullable', 'integer', 'between:1,'.self::MAX_LIMIT],
        ]);

        $placement = $filters['placement'] ?? ContentPlacement::FAQ_INDEX;
        $limit = isset($filters['limit']) ? (int) $filters['limit'] : null;
        $query = $this->faqEntriesForPlacement($placement, $filters['category'] ?? null);
        if (filled($filters['subject'] ?? null)) {
            $subject = Subject::query()->active()->where('slug', $filters['subject'])->firstOrFail();
            $query->where('content_entries.subject_id', $subject->id);
        }
        $total = (clone $query)->count();
        $entries = $query
            ->when($limit !== null, fn (Builder $query) => $query->limit($limit))
            ->get();

        return FaqResource::collection($entries)->additional([
            'meta' => [
                'placement' => $placement,
                'limit' => $limit,
                'total' => $total,
            ],
        ]);
    }

    private function faqEntriesForPlacement(string $placement, ?string $categorySlug): Builder
    {
        return ContentEntry::query()
            ->select('content_entries.*')
            ->selectRaw('content_placements.sort_order as placement_sort_order')
            ->faq()
            ->published()
            ->with(['category', 'subject'])
            ->join('content_placements', function ($join) use ($placement): void {
                $join
                    ->on('content_placements.content_entry_id', '=', 'content_entries.id')
                    ->where('content_placements.placement', $placement)
                    ->where('content_placements.is_enabled', true);
            })
            ->where(function (Builder $query): void {
                $query
                    ->whereNull('content_entries.content_category_id')
                    ->orWhereHas('category', fn (Builder $categoryQuery) => $categoryQuery->faq()->active());
            })
            ->when($categorySlug, function (Builder $query, string $slug): void {
                $query->whereHas('category', fn (Builder $categoryQuery) => $categoryQuery
                    ->faq()
                    ->active()
                    ->where('slug', $slug));
            })
            ->orderBy('content_placements.sort_order')
            ->orderBy('content_entries.sort_order')
            ->orderBy('content_entries.id');
    }
}
