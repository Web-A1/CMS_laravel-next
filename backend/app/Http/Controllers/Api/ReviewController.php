<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ReviewResource;
use App\Models\Course;
use App\Models\Review;
use App\Models\Subject;
use App\Services\Reviews\PublicReviewName;
use App\Support\PublicContentSearch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ReviewController extends Controller
{
    private const MAX_PER_PAGE = 1000;

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'subject' => ['nullable', 'string', 'max:120'],
            'course' => ['nullable', 'string', 'max:120'],
            'search' => ['nullable', 'string', 'max:200'],
            'limit' => ['nullable', 'integer', 'between:1,'.self::MAX_PER_PAGE],
            'per_page' => ['nullable', 'integer', 'between:1,'.self::MAX_PER_PAGE],
            'page' => ['nullable', 'integer', 'min:1'],
            'sort' => ['nullable', Rule::in(['latest'])],
            'placement' => ['nullable', Rule::in(['home'])],
        ]);

        $perPage = (int) ($filters['per_page'] ?? $filters['limit'] ?? 20);
        $page = (int) ($filters['page'] ?? 1);
        $totalPublished = Review::query()->published()->count();

        $reviews = $this->orderedReviews(
            $this->applyFilters($this->publishedReviews(), $filters),
            ($filters['placement'] ?? null) === 'home',
        )
            ->paginate($perPage, ['*'], 'page', $page);

        return ReviewResource::collection($reviews->getCollection())->additional([
            'meta' => [
                'limit' => $perPage,
                'page' => $reviews->currentPage(),
                'perPage' => $reviews->perPage(),
                'total' => $reviews->total(),
                'lastPage' => $reviews->lastPage(),
                'sort' => 'latest',
                'totalPublished' => $totalPublished,
                'filters' => [
                    'subject' => $filters['subject'] ?? null,
                    'course' => $filters['course'] ?? null,
                ],
                'facets' => [
                    'subjects' => $this->reviewSubjectFacets(),
                    'courses' => $this->reviewCourseFacets(),
                ],
            ],
        ]);
    }

    public function show(Request $request, Review $review): ReviewResource
    {
        $filters = $request->validate([
            'subject' => ['nullable', 'string', 'max:120'],
            'course' => ['nullable', 'string', 'max:120'],
            'search' => ['nullable', 'string', 'max:200'],
        ]);

        $orderedIds = $this->orderedReviews($this->applyFilters(Review::query()->published(), $filters))
            ->pluck('reviews.id')
            ->values();
        $position = $orderedIds->search($review->id, strict: true);

        abort_if($position === false, 404);

        $review->load([
            'imageAssets',
            'subject',
            'course.subject',
            'media' => fn ($query) => $query->orderBy('sort_order')->orderBy('id'),
            'evidence' => fn ($query) => $query->with('source')->orderBy('id'),
            'sources' => fn ($query) => $query->orderByDesc('is_public')->orderBy('id'),
        ]);

        return (new ReviewResource($review))->additional([
            'meta' => [
                'navigation' => [
                    'previousId' => $position > 0 ? $orderedIds[$position - 1] : null,
                    'nextId' => $position < $orderedIds->count() - 1 ? $orderedIds[$position + 1] : null,
                    'position' => $position + 1,
                    'total' => $orderedIds->count(),
                    'filters' => [
                        'subject' => $filters['subject'] ?? null,
                        'course' => $filters['course'] ?? null,
                    ],
                ],
            ],
        ]);
    }

    private function publishedReviews(): Builder
    {
        return Review::query()
            ->published()
            ->with([
                'imageAssets',
                'subject',
                'course.subject',
                'media' => fn ($query) => $query->orderBy('sort_order')->orderBy('id'),
                'evidence' => fn ($query) => $query->with('source')->orderBy('id'),
                'sources' => fn ($query) => $query->orderByDesc('is_public')->orderBy('id'),
            ]);
    }

    /**
     * @param  array{subject?: string|null, course?: string|null, search?: string|null, placement?: string|null}  $filters
     */
    private function applyFilters(Builder $query, array $filters): Builder
    {
        return $query
            ->when(($filters['placement'] ?? null) === 'home', fn (Builder $query) => $query->where('show_on_home', true))
            ->when($filters['subject'] ?? null, function (Builder $query, string $subjectSlug): void {
                $query->where(function (Builder $subjectFilter) use ($subjectSlug): void {
                    $subjectFilter
                        ->whereHas('subject', fn (Builder $subjectQuery) => $subjectQuery->where('slug', $subjectSlug))
                        ->orWhereHas('course.subject', fn (Builder $subjectQuery) => $subjectQuery->where('slug', $subjectSlug));
                });
            })
            ->when($filters['course'] ?? null, function (Builder $query, string $courseSlug): void {
                $query->whereHas('course', fn (Builder $courseQuery) => $courseQuery->where('slug', $courseSlug));
            })
            ->when(trim($filters['search'] ?? '') !== '', function (Builder $query) use ($filters): void {
                $search = trim($filters['search']);
                $idSearch = ctype_digit($search) ? ltrim($search, '0') : '';
                $names = app(PublicReviewName::class);
                $matchingIds = [];

                // Use the same public name resolver and literal Unicode matching as the displayed data.
                // Read only searchable fields; media and other relations are loaded after pagination.
                $candidates = (clone $query)->withoutEagerLoads()->select([
                    'reviews.id', 'text', 'display_name', 'is_anonymous', 'content_edited_at', 'review_written_at',
                ])->cursor();

                foreach ($candidates as $review) {
                    if ((string) $review->id === $idSearch
                        || mb_stripos((string) $review->text, $search, 0, 'UTF-8') !== false
                        || mb_stripos($names->resolve($review), $search, 0, 'UTF-8') !== false
                        || mb_stripos(PublicContentSearch::date($review->review_written_at, 'UTC'), PublicContentSearch::text($search), 0, 'UTF-8') !== false) {
                        $matchingIds[] = $review->id;
                    }
                }

                $query->whereIntegerInRaw('reviews.id', $matchingIds);
            });
    }

    private function orderedReviews(Builder $query, bool $homePlacement = false): Builder
    {
        if ($homePlacement) {
            return $query
                ->orderByRaw('COALESCE(review_written_at, published_at) DESC')
                ->orderByDesc('reviews.id');
        }

        return $query
            ->orderByDesc('show_on_home')
            ->orderByDesc('published_at')
            ->orderByDesc('reviews.id');
    }

    /**
     * @return array<int, array{slug: string, title: string, reviewsCount: int}>
     */
    private function reviewSubjectFacets(): array
    {
        $subjectCounts = Review::query()
            ->published()
            ->leftJoin('courses', 'courses.id', '=', 'reviews.course_id')
            ->whereRaw('COALESCE(reviews.subject_id, courses.subject_id) IS NOT NULL')
            ->selectRaw('COALESCE(reviews.subject_id, courses.subject_id) as subject_id, COUNT(*) as reviews_count')
            ->groupByRaw('COALESCE(reviews.subject_id, courses.subject_id)');

        return Subject::query()
            ->joinSub($subjectCounts, 'review_subject_counts', function ($join): void {
                $join->on('subjects.id', '=', 'review_subject_counts.subject_id');
            })
            ->orderByDesc('review_subject_counts.reviews_count')
            ->orderBy('subjects.title')
            ->get([
                'subjects.slug',
                'subjects.title',
                DB::raw('review_subject_counts.reviews_count as reviews_count'),
            ])
            ->map(fn (Subject $subject): array => [
                'slug' => $subject->slug,
                'title' => $subject->title,
                'reviewsCount' => (int) $subject->reviews_count,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{itemId: string, courseSlug: string, title: string, shortTitle: string|null, subjectSlug: string, subjectTitle: string, reviewsCount: int}>
     */
    private function reviewCourseFacets(): array
    {
        $courseCounts = Review::query()
            ->published()
            ->whereNotNull('course_id')
            ->selectRaw('course_id, COUNT(*) as reviews_count')
            ->groupBy('course_id');

        return Course::query()
            ->joinSub($courseCounts, 'review_course_counts', function ($join): void {
                $join->on('courses.id', '=', 'review_course_counts.course_id');
            })
            ->join('subjects', 'subjects.id', '=', 'courses.subject_id')
            ->orderBy('subjects.sort_order')
            ->orderBy('subjects.title')
            ->orderBy('courses.sort_order')
            ->orderBy('courses.title')
            ->get([
                'courses.slug',
                'courses.title',
                'courses.short_title',
                'subjects.slug as subject_slug',
                'subjects.title as subject_title',
                DB::raw('review_course_counts.reviews_count as reviews_count'),
            ])
            ->map(fn (Course $course): array => [
                'itemId' => "course:{$course->subject_slug}:{$course->slug}",
                'courseSlug' => $course->slug,
                'title' => $course->title,
                'shortTitle' => $course->short_title,
                'subjectSlug' => $course->subject_slug,
                'subjectTitle' => $course->subject_title,
                'reviewsCount' => (int) $course->reviews_count,
            ])
            ->values()
            ->all();
    }
}
