<?php

namespace App\Services\Reviews;

use App\Exceptions\AdminReviewException;
use App\Models\AdminOperationEvent;
use App\Models\Review;
use App\Models\ReviewModerationEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class AdminReviewDeletion
{
    public const MAX_BULK_REVIEWS = AdminReviewService::MAX_BULK_REVIEWS;

    public function deleteMany(User $actor, array $reviews, string $confirmation, string $key): array
    {
        $validated = Validator::make(compact('reviews', 'confirmation', 'key'), [
            'reviews' => ['required', 'array', 'min:1', 'max:'.self::MAX_BULK_REVIEWS],
            'reviews.*' => ['required', 'array:id,revision'],
            'reviews.*.id' => ['required', 'integer:strict', 'min:1', 'distinct:strict'],
            'reviews.*.revision' => ['required', 'integer:strict', 'min:0'],
            'confirmation' => ['required', Rule::in([(string) count($reviews)])],
            'key' => ['required', 'string', 'max:160'],
        ], ['confirmation.in' => 'Введите точное количество выбранных отзывов.'])->validate();
        $reviews = array_map(fn (array $item) => ['id' => $item['id'], 'revision' => $item['revision']], $validated['reviews']);
        usort($reviews, fn (array $a, array $b): int => $a['id'] <=> $b['id']);

        return DB::transaction(function () use ($actor, $reviews, $confirmation, $key): array {
            DeletedReviews::lock();
            $actor = $this->lockActor($actor);
            $ids = array_column($reviews, 'id');
            $hash = hash('sha256', json_encode([$reviews, $confirmation], JSON_THROW_ON_ERROR));
            $receiptKey = 'review-bulk-delete:'.hash('sha256', $actor->id.':'.$key);
            $previous = AdminOperationEvent::where('idempotency_key', $receiptKey)->first();
            if ($previous) {
                if ($previous->actor_id !== $actor->id || $previous->operation !== 'delete_reviews' || ! hash_equals($previous->payload['request_hash'] ?? '', $hash)) {
                    throw new AdminReviewException('ADMIN_REVIEW_IDEMPOTENCY_CONFLICT', 'Этот ключ уже использован для другого действия.');
                }

                return ['deleted' => true, 'ids' => $ids, 'count' => count($ids)];
            }
            $this->assertUnusedKey($actor, $key);
            if (Review::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get(['id'])->count() !== count($ids)) {
                throw new AdminReviewException('ADMIN_REVIEW_NOT_FOUND', 'Один из выбранных отзывов уже удалён. Обновите список и выберите отзывы заново.', 404);
            }
            AdminOperationEvent::create(['actor_id' => $actor->id, 'operation' => 'delete_reviews', 'resource' => 'Review',
                'idempotency_key' => $receiptKey, 'payload' => ['request_hash' => $hash, 'ids' => $ids]]);
            foreach ($reviews as $item) {
                $this->delete($actor, $item['id'], ['revision' => $item['revision'], 'confirmation' => (string) $item['id']],
                    'bulk:'.hash('sha256', $key.':'.$item['id']));
            }

            return ['deleted' => true, 'ids' => $ids, 'count' => count($ids)];
        }, 3);
    }

    public function delete(User $actor, int $id, array $input, string $key): array
    {
        return DB::transaction(function () use ($actor, $id, $input, $key): array {
            DeletedReviews::lock();
            $actor = $this->lockActor($actor);
            if (($input['confirmation'] ?? null) !== (string) $id) {
                throw new AdminReviewException('ADMIN_REVIEW_VALIDATION_FAILED', 'Для удаления введите ID отзыва.', 422);
            }
            ksort($input);
            $hash = hash('sha256', json_encode([$id, $input], JSON_THROW_ON_ERROR));
            $receiptKey = 'review-delete:'.hash('sha256', $actor->id.':'.$key);
            $previous = AdminOperationEvent::where('idempotency_key', $receiptKey)->first();
            if ($previous) {
                if ($previous->actor_id !== $actor->id || $previous->resource_id !== $id || ! hash_equals($previous->payload['request_hash'] ?? '', $hash)) {
                    throw new AdminReviewException('ADMIN_REVIEW_IDEMPOTENCY_CONFLICT', 'Этот ключ уже использован для другого действия.');
                }

                return ['deleted' => true, 'id' => $id];
            }
            $this->assertUnusedKey($actor, $key);
            $review = Review::lockForUpdate()->find($id);
            if (! $review) {
                throw new AdminReviewException('ADMIN_REVIEW_NOT_FOUND', 'Отзыв не найден.', 404);
            }
            if ((int) $review->moderationEvents()->max('id') !== $input['revision']) {
                throw new AdminReviewException('ADMIN_REVIEW_REVISION_CONFLICT', 'Отзыв изменился. Обновите данные и повторите действие.');
            }
            $fingerprints = DeletedReviews::fingerprints('review:'.$review->source, $review->source_ref);
            foreach ($review->sources()->get() as $source) {
                $fingerprints = [...$fingerprints, ...DeletedReviews::fingerprints($source->source_type, $source->source_ref, $source->external_id)];
            }
            AdminOperationEvent::create(['actor_id' => $actor->id, 'operation' => 'delete_review', 'resource' => 'Review', 'resource_id' => $id,
                'idempotency_key' => $receiptKey, 'payload' => ['request_hash' => $hash, 'source_fingerprints' => array_values(array_unique($fingerprints))]]);
            app(ReviewImageRemoval::class)->removeReview($review);
            $review->moderationEvents()->delete();
            $review->delete();

            return ['deleted' => true, 'id' => $id];
        }, 3);
    }

    private function lockActor(User $actor): User
    {
        $actor = User::lockForUpdate()->find($actor->id);
        if ($actor?->role !== 'admin_full' || $actor->blocksAuthenticationForMigration()) {
            throw new AdminReviewException('ADMIN_REVIEW_FORBIDDEN', 'Нет доступа к удалению отзывов.', 403);
        }

        return $actor;
    }

    private function assertUnusedKey(User $actor, string $key): void
    {
        $hash = hash('sha256', $actor->id.':'.$key);
        if (AdminOperationEvent::whereIn('idempotency_key', ['review-delete:'.$hash, 'review-bulk-delete:'.$hash, 'review-bulk-placement:'.$hash])->exists()
            || ReviewModerationEvent::where('actor_user_id', $actor->id)->where('metadata->idempotency_key_hash', hash('sha256', $key))->exists()) {
            throw new AdminReviewException('ADMIN_REVIEW_IDEMPOTENCY_CONFLICT', 'Этот ключ уже использован для другого действия.');
        }
    }
}
