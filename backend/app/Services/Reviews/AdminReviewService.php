<?php

namespace App\Services\Reviews;

use App\Exceptions\AdminReviewException;
use App\Http\Requests\AdminReviewRequest;
use App\Models\AdminOperationEvent;
use App\Models\Review;
use App\Models\ReviewDuplicateCandidate;
use App\Models\ReviewModerationEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class AdminReviewService
{
    public const MAX_BULK_REVIEWS = 100;

    public function __construct(private readonly ReviewDuplicateMatcher $duplicates) {}

    public function placeMany(User $actor, array $reviews, bool $showOnHome, string $key): array
    {
        $validated = Validator::make(compact('reviews', 'key'), [
            'reviews' => ['required', 'array', 'min:1', 'max:'.self::MAX_BULK_REVIEWS],
            'reviews.*' => ['required', 'array:id,revision'],
            'reviews.*.id' => ['required', 'integer:strict', 'min:1', 'distinct:strict'],
            'reviews.*.revision' => ['required', 'integer:strict', 'min:0'],
            'key' => ['required', 'string', 'max:160'],
        ])->validate();
        $reviews = array_map(fn (array $item) => ['id' => $item['id'], 'revision' => $item['revision']], $validated['reviews']);
        usort($reviews, fn (array $a, array $b): int => $a['id'] <=> $b['id']);

        return DB::transaction(function () use ($actor, $reviews, $showOnHome, $key): array {
            $actor = User::lockForUpdate()->find($actor->id);
            if ($actor?->role !== 'admin_full' || $actor->blocksAuthenticationForMigration()) {
                throw new AdminReviewException('ADMIN_REVIEW_FORBIDDEN', 'Нет доступа к управлению отзывами.', 403);
            }
            $ids = array_column($reviews, 'id');
            $result = ['ids' => $ids, 'showOnHome' => $showOnHome, 'count' => count($ids)];
            $hash = hash('sha256', json_encode([$reviews, $showOnHome], JSON_THROW_ON_ERROR));
            $keyHash = hash('sha256', $actor->id.':'.$key);
            $receiptKey = 'review-bulk-placement:'.$keyHash;
            $previous = AdminOperationEvent::where('idempotency_key', $receiptKey)->first();
            if ($previous) {
                if ($previous->actor_id !== $actor->id || $previous->operation !== 'place_reviews' || ! hash_equals($previous->payload['request_hash'] ?? '', $hash)) {
                    throw new AdminReviewException('ADMIN_REVIEW_IDEMPOTENCY_CONFLICT', 'Этот ключ уже использован для другого действия.');
                }

                return $result;
            }
            if (AdminOperationEvent::whereIn('idempotency_key', ['review-delete:'.$keyHash, 'review-bulk-delete:'.$keyHash])->exists()
                || ReviewModerationEvent::where('actor_user_id', $actor->id)->where('metadata->idempotency_key_hash', hash('sha256', $key))->exists()) {
                throw new AdminReviewException('ADMIN_REVIEW_IDEMPOTENCY_CONFLICT', 'Этот ключ уже использован для другого действия.');
            }
            if (Review::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get(['id'])->count() !== count($ids)) {
                throw new AdminReviewException('ADMIN_REVIEW_NOT_FOUND', 'Один из выбранных отзывов уже удалён. Обновите список и выберите отзывы заново.', 404);
            }
            foreach ($reviews as $item) {
                $this->execute($actor, 'placement', $item['id'], ['revision' => $item['revision'], 'showOnHome' => $showOnHome],
                    'bulk-placement:'.hash('sha256', $key.':'.$item['id']));
            }
            AdminOperationEvent::create(['actor_id' => $actor->id, 'operation' => 'place_reviews', 'resource' => 'Review',
                'idempotency_key' => $receiptKey, 'payload' => ['request_hash' => $hash, 'ids' => $ids, 'show_on_home' => $showOnHome]]);

            return $result;
        }, 3);
    }

    public function query(): Builder
    {
        return Review::query()
            ->with(['subject:id,title', 'course:id,title,subject_id', 'course.subject:id,title'])
            ->withCount('media')
            ->withCount(['evidence as photo_evidence_count' => fn ($query) => $query->whereIn('type', ['screenshot', 'image'])])
            ->withMax('moderationEvents', 'id');
    }

    public function detail(int $id): Review
    {
        $review = $this->query()->with(['media' => fn ($query) => $query->orderBy('sort_order')->orderBy('id'),
            'evidence.source', 'sources', 'imageAssets',
            'moderationEvents' => fn ($query) => $query->orderByDesc('id'), 'moderationEvents.actor:id,name'])->find($id);
        if (! $review) {
            throw new AdminReviewException('ADMIN_REVIEW_NOT_FOUND', 'Отзыв не найден.', 404);
        }

        return $review;
    }

    public function filteredQuery(array $filters): Builder
    {
        Validator::make($filters, AdminReviewRequest::addedFilterRules($filters))->validate();
        $query = $this->query();
        if (($filters['status'] ?? 'all') !== 'all') {
            $query->where('publication_status', $filters['status']);
        }
        if (($search = trim($filters['search'] ?? '')) !== '') {
            $query->where(fn ($q) => $q->where('display_name', 'like', '%'.$search.'%')->orWhere('text', 'like', '%'.$search.'%'));
        }
        if (($photo = $filters['photo'] ?? 'all') !== 'all') {
            $this->filterPhoto($query, $photo);
        }
        if (filled($filters['subjectId'] ?? null)) {
            $query->where(fn ($q) => $q->where('subject_id', $filters['subjectId'])->orWhereHas('course', fn ($c) => $c->where('subject_id', $filters['subjectId'])));
        }
        if (in_array($filters['home'] ?? null, ['yes', 'no'], true)) {
            $query->where('show_on_home', $filters['home'] === 'yes');
        }
        if (filled($filters['addedFrom'] ?? null)) {
            $query->where('created_at', '>=', $filters['addedFrom'].' 00:00:00');
        }
        if (filled($filters['addedTo'] ?? null)) {
            $query->where('created_at', '<', Carbon::parse($filters['addedTo'])->addDay()->startOfDay());
        }
        $date = $filters['date'] ?? 'newest';
        if ($date === 'this_month') {
            $query->whereBetween('review_written_at', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()]);
        }
        if (filled($filters['addedOrder'] ?? null)) {
            return $query->orderBy('created_at', $filters['addedOrder'] === 'oldest' ? 'asc' : 'desc')->orderBy('id', $filters['addedOrder'] === 'oldest' ? 'asc' : 'desc');
        }

        return $query->orderBy('review_written_at', $date === 'oldest' ? 'asc' : 'desc')->orderByDesc('id');
    }

    public function filterPhoto(Builder $query, string $photo): void
    {
        $images = fn ($evidence) => $evidence->whereIn('type', ['screenshot', 'image']);
        if ($photo === 'no') {
            $query->whereDoesntHave('media')->where(fn ($query) => $query->where('media_managed', true)->orWhereDoesntHave('evidence', $images));
        } else {
            $query->where(fn ($query) => $query->whereHas('media')->orWhere(fn ($query) => $query->where('media_managed', false)->whereHas('evidence', $images)));
        }
    }

    public function execute(User $actor, string $operation, ?int $id, array $input, string $key): Review
    {
        return DB::transaction(function () use ($actor, $operation, $id, $input, $key): Review {
            // All admin review commands take locks in the same order, including creates.
            $lockedActor = User::query()->lockForUpdate()->find($actor->id);
            if ($lockedActor?->role !== 'admin_full') {
                throw new AdminReviewException('ADMIN_REVIEW_FORBIDDEN', 'Нет доступа к управлению отзывами.', 403);
            }
            if ($operation === 'parameters') {
                $rules = AdminReviewRequest::parameterRules();
                if (array_diff(array_keys($input), array_keys($rules))) {
                    throw ValidationException::withMessages(['request' => 'Запрос содержит недопустимые поля.']);
                }
                $input = Validator::make($input, $rules)->validate();
                if ($id === null) {
                    throw new AdminReviewException('ADMIN_REVIEW_NOT_FOUND', 'Отзыв не найден.', 404);
                }
            }
            $keyHash = hash('sha256', $key);
            $deletionKey = hash('sha256', $actor->id.':'.$key);
            if (AdminOperationEvent::whereIn('idempotency_key', ['review-delete:'.$deletionKey, 'review-bulk-delete:'.$deletionKey, 'review-bulk-placement:'.$deletionKey])->exists()) {
                throw new AdminReviewException('ADMIN_REVIEW_IDEMPOTENCY_CONFLICT', 'Этот ключ уже использован для другого действия.');
            }
            ksort($input);
            $requestHash = hash('sha256', json_encode([$operation, $id, $input], JSON_THROW_ON_ERROR));
            $previous = ReviewModerationEvent::query()->where('actor_user_id', $actor->id)
                ->where('metadata->idempotency_key_hash', $keyHash)->first();
            if ($previous) {
                if (! hash_equals((string) ($previous->metadata['request_hash'] ?? ''), $requestHash)) {
                    throw new AdminReviewException('ADMIN_REVIEW_IDEMPOTENCY_CONFLICT', 'Этот ключ уже использован для другого действия.');
                }

                return $this->detail($previous->review_id);
            }

            $review = $id === null ? new Review : Review::query()->lockForUpdate()->find($id);
            if (! $review) {
                throw new AdminReviewException('ADMIN_REVIEW_NOT_FOUND', 'Отзыв не найден.', 404);
            }
            if ($id !== null && (int) $review->moderationEvents()->max('id') !== $input['revision']) {
                throw new AdminReviewException('ADMIN_REVIEW_REVISION_CONFLICT', 'Отзыв изменился. Обновите данные и повторите действие.');
            }
            $fromModeration = $review->moderation_status;
            $fromPublication = $review->publication_status;
            $media = app(ReviewMediaManager::class);
            $before = $id === null ? [] : $media->snapshot($review);
            $oldAssets = $id === null ? [] : $review->imageAssets->all();
            $oldUrls = array_column($before['screenshots'] ?? [], 'url');
            if ($review->author_photo_path) {
                $oldUrls[] = '/storage/'.$review->author_photo_path;
            }

            if ($operation === 'create') {
                $review->fill(['source' => 'admin', 'origin_type' => Review::ORIGIN_MANUAL, 'status' => Review::STATUS_DRAFT,
                    'publication_status' => Review::PUBLICATION_DRAFT, 'published_at' => null]);
                $this->fillInput($review, $input);
                $this->markContentEdited($review);
            } elseif (in_array($operation, ['update', 'parameters'], true)) {
                if ($operation === 'parameters') {
                    $this->fillParameters($review, $input);
                } else {
                    $this->fillInput($review, $input);
                }
                $sourceUnchanged = ! array_key_exists('sourceUrl', $input)
                    || (filled($input['sourceUrl']) ? trim($input['sourceUrl']) : null) === app(ReviewSourceLinks::class)->url($review);
                $screenshotsUnchanged = ! array_key_exists('screenshotPaths', $input)
                    || array_values($input['screenshotPaths']) === array_keys($media->current($review));
                if (! $review->isDirty() && $sourceUnchanged && $screenshotsUnchanged) {
                    return $this->detail($review->id);
                }
                if ($operation === 'update' || $review->isDirty(['display_name', 'text', 'is_anonymous', 'author_photo_path', 'review_written_at', 'subject_id', 'course_id']) || ! $screenshotsUnchanged) {
                    $this->markContentEdited($review);
                }
            } elseif ($operation === 'placement') {
                $review->show_on_home = $input['showOnHome'];
                if (! $review->isDirty()) {
                    return $this->detail($review->id);
                }
            } elseif ($input['action'] === 'publish') {
                if (! in_array($review->publication_status, [Review::PUBLICATION_DRAFT, Review::PUBLICATION_HIDDEN], true)) {
                    throw new AdminReviewException('ADMIN_REVIEW_STATE_CONFLICT', 'Отзыв уже опубликован или находится в архиве.');
                }
                $this->assertPublishable($review);
                $review->fill(['status' => Review::STATUS_PUBLISHED, 'moderation_status' => Review::MODERATION_APPROVED,
                    'publication_status' => Review::PUBLICATION_PUBLISHED, 'dedupe_status' => Review::DEDUPE_UNIQUE,
                    'reviewed_by' => $actor->id, 'reviewed_at' => now(), 'published_at' => now()]);
            } else {
                if ($review->publication_status !== Review::PUBLICATION_PUBLISHED) {
                    throw new AdminReviewException('ADMIN_REVIEW_STATE_CONFLICT', 'Скрыть можно только опубликованный отзыв.');
                }
                $review->publication_status = Review::PUBLICATION_HIDDEN;
            }
            $syncAssets = in_array($operation, ['create', 'update'], true)
                || ($operation === 'parameters' && ($review->isDirty('author_photo_path') || ! $screenshotsUnchanged));
            $review->save();
            if (in_array($operation, ['create', 'update'], true) && array_key_exists('sourceUrl', $input)) {
                app(ReviewSourceLinks::class)->sync($review, $input['sourceUrl']);
            }
            if (in_array($operation, ['create', 'update', 'parameters'], true) && array_key_exists('screenshotPaths', $input)
                && ($operation !== 'parameters' || ! $screenshotsUnchanged)) {
                $media->sync($review, $input['screenshotPaths']);
            }
            if ($syncAssets) {
                $paths = $media->assetPaths($review);
                app(ReviewImageAssets::class)->attach($actor, $review, $paths);
                app(ReviewImageRemoval::class)->schedule($review, $oldAssets, $oldUrls, $paths);
            }
            $review->unsetRelation('imageAssets');
            $after = $media->snapshot($review);
            $changes = [];
            foreach ($after as $field => $value) {
                if (($before[$field] ?? null) !== $value) {
                    $changes[$field] = ['before' => $before[$field] ?? null, 'after' => $value];
                }
            }
            $review->moderationEvents()->create([
                'actor_user_id' => $actor->id,
                'event_type' => 'admin_'.($operation === 'publication' ? $input['action'] : $operation),
                'from_moderation_status' => $fromModeration,
                'to_moderation_status' => $review->moderation_status,
                'from_publication_status' => $fromPublication,
                'to_publication_status' => $review->publication_status,
                'metadata' => ['idempotency_key_hash' => $keyHash, 'request_hash' => $requestHash,
                    'show_on_home' => (bool) $review->show_on_home, 'changes' => $changes],
            ]);

            return $this->detail($review->id);
        }, 3);
    }

    private function fillInput(Review $review, array $input): void
    {
        $review->fill([
            'display_name' => $input['isAnonymous'] ? 'Анонимный автор' : trim($input['displayName']),
            'author_photo_path' => $input['isAnonymous'] ? null
                : (array_key_exists('authorPhotoPath', $input) ? $input['authorPhotoPath'] : $review->author_photo_path),
            'is_anonymous' => $input['isAnonymous'], 'text' => trim($input['text']),
            'show_on_home' => $input['showOnHome'] ?? $review->show_on_home ?? false,
            'review_written_at' => $input['reviewWrittenAt'],
            'subject_id' => $input['subjectId'], 'course_id' => $input['courseId'],
        ]);
    }

    private function markContentEdited(Review $review): void
    {
        $review->content_edited_at = now();
        if ($review->publication_status !== Review::PUBLICATION_PUBLISHED) {
            $review->fill(['moderation_status' => Review::MODERATION_NEEDS_REVIEW,
                'dedupe_status' => in_array($review->dedupe_status, [Review::DEDUPE_CANDIDATE, Review::DEDUPE_DUPLICATE], true)
                    ? $review->dedupe_status : Review::DEDUPE_UNCHECKED,
                'reviewed_by' => null, 'reviewed_at' => null]);
        }
    }

    private function fillParameters(Review $review, array $input): void
    {
        if (array_intersect(array_keys($input), ['scope', 'subjectId', 'courseId'])) {
            $binding = array_replace(['scope' => $review->course_id ? 'course' : ($review->subject_id ? 'subject' : 'all'),
                'subjectId' => $review->course_id ? null : $review->subject_id, 'courseId' => $review->course_id],
                array_intersect_key($input, array_flip(['scope', 'subjectId', 'courseId'])));
            $rules = array_intersect_key(AdminReviewRequest::inputRules(), array_flip(['scope', 'subjectId', 'courseId']));
            $validator = Validator::make($binding, $rules);
            $validator->after(fn ($validator) => AdminReviewRequest::validateScope($validator, $binding, false));
            $binding = $validator->validate();
            $review->subject_id = filled($binding['subjectId']) ? (int) $binding['subjectId'] : null;
            $review->course_id = filled($binding['courseId']) ? (int) $binding['courseId'] : null;
        }
        foreach (['reviewWrittenAt' => 'review_written_at', 'showOnHome' => 'show_on_home', 'isAnonymous' => 'is_anonymous'] as $field => $attribute) {
            if (array_key_exists($field, $input)) {
                $review->$attribute = $input[$field];
            }
        }
        if (array_key_exists('isAnonymous', $input) && $input['isAnonymous']) {
            $review->display_name = 'Анонимный автор';
            $review->author_photo_path = null;
        } elseif (array_key_exists('authorPhotoPath', $input)) {
            $review->author_photo_path = $review->is_anonymous ? null : $input['authorPhotoPath'];
        }
    }

    private function assertPublishable(Review $review): void
    {
        $input = ['displayName' => $review->display_name, 'isAnonymous' => $review->is_anonymous, 'text' => $review->text,
            'authorPhotoPath' => $review->author_photo_path,
            'reviewWrittenAt' => $review->review_written_at?->toDateString(),
            'scope' => $review->course_id ? 'course' : ($review->subject_id ? 'subject' : 'all'),
            'subjectId' => $review->subject_id, 'courseId' => $review->course_id];
        $validator = Validator::make($input, AdminReviewRequest::inputRules());
        $validator->after(fn ($validator) => AdminReviewRequest::validateScope($validator, $input));
        if ($validator->fails()) {
            throw new AdminReviewException('ADMIN_REVIEW_VALIDATION_FAILED', 'Проверьте имя, текст, дату и охват отзыва.', 422);
        }
        $candidate = ReviewDuplicateCandidate::query()
            ->where(fn ($query) => $query->where('review_id', $review->id)->orWhere('candidate_review_id', $review->id))
            ->whereIn('status', [ReviewDuplicateCandidate::STATUS_PENDING, ReviewDuplicateCandidate::STATUS_CONFIRMED_DUPLICATE, ReviewDuplicateCandidate::STATUS_MERGED])->exists();
        if ($candidate || in_array($review->dedupe_status, [Review::DEDUPE_CANDIDATE, Review::DEDUPE_DUPLICATE], true)
            || $this->duplicates->findMatch(['text' => $review->text], $review->id) !== null) {
            throw new AdminReviewException('ADMIN_REVIEW_DUPLICATE_CONFLICT', 'Найдено совпадение с другим отзывом. Публикация недоступна до проверки дублей.');
        }
    }
}
