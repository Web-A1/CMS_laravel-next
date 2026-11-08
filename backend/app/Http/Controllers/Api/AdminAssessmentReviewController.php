<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\LessonAssessmentException;
use App\Http\Controllers\Controller;
use App\Http\Requests\FinalizeLessonAssessmentManualReviewRequest;
use App\Http\Requests\UpdateLessonAssessmentManualReviewRequest;
use App\Models\LessonAssessmentAttempt;
use App\Models\User;
use App\Services\Learning\LessonAssessmentManualReviewService;
use App\Support\Rbac;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class AdminAssessmentReviewController extends Controller
{
    public function index(Request $request, LessonAssessmentManualReviewService $reviews): JsonResponse
    {
        $actor = $this->actor($request);
        $this->assertReviewer($actor, 'assessment-reviews.read');
        $page = filter_var($request->query('page', 1), FILTER_VALIDATE_INT) ?: 1;
        $perPage = filter_var($request->query('perPage', 20), FILTER_VALIDATE_INT) ?: 20;
        $filters = $request->validate([
            'status' => ['sometimes', 'string', 'in:pending_review,finalized,all'],
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        try {
            $paginator = $reviews->list($actor, $perPage, $page, $filters['status'] ?? 'pending_review', trim($filters['search'] ?? ''));
        } catch (LessonAssessmentException $exception) {
            return $this->domainError($exception);
        }

        return $this->privateResponse([
            'data' => collect($paginator->items())
                ->map(function (LessonAssessmentAttempt $attempt) use ($actor, $reviews): array {
                    if (! Rbac::canReviewAssessmentUserScope($actor, $attempt->user)) {
                        abort(Response::HTTP_FORBIDDEN, 'Forbidden by assessment review scope.');
                    }

                    return $reviews->summaryPayload($attempt);
                })
                ->all(),
            'meta' => [
                'currentPage' => $paginator->currentPage(),
                'lastPage' => $paginator->lastPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function show(Request $request, string $attempt, LessonAssessmentManualReviewService $reviews): JsonResponse
    {
        $actor = $this->actor($request);
        $record = $this->scopedAttempt($actor, $attempt, 'assessment-reviews.read');
        if (! $record instanceof LessonAssessmentAttempt) {
            return $this->notFound();
        }

        try {
            return $this->privateResponse(['data' => $reviews->detail($actor, (int) $record->id)]);
        } catch (LessonAssessmentException $exception) {
            return $this->domainError($exception);
        }
    }

    public function update(
        UpdateLessonAssessmentManualReviewRequest $request,
        string $attempt,
        string $review,
        LessonAssessmentManualReviewService $reviews,
    ): JsonResponse {
        $actor = $this->actor($request);
        $record = $this->scopedAttempt($actor, $attempt, 'assessment-reviews.review');
        if (! $record instanceof LessonAssessmentAttempt || ! $this->positiveInteger($review)) {
            return $this->notFound();
        }
        $validated = $request->validated();

        try {
            return $this->privateResponse(['data' => $reviews->update(
                $actor,
                (int) $record->id,
                (int) $review,
                $validated['revision'],
                $validated['scoreBps'],
                $validated['comment'] ?? null,
                (string) $request->idempotencyKey(),
            )]);
        } catch (LessonAssessmentException $exception) {
            return $this->domainError($exception);
        }
    }

    public function finalize(
        FinalizeLessonAssessmentManualReviewRequest $request,
        string $attempt,
        LessonAssessmentManualReviewService $reviews,
    ): JsonResponse {
        $actor = $this->actor($request);
        $record = $this->scopedAttempt($actor, $attempt, 'assessment-reviews.review');
        if (! $record instanceof LessonAssessmentAttempt) {
            return $this->notFound();
        }

        try {
            return $this->privateResponse(['data' => $reviews->finalize(
                $actor,
                (int) $record->id,
                $request->validated('reviewRevision'),
                (string) $request->idempotencyKey(),
            )]);
        } catch (LessonAssessmentException $exception) {
            return $this->domainError($exception);
        }
    }

    private function scopedAttempt(User $actor, string $attempt, string $permission): ?LessonAssessmentAttempt
    {
        $this->assertReviewer($actor, $permission);
        if (! $this->positiveInteger($attempt)) {
            return null;
        }
        $record = LessonAssessmentAttempt::query()->with('user')->find((int) $attempt);
        if (! $record instanceof LessonAssessmentAttempt) {
            return null;
        }
        if (! Rbac::canReviewAssessmentUserScope($actor, $record->user)) {
            abort(Response::HTTP_FORBIDDEN, 'Forbidden by assessment review scope.');
        }

        return $record;
    }

    private function assertReviewer(User $actor, string $permission): void
    {
        if (! Rbac::hasPermission($actor, $permission) || ! in_array($actor->role, ['admin_full', 'manager', 'teacher'], true)) {
            abort(Response::HTTP_FORBIDDEN, 'Forbidden.');
        }
    }

    private function positiveInteger(string $value): bool
    {
        return preg_match('/\A[1-9][0-9]*\z/', $value) === 1;
    }

    private function actor(Request $request): User
    {
        /** @var User $actor */
        $actor = $request->user();

        return $actor;
    }

    private function domainError(LessonAssessmentException $exception): JsonResponse
    {
        return $this->privateResponse([
            'error' => [
                'code' => $exception->errorCode,
                'message' => match ($exception->errorCode) {
                    LessonAssessmentException::REVIEW_NOT_FOUND => 'Проверка экзамена не найдена.',
                    LessonAssessmentException::REVIEW_FORBIDDEN => 'Нет доступа к проверке этого экзамена.',
                    LessonAssessmentException::REVIEW_INCOMPLETE => 'Сначала оцените все ответы ручной части.',
                    default => 'Состояние проверки изменилось. Обновите данные и повторите действие.',
                },
            ],
        ], $exception->httpStatus);
    }

    private function notFound(): JsonResponse
    {
        return $this->privateResponse([
            'error' => [
                'code' => LessonAssessmentException::REVIEW_NOT_FOUND,
                'message' => 'Проверка экзамена не найдена.',
            ],
        ], Response::HTTP_NOT_FOUND);
    }

    /** @param array<string, mixed> $payload */
    private function privateResponse(array $payload, int $status = Response::HTTP_OK): JsonResponse
    {
        return response()->json($payload, $status)->header('Cache-Control', 'private, no-store');
    }
}
