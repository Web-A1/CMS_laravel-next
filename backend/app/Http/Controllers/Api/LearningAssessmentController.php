<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\LessonAssessmentException;
use App\Http\Controllers\Controller;
use App\Http\Requests\SaveLessonAssessmentAttemptRequest;
use App\Http\Requests\ShowLessonAssessmentCalibrationKeyRequest;
use App\Http\Requests\StartLessonAssessmentAttemptRequest;
use App\Http\Requests\SubmitLessonAssessmentAttemptRequest;
use App\Http\Requests\SuggestLessonAssessmentAnswerRequest;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\LessonAssessment;
use App\Models\LessonAssessmentAttempt;
use App\Models\LessonAssessmentVersion;
use App\Models\Subject;
use App\Models\User;
use App\Services\AccessResolver;
use App\Services\Learning\LessonAssessmentAttemptService;
use App\Services\Learning\LessonAssessmentPresenter;
use App\Services\Learning\LessonAssessmentStateSelector;
use App\Services\Learning\LessonAssessmentSuggestionService;
use App\Support\ImpersonationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LearningAssessmentController extends Controller
{
    public function show(
        Request $request,
        Subject $subject,
        Course $course,
        Lesson $lesson,
        AccessResolver $accessResolver,
        LessonAssessmentPresenter $presenter,
        LessonAssessmentStateSelector $stateSelector,
    ): JsonResponse {
        if ($this->lessonIsUnavailable($subject, $course, $lesson)) {
            return $this->lessonNotFound();
        }
        if (! $this->hasAccess($request, $lesson, $accessResolver)) {
            return $this->accessDenied();
        }

        $assessment = $this->assessment($lesson);
        if (! $assessment instanceof LessonAssessment) {
            return $this->assessmentNotFound();
        }
        $state = $stateSelector->select($this->user($request), $assessment);
        if ($state === null) {
            return $this->assessmentNotFound();
        }

        return $this->privateResponse($presenter->present(
            $subject,
            $course,
            $lesson,
            $assessment,
            $state['version'],
            $state['attempt'],
        ));
    }

    public function store(
        StartLessonAssessmentAttemptRequest $request,
        Subject $subject,
        Course $course,
        Lesson $lesson,
        AccessResolver $accessResolver,
        LessonAssessmentAttemptService $attempts,
        LessonAssessmentPresenter $presenter,
    ): JsonResponse {
        if ($this->lessonIsUnavailable($subject, $course, $lesson)) {
            return $this->lessonNotFound();
        }
        if (! $this->hasAccess($request, $lesson, $accessResolver)) {
            return $this->accessDenied();
        }

        $assessment = $this->assessment($lesson);
        if (! $assessment instanceof LessonAssessment) {
            return $this->assessmentNotFound();
        }

        try {
            $result = $attempts->start(
                $this->user($request),
                $assessment,
                $request->validated('retake', false) === true,
            );
        } catch (LessonAssessmentException $exception) {
            return $this->domainError($exception);
        }

        $attempt = $result['attempt'];
        $version = $this->attemptVersion($attempt, $assessment);
        if (! $version instanceof LessonAssessmentVersion) {
            return $this->domainError(new LessonAssessmentException(
                LessonAssessmentException::ATTEMPT_CONFLICT,
                'The assessment attempt has changed.',
                Response::HTTP_CONFLICT,
            ));
        }

        return $this->privateResponse(
            $presenter->present($subject, $course, $lesson, $assessment, $version, $attempt),
            $result['created'] ? Response::HTTP_CREATED : Response::HTTP_OK,
        );
    }

    public function update(
        SaveLessonAssessmentAttemptRequest $request,
        Subject $subject,
        Course $course,
        Lesson $lesson,
        string $attempt,
        AccessResolver $accessResolver,
        LessonAssessmentAttemptService $attempts,
        LessonAssessmentPresenter $presenter,
    ): JsonResponse {
        if ($this->lessonIsUnavailable($subject, $course, $lesson)) {
            return $this->lessonNotFound();
        }
        if (! $this->hasAccess($request, $lesson, $accessResolver)) {
            return $this->accessDenied();
        }

        $assessment = $this->assessment($lesson);
        if (! $assessment instanceof LessonAssessment) {
            return $this->assessmentNotFound();
        }
        $record = $this->attempt($this->user($request), $assessment, $attempt);
        if (! $record instanceof LessonAssessmentAttempt) {
            return $this->attemptNotFound();
        }

        $validated = $request->validated();
        try {
            $saved = $attempts->save(
                $this->user($request),
                $record,
                $validated['revision'],
                $validated['responses'],
                $validated['lastQuestionId'],
                $validated['lockQuestionIds'] ?? [],
            );
        } catch (LessonAssessmentException $exception) {
            return $this->domainError($exception);
        }

        $version = $this->attemptVersion($saved, $assessment);
        if (! $version instanceof LessonAssessmentVersion) {
            return $this->attemptNotFound();
        }

        return $this->privateResponse(
            $presenter->present($subject, $course, $lesson, $assessment, $version, $saved),
        );
    }

    public function submit(
        SubmitLessonAssessmentAttemptRequest $request,
        Subject $subject,
        Course $course,
        Lesson $lesson,
        string $attempt,
        AccessResolver $accessResolver,
        LessonAssessmentAttemptService $attempts,
        LessonAssessmentPresenter $presenter,
    ): JsonResponse {
        if ($this->lessonIsUnavailable($subject, $course, $lesson)) {
            return $this->lessonNotFound();
        }
        if (! $this->hasAccess($request, $lesson, $accessResolver)) {
            return $this->accessDenied();
        }

        $assessment = $this->assessment($lesson);
        if (! $assessment instanceof LessonAssessment) {
            return $this->assessmentNotFound();
        }
        $record = $this->attempt($this->user($request), $assessment, $attempt);
        if (! $record instanceof LessonAssessmentAttempt) {
            return $this->attemptNotFound();
        }

        try {
            $result = $attempts->submit(
                $this->user($request),
                $record,
                $request->validated('revision'),
            );
        } catch (LessonAssessmentException $exception) {
            return $this->domainError($exception);
        }

        $version = $this->attemptVersion($result['attempt'], $assessment);
        if (! $version instanceof LessonAssessmentVersion) {
            return $this->attemptNotFound();
        }

        $payload = $presenter->present(
            $subject,
            $course,
            $lesson,
            $assessment,
            $version,
            $result['attempt'],
        );
        $payload['data']['submission'] = [
            'number' => (int) $result['attempt']->check_count,
            'automaticGate' => $payload['data']['attempt']['automaticGate'],
        ];

        return $this->privateResponse($payload);
    }

    public function suggest(
        SuggestLessonAssessmentAnswerRequest $request,
        Subject $subject,
        Course $course,
        Lesson $lesson,
        string $attempt,
        AccessResolver $accessResolver,
        LessonAssessmentSuggestionService $suggestions,
    ): JsonResponse {
        if ($this->lessonIsUnavailable($subject, $course, $lesson)) {
            return $this->lessonNotFound();
        }
        if (! $this->hasAccess($request, $lesson, $accessResolver)) {
            return $this->accessDenied();
        }

        $assessment = $this->assessment($lesson);
        if (! $assessment instanceof LessonAssessment) {
            return $this->assessmentNotFound();
        }
        $record = $this->attempt($this->user($request), $assessment, $attempt);
        if (! $record instanceof LessonAssessmentAttempt) {
            return $this->attemptNotFound();
        }
        $version = $this->attemptVersion($record, $assessment);
        if (! $version instanceof LessonAssessmentVersion) {
            return $this->attemptNotFound();
        }

        try {
            $values = $suggestions->suggest(
                $version,
                $request->validated('questionId'),
                $request->validated('partId'),
                $request->validated('prefix'),
            );
        } catch (LessonAssessmentException $exception) {
            return $this->domainError($exception);
        }

        return $this->privateResponse(['data' => ['suggestions' => $values]]);
    }

    public function resetDevelopmentState(
        Request $request,
        Subject $subject,
        Course $course,
        Lesson $lesson,
        AccessResolver $accessResolver,
        LessonAssessmentAttemptService $attempts,
    ): JsonResponse {
        if ($this->lessonIsUnavailable($subject, $course, $lesson)) {
            return $this->lessonNotFound();
        }
        if (! $this->hasAccess($request, $lesson, $accessResolver)) {
            return $this->accessDenied();
        }

        $assessment = $this->assessment($lesson);
        if (! $assessment instanceof LessonAssessment) {
            return $this->assessmentNotFound();
        }

        try {
            $reset = $attempts->resetDevelopmentState($this->user($request), $assessment);
        } catch (LessonAssessmentException $exception) {
            return $this->domainError($exception);
        }
        if (! $reset) {
            return $this->accessDenied();
        }

        return $this->privateResponse(['data' => ['status' => 'reset']]);
    }

    public function showDevelopmentCalibrationKey(
        ShowLessonAssessmentCalibrationKeyRequest $request,
        Subject $subject,
        Course $course,
        Lesson $lesson,
        AccessResolver $accessResolver,
        LessonAssessmentAttemptService $attempts,
        LessonAssessmentPresenter $presenter,
        LessonAssessmentStateSelector $stateSelector,
    ): JsonResponse {
        if ($this->lessonIsUnavailable($subject, $course, $lesson)) {
            return $this->lessonNotFound();
        }
        if (! $this->hasAccess($request, $lesson, $accessResolver)) {
            return $this->accessDenied();
        }

        $user = $this->user($request);
        if (! $attempts->developmentToolsAllowed($user)) {
            return $this->accessDenied();
        }

        $assessment = $this->assessment($lesson);
        if (! $assessment instanceof LessonAssessment) {
            return $this->assessmentNotFound();
        }
        $state = $stateSelector->select($user, $assessment);
        if ($state === null) {
            return $this->assessmentNotFound();
        }

        $questionId = $request->validated('questionId');
        $terms = $presenter->developmentCalibrationTerms($state['version'], $questionId);
        if ($terms === null) {
            return $this->assessmentNotFound();
        }

        return $this->privateResponse(['data' => [
            'questionId' => $questionId,
            'terms' => $terms,
        ]]);
    }

    private function lessonIsUnavailable(Subject $subject, Course $course, Lesson $lesson): bool
    {
        return ! $subject->is_active
            || ! $course->is_active
            || ! $lesson->is_active
            || (int) $course->subject_id !== (int) $subject->id
            || (int) $lesson->course_id !== (int) $course->id;
    }

    private function hasAccess(Request $request, Lesson $lesson, AccessResolver $accessResolver): bool
    {
        $access = $accessResolver->check(
            $this->user($request),
            AccessResolver::ENTITY_LESSON,
            (int) $lesson->id,
        );

        return $access['access'] === AccessResolver::ACCESS_FULL || (bool) $lesson->is_free_preview;
    }

    private function assessment(Lesson $lesson): ?LessonAssessment
    {
        return LessonAssessment::query()->where('lesson_id', $lesson->id)->first();
    }

    private function attempt(User $user, LessonAssessment $assessment, string $attempt): ?LessonAssessmentAttempt
    {
        if (preg_match('/\A[1-9][0-9]*\z/', $attempt) !== 1) {
            return null;
        }

        return LessonAssessmentAttempt::query()
            ->whereKey((int) $attempt)
            ->where('user_id', $user->id)
            ->where('lesson_assessment_id', $assessment->id)
            ->whereHas('version', fn ($query) => $query
                ->where('lesson_assessment_id', $assessment->id))
            ->first();
    }

    private function attemptVersion(
        LessonAssessmentAttempt $attempt,
        LessonAssessment $assessment,
    ): ?LessonAssessmentVersion {
        return LessonAssessmentVersion::query()
            ->whereKey($attempt->lesson_assessment_version_id)
            ->where('lesson_assessment_id', $assessment->id)
            ->first();
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = ImpersonationContext::effectiveUser($request);

        return $user;
    }

    private function lessonNotFound(): JsonResponse
    {
        return $this->error('LEARNING_LESSON_NOT_FOUND', 'Урок не найден.', Response::HTTP_NOT_FOUND);
    }

    private function accessDenied(): JsonResponse
    {
        return $this->error(
            'LEARNING_ACCESS_DENIED',
            'У пользователя нет доступа к этому уроку.',
            Response::HTTP_FORBIDDEN,
        );
    }

    private function assessmentNotFound(): JsonResponse
    {
        return $this->error(
            LessonAssessmentException::ASSESSMENT_NOT_FOUND,
            'Экзамен не найден.',
            Response::HTTP_NOT_FOUND,
        );
    }

    private function attemptNotFound(): JsonResponse
    {
        return $this->error(
            LessonAssessmentException::ATTEMPT_NOT_FOUND,
            'Попытка сдачи экзамена не найдена.',
            Response::HTTP_NOT_FOUND,
        );
    }

    private function domainError(LessonAssessmentException $exception): JsonResponse
    {
        return $this->error(
            $exception->errorCode,
            match ($exception->errorCode) {
                LessonAssessmentException::ASSESSMENT_NOT_FOUND => 'Экзамен не найден.',
                LessonAssessmentException::ATTEMPT_NOT_FOUND => 'Попытка сдачи экзамена не найдена.',
                LessonAssessmentException::ATTEMPT_CONFLICT => 'Состояние попытки изменилось. Обновите данные и повторите действие.',
                LessonAssessmentException::LOCKED_RESPONSE => 'Уже зачтённый ответ нельзя изменить.',
                LessonAssessmentException::INCOMPLETE => 'Заполните все доступные задания перед проверкой.',
                LessonAssessmentException::INVALID_RESPONSE => 'Ответ на задание имеет неверный формат.',
                LessonAssessmentException::SUGGESTION_FORBIDDEN => 'Подсказки недоступны для заданий с ручной проверкой.',
                default => 'Не удалось обработать экзамен.',
            },
            $exception->httpStatus,
        );
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return $this->privateResponse(['error' => ['code' => $code, 'message' => $message]], $status);
    }

    /** @param array<string, mixed> $payload */
    private function privateResponse(array $payload, int $status = Response::HTTP_OK): JsonResponse
    {
        return response()->json($payload, $status)->header('Cache-Control', 'private, no-store');
    }
}
