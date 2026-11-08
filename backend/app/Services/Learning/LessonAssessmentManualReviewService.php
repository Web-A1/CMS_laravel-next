<?php

namespace App\Services\Learning;

use App\Exceptions\LessonAssessmentException;
use App\Models\LessonAssessmentAttempt;
use App\Models\LessonAssessmentCompletion;
use App\Models\LessonAssessmentManualReview;
use App\Models\LessonAssessmentReviewEvent;
use App\Models\User;
use App\Support\Rbac;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

final class LessonAssessmentManualReviewService
{
    public function __construct(private readonly LessonAssessmentScoringService $scoring) {}

    public function list(User $actor, int $perPage = 20, int $page = 1, string $status = 'pending_review', string $search = '', ?\Closure $configureQuery = null): LengthAwarePaginator
    {
        $this->assertPermission($actor, 'assessment-reviews.read');
        $query = LessonAssessmentAttempt::query()
            ->whereHas('manualReviews')
            ->with($this->attemptRelations())
            ->orderByDesc('submitted_at')
            ->orderByDesc('id');

        if ($actor->role !== 'admin_full') {
            $query->whereHas('user', fn ($userQuery) => $userQuery->where('manager_id', $actor->id));
        }
        if ($status === 'pending_review') {
            $query->where('status', LessonAssessmentAttempt::STATUS_PENDING_REVIEW);
        } elseif ($status === 'finalized') {
            $query->whereNotNull('finalized_at');
        }
        if ($search !== '') {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($search)).'%';
            $query->where(function ($searchQuery) use ($pattern): void {
                $searchQuery->whereHas('user', fn ($userQuery) => $userQuery
                    ->whereRaw("LOWER(name) LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereRaw("LOWER(email) LIKE ? ESCAPE '!'", [$pattern]))
                    ->orWhereHas('assessment.lesson', fn ($lessonQuery) => $lessonQuery->whereRaw("LOWER(title) LIKE ? ESCAPE '!'", [$pattern]))
                    ->orWhereHas('assessment.lesson.course', fn ($courseQuery) => $courseQuery->whereRaw("LOWER(title) LIKE ? ESCAPE '!'", [$pattern]));
            });
        }

        $configureQuery?->__invoke($query);
        $paginator = $query->paginate(min(max($perPage, 1), 100), ['*'], 'page', max($page, 1));

        return $paginator->currentPage() > $paginator->lastPage()
            ? $query->paginate($paginator->perPage(), ['*'], 'page', $paginator->lastPage())
            : $paginator;
    }

    /** @return array<string, mixed> */
    public function detail(User $actor, int $attemptId): array
    {
        $this->assertPermission($actor, 'assessment-reviews.read');
        $attempt = LessonAssessmentAttempt::query()
            ->with($this->attemptRelations())
            ->find($attemptId);
        if (! $attempt instanceof LessonAssessmentAttempt || $attempt->manualReviews->isEmpty()) {
            throw $this->notFound();
        }
        $this->assertScope($actor, $attempt->user);

        return $this->detailPayload($attempt);
    }

    /** @return array<string, mixed> */
    public function update(
        User $actor,
        int $attemptId,
        int $reviewId,
        int $revision,
        int $scoreBps,
        ?string $comment,
        string $idempotencyKey,
    ): array {
        $comment = $this->normalizeComment($comment);
        $payload = [
            'revision' => $revision,
            'scoreBps' => $scoreBps,
            'comment' => $comment,
        ];

        return DB::transaction(function () use ($actor, $attemptId, $reviewId, $revision, $scoreBps, $comment, $idempotencyKey, $payload): array {
            $this->assertPermission($actor, 'assessment-reviews.review');
            $attempt = LessonAssessmentAttempt::query()->lockForUpdate()->find($attemptId);
            if (! $attempt instanceof LessonAssessmentAttempt) {
                throw $this->notFound();
            }
            $student = User::query()->find($attempt->user_id);
            if (! $student instanceof User) {
                throw $this->notFound();
            }
            $this->assertScope($actor, $student);

            $siblings = LessonAssessmentManualReview::query()
                ->where('lesson_assessment_attempt_id', $attempt->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $review = $siblings->firstWhere('id', $reviewId);
            if (! $review instanceof LessonAssessmentManualReview) {
                throw $this->notFound();
            }

            $keyHash = hash('sha256', $idempotencyKey);
            $fingerprint = $this->fingerprint($actor, $attemptId, 'review_update', [
                'reviewId' => $reviewId,
                ...$payload,
            ]);
            $replay = LessonAssessmentReviewEvent::query()
                ->where('lesson_assessment_manual_review_id', $review->id)
                ->where('event_type', 'review_updated')
                ->where('idempotency_key_hash', $keyHash)
                ->first();
            if ($replay instanceof LessonAssessmentReviewEvent) {
                if (! hash_equals($replay->request_fingerprint_sha256, $fingerprint)) {
                    throw $this->conflict();
                }

                return $this->mutationPayload($attempt->refresh(), $review->refresh());
            }

            if (
                $attempt->status !== LessonAssessmentAttempt::STATUS_PENDING_REVIEW
                || $attempt->finalized_at !== null
                || $review->revision !== $revision
            ) {
                throw $this->conflict();
            }

            if ($review->status === LessonAssessmentManualReview::STATUS_DECIDED
                && $review->score_bps === $scoreBps && $review->comment === $comment) {
                return $this->mutationPayload($attempt, $review);
            }

            $previousScore = $review->score_bps;
            $previousComment = $review->comment;
            $review->forceFill([
                'status' => LessonAssessmentManualReview::STATUS_DECIDED,
                'score_bps' => $scoreBps,
                'comment' => $comment,
                'reviewer_id' => $actor->id,
                'revision' => $review->revision + 1,
                'decided_at' => now(),
            ])->save();

            $attempt->revision++;
            $attempt->save();
            LessonAssessmentReviewEvent::query()->create([
                'lesson_assessment_attempt_id' => $attempt->id,
                'lesson_assessment_manual_review_id' => $review->id,
                'actor_id' => $actor->id,
                'event_type' => 'review_updated',
                'previous_review_score_bps' => $previousScore,
                'review_score_bps' => $scoreBps,
                'comment_changed' => $previousComment !== $comment,
                'previous_attempt_status' => LessonAssessmentAttempt::STATUS_PENDING_REVIEW,
                'attempt_status' => LessonAssessmentAttempt::STATUS_PENDING_REVIEW,
                'revision' => $review->revision,
                'idempotency_key_hash' => $keyHash,
                'request_fingerprint_sha256' => $fingerprint,
                'created_at' => now(),
            ]);

            return $this->mutationPayload($attempt->refresh(), $review->refresh());
        });
    }

    /** @return array<string, mixed> */
    public function finalize(
        User $actor,
        int $attemptId,
        int $reviewRevision,
        string $idempotencyKey,
    ): array {
        return DB::transaction(function () use ($actor, $attemptId, $reviewRevision, $idempotencyKey): array {
            $this->assertPermission($actor, 'assessment-reviews.review');
            $attempt = LessonAssessmentAttempt::query()->lockForUpdate()->find($attemptId);
            if (! $attempt instanceof LessonAssessmentAttempt) {
                throw $this->notFound();
            }
            $student = User::query()->find($attempt->user_id);
            if (! $student instanceof User) {
                throw $this->notFound();
            }
            $this->assertScope($actor, $student);
            $reviews = LessonAssessmentManualReview::query()
                ->where('lesson_assessment_attempt_id', $attempt->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $keyHash = hash('sha256', $idempotencyKey);
            $fingerprint = $this->fingerprint($actor, $attemptId, 'finalize', [
                'reviewRevision' => $reviewRevision,
            ]);
            $replay = LessonAssessmentReviewEvent::query()
                ->where('lesson_assessment_attempt_id', $attempt->id)
                ->where('event_type', 'finalized')
                ->where('idempotency_key_hash', $keyHash)
                ->first();
            if ($replay instanceof LessonAssessmentReviewEvent) {
                if (! hash_equals($replay->request_fingerprint_sha256, $fingerprint)) {
                    throw $this->conflict();
                }

                return $this->finalizationPayload($attempt->refresh());
            }

            if (
                $attempt->status !== LessonAssessmentAttempt::STATUS_PENDING_REVIEW
                || $attempt->finalized_at !== null
                || $attempt->revision !== $reviewRevision
            ) {
                throw $this->conflict();
            }
            if ($reviews->isEmpty() || $reviews->contains(
                fn (LessonAssessmentManualReview $review): bool => $review->status !== LessonAssessmentManualReview::STATUS_DECIDED
                    || ! is_int($review->score_bps),
            )) {
                throw $this->incomplete();
            }

            $automaticBps = $attempt->automatic_score['overall']['percentBps'] ?? null;
            if (! is_int($automaticBps) || ($attempt->automatic_score['passed'] ?? null) !== true) {
                throw $this->conflict();
            }
            $manualBps = $this->scoring->manualComponentBps($reviews->pluck('score_bps')->all());
            $finalBps = $this->scoring->finalBps($automaticBps, $manualBps);
            $passed = $manualBps >= 6000 && $finalBps >= 8000;
            $failureReason = $passed
                ? null
                : ($manualBps < 6000
                    ? LessonAssessmentAttempt::FAILURE_MANUAL_THRESHOLD
                    : LessonAssessmentAttempt::FAILURE_COMBINED_THRESHOLD);
            $finalizedAt = now();
            $manualScore = [
                'percentBps' => $manualBps,
                'requiredBps' => 6000,
                'passed' => $manualBps >= 6000,
            ];
            $finalScore = [
                'checked' => true,
                'passed' => $passed,
                'mastered' => $finalBps === 10000,
                'overall' => [
                    'numerator' => $finalBps,
                    'denominator' => 10000,
                    'percentBps' => $finalBps,
                    'requiredBps' => 8000,
                    'passed' => $finalBps >= 8000,
                ],
                'components' => [
                    'automaticBps' => $automaticBps,
                    'manualBps' => $manualBps,
                    'automaticWeightBps' => 7000,
                    'manualWeightBps' => 3000,
                ],
            ];

            $attempt->forceFill([
                'status' => $passed ? LessonAssessmentAttempt::STATUS_PASSED : LessonAssessmentAttempt::STATUS_FAILED,
                'manual_score' => $manualScore,
                'final_score' => $finalScore,
                'failure_reason' => $failureReason,
                'finalized_at' => $finalizedAt,
                'passed_at' => $passed ? $finalizedAt : null,
                'revision' => $attempt->revision + 1,
            ])->save();
            LessonAssessmentReviewEvent::query()->create([
                'lesson_assessment_attempt_id' => $attempt->id,
                'lesson_assessment_manual_review_id' => null,
                'actor_id' => $actor->id,
                'event_type' => 'finalized',
                'previous_attempt_status' => LessonAssessmentAttempt::STATUS_PENDING_REVIEW,
                'attempt_status' => $attempt->status,
                'automatic_bps' => $automaticBps,
                'manual_bps' => $manualBps,
                'final_bps' => $finalBps,
                'failure_reason' => $failureReason,
                'revision' => $attempt->revision,
                'idempotency_key_hash' => $keyHash,
                'request_fingerprint_sha256' => $fingerprint,
                'created_at' => $finalizedAt,
            ]);
            if ($passed) {
                $this->writeCompletion($student, $attempt, $finalizedAt);
            }

            return $this->finalizationPayload($attempt->refresh());
        });
    }

    public function assertActorCanAccessAttempt(User $actor, LessonAssessmentAttempt $attempt, string $permission): void
    {
        $this->assertPermission($actor, $permission);
        $student = User::query()->find($attempt->user_id);
        if (! $student instanceof User) {
            throw $this->notFound();
        }
        $this->assertScope($actor, $student);
    }

    /** @return array<string, mixed> */
    public function summaryPayload(LessonAssessmentAttempt $attempt): array
    {
        $attempt->loadMissing($this->attemptRelations());
        $reviews = $attempt->manualReviews;

        return [
            'id' => (int) $attempt->id,
            'status' => (string) $attempt->status,
            'reviewRevision' => (int) $attempt->revision,
            'student' => [
                'id' => (int) $attempt->user->id,
                'name' => (string) $attempt->user->name,
                'email' => (string) $attempt->user->email,
            ],
            'context' => $this->context($attempt),
            'pendingCount' => $reviews->where('status', LessonAssessmentManualReview::STATUS_PENDING)->count(),
            'reviewedCount' => $reviews->where('status', LessonAssessmentManualReview::STATUS_DECIDED)->count(),
            'manualQuestionCount' => $reviews->count(),
            'submittedAt' => $attempt->submitted_at?->toJSON(),
            'finalizedAt' => $attempt->finalized_at?->toJSON(),
        ];
    }

    /** @return array<string, mixed> */
    private function detailPayload(LessonAssessmentAttempt $attempt): array
    {
        return [
            'attempt' => $this->summaryPayload($attempt),
            'items' => $attempt->manualReviews
                ->sortBy('id')
                ->values()
                ->map(fn (LessonAssessmentManualReview $review): array => $this->itemPayload($review, $attempt))
                ->all(),
            'finalScore' => $attempt->final_score,
            'failureReason' => $attempt->failure_reason,
        ];
    }

    /** @return array<string, mixed> */
    private function mutationPayload(LessonAssessmentAttempt $attempt, LessonAssessmentManualReview $review): array
    {
        $attempt->loadMissing($this->attemptRelations());
        $review->loadMissing('reviewer');

        return [
            'attempt' => $this->summaryPayload($attempt),
            'item' => $this->itemPayload($review, $attempt),
        ];
    }

    /** @return array<string, mixed> */
    private function finalizationPayload(LessonAssessmentAttempt $attempt): array
    {
        $attempt->loadMissing($this->attemptRelations());

        return $this->detailPayload($attempt);
    }

    /** @return array<string, mixed> */
    private function itemPayload(LessonAssessmentManualReview $review, LessonAssessmentAttempt $attempt): array
    {
        $questions = collect(is_array($attempt->version->questions) ? $attempt->version->questions : []);
        $question = $questions->firstWhere('id', $review->question_id);

        return [
            'id' => (int) $review->id,
            'status' => (string) $review->status,
            'revision' => (int) $review->revision,
            'question' => [
                'id' => (string) $review->question_id,
                'prompt' => is_array($question) ? (string) ($question['prompt'] ?? '') : '',
            ],
            'responseSnapshot' => $review->response_snapshot,
            'rubricSnapshot' => $review->rubric_snapshot,
            'scoreBps' => $review->score_bps,
            'comment' => $review->comment,
            'reviewer' => $review->reviewer === null ? null : [
                'id' => (int) $review->reviewer->id,
                'name' => (string) $review->reviewer->name,
            ],
            'decidedAt' => $review->decided_at?->toJSON(),
        ];
    }

    /** @return array<string, mixed> */
    private function context(LessonAssessmentAttempt $attempt): array
    {
        $lesson = $attempt->assessment->lesson;
        $course = $lesson->course;

        return [
            'subject' => ['id' => (int) $course->subject->id, 'title' => (string) $course->subject->title],
            'course' => ['id' => (int) $course->id, 'title' => (string) $course->title],
            'lesson' => ['id' => (int) $lesson->id, 'title' => (string) $lesson->title],
            'assessmentId' => (int) $attempt->lesson_assessment_id,
            'contentVersion' => (int) $attempt->version->content_version,
        ];
    }

    /** @return array<int, string> */
    private function attemptRelations(): array
    {
        return [
            'user',
            'version',
            'assessment.lesson.course.subject',
            'manualReviews.reviewer',
        ];
    }

    private function writeCompletion(User $student, LessonAssessmentAttempt $attempt, mixed $finalizedAt): void
    {
        $lessonId = (int) $attempt->assessment()->value('lesson_id');
        if ($lessonId < 1) {
            throw $this->conflict();
        }
        $completion = LessonAssessmentCompletion::query()
            ->where('user_id', $student->id)
            ->where('lesson_id', $lessonId)
            ->lockForUpdate()
            ->first();
        $replace = ! $completion instanceof LessonAssessmentCompletion;
        if ($completion instanceof LessonAssessmentCompletion) {
            $previousAttempt = LessonAssessmentAttempt::query()
                ->whereKey($completion->passed_attempt_id)
                ->where('user_id', $student->id)
                ->first(['final_score']);
            $previousBps = $previousAttempt?->final_score['overall']['percentBps'] ?? null;
            $replace = ! is_numeric($previousBps)
                || (int) ($attempt->final_score['overall']['percentBps'] ?? -1) >= (int) $previousBps;
        } else {
            $completion = new LessonAssessmentCompletion;
        }
        $completion->forceFill([
            'user_id' => $student->id,
            'lesson_id' => $lessonId,
            'lesson_assessment_id' => $replace ? $attempt->lesson_assessment_id : $completion->lesson_assessment_id,
            'passed_attempt_id' => $replace ? $attempt->id : $completion->passed_attempt_id,
            'completed_at' => $completion->completed_at ?? $finalizedAt,
        ])->save();
    }

    private function assertPermission(User $actor, string $permission): void
    {
        if (! Rbac::hasPermission($actor, $permission)) {
            throw $this->forbidden();
        }
    }

    private function assertScope(User $actor, User $student): void
    {
        if (! Rbac::canReviewAssessmentUserScope($actor, $student)) {
            throw $this->forbidden();
        }
    }

    private function normalizeComment(?string $comment): ?string
    {
        if ($comment === null || trim($comment) === '') {
            return null;
        }

        return $comment;
    }

    /** @param array<string, mixed> $payload */
    private function fingerprint(User $actor, int $attemptId, string $action, array $payload): string
    {
        $value = [
            'actorId' => (int) $actor->id,
            'attemptId' => $attemptId,
            'action' => $action,
            'payload' => $payload,
        ];

        return hash('sha256', json_encode(
            $this->canonicalize($value),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }
        ksort($value, SORT_STRING);

        return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
    }

    private function notFound(): LessonAssessmentException
    {
        return new LessonAssessmentException(
            LessonAssessmentException::REVIEW_NOT_FOUND,
            'The assessment review was not found.',
            Response::HTTP_NOT_FOUND,
        );
    }

    private function forbidden(): LessonAssessmentException
    {
        return new LessonAssessmentException(
            LessonAssessmentException::REVIEW_FORBIDDEN,
            'The assessment review is forbidden.',
            Response::HTTP_FORBIDDEN,
        );
    }

    private function conflict(): LessonAssessmentException
    {
        return new LessonAssessmentException(
            LessonAssessmentException::REVIEW_CONFLICT,
            'The assessment review has changed.',
            Response::HTTP_CONFLICT,
        );
    }

    private function incomplete(): LessonAssessmentException
    {
        return new LessonAssessmentException(
            LessonAssessmentException::REVIEW_INCOMPLETE,
            'Every manual review item must be decided before finalization.',
            Response::HTTP_CONFLICT,
        );
    }
}
