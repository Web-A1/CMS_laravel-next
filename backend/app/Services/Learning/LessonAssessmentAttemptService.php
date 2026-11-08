<?php

namespace App\Services\Learning;

use App\Exceptions\LessonAssessmentException;
use App\Models\LessonAssessment;
use App\Models\LessonAssessmentAttempt;
use App\Models\LessonAssessmentAttemptResponse;
use App\Models\LessonAssessmentCompletion;
use App\Models\LessonAssessmentManualReview;
use App\Models\LessonAssessmentVersion;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class LessonAssessmentAttemptService
{
    public function __construct(private readonly LessonAssessmentScoringService $scoring) {}

    /** @return array{attempt: LessonAssessmentAttempt, created: bool} */
    public function start(User $user, LessonAssessment $assessment, bool $retake = false): array
    {
        try {
            return DB::transaction(function () use ($user, $assessment, $retake): array {
                $lockedAssessment = LessonAssessment::query()
                    ->lockForUpdate()
                    ->find($assessment->getKey());
                if (! $lockedAssessment instanceof LessonAssessment) {
                    throw $this->assessmentNotFound();
                }

                $existing = $this->existingAttempt($user, $lockedAssessment, $retake);
                if ($existing instanceof LessonAssessmentAttempt) {
                    return ['attempt' => $existing, 'created' => false];
                }

                $version = LessonAssessmentVersion::query()
                    ->where('lesson_assessment_id', $lockedAssessment->id)
                    ->where('status', LessonAssessmentVersion::STATUS_PUBLISHED)
                    ->lockForUpdate()
                    ->first();
                if (! $version instanceof LessonAssessmentVersion) {
                    throw $this->assessmentNotFound();
                }

                $attempt = LessonAssessmentAttempt::query()->forceCreate([
                    'user_id' => $user->id,
                    'lesson_assessment_id' => $lockedAssessment->id,
                    'lesson_assessment_version_id' => $version->id,
                    'status' => LessonAssessmentAttempt::STATUS_IN_PROGRESS,
                    'correct_count' => 0,
                    'revision' => 0,
                    'check_count' => 0,
                ]);

                return ['attempt' => $attempt, 'created' => true];
            });
        } catch (QueryException $exception) {
            $existing = $this->existingAttempt($user, $assessment, $retake);
            if ($this->isUniqueViolation($exception) && $existing instanceof LessonAssessmentAttempt) {
                return ['attempt' => $existing, 'created' => false];
            }

            throw $exception;
        }
    }

    public function resetDevelopmentState(User $user, LessonAssessment $assessment): bool
    {
        if (! $this->developmentToolsAllowed($user)) {
            return false;
        }

        DB::transaction(function () use ($user, $assessment): void {
            $lockedAssessment = LessonAssessment::query()
                ->lockForUpdate()
                ->find($assessment->getKey());
            if (! $lockedAssessment instanceof LessonAssessment) {
                throw $this->assessmentNotFound();
            }

            $attempts = LessonAssessmentAttempt::query()
                ->where('user_id', $user->id)
                ->where('lesson_assessment_id', $lockedAssessment->id)
                ->lockForUpdate()
                ->get();
            LessonAssessmentCompletion::query()
                ->where('user_id', $user->id)
                ->where('lesson_assessment_id', $lockedAssessment->id)
                ->lockForUpdate()
                ->get();

            LessonAssessmentCompletion::query()
                ->where('user_id', $user->id)
                ->where('lesson_assessment_id', $lockedAssessment->id)
                ->delete();

            $attemptIds = $attempts
                ->pluck('id')
                ->map(static fn (int|string $id): int => (int) $id)
                ->all();
            if ($attemptIds === []) {
                return;
            }

            DB::table('lesson_assessment_review_events')
                ->whereIn('lesson_assessment_attempt_id', $attemptIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id']);
            DB::table('lesson_assessment_manual_reviews')
                ->whereIn('lesson_assessment_attempt_id', $attemptIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id']);
            DB::table('lesson_assessment_attempt_responses')
                ->whereIn('lesson_assessment_attempt_id', $attemptIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id']);

            $this->deleteDevelopmentReviewEvents($attemptIds);
            DB::table('lesson_assessment_manual_reviews')
                ->whereIn('lesson_assessment_attempt_id', $attemptIds)
                ->delete();
            DB::table('lesson_assessment_attempt_responses')
                ->whereIn('lesson_assessment_attempt_id', $attemptIds)
                ->delete();
            DB::table('lesson_assessment_attempts')
                ->whereIn('id', $attemptIds)
                ->delete();
        });

        return true;
    }

    /**
     * @param  list<array{questionId: string, response: array<string, mixed>}>  $responses
     * @param  list<string>  $lockQuestionIds
     */
    public function save(
        User $user,
        LessonAssessmentAttempt $attempt,
        int $revision,
        array $responses,
        ?string $lastQuestionId,
        array $lockQuestionIds = [],
    ): LessonAssessmentAttempt {
        return DB::transaction(function () use (
            $user,
            $attempt,
            $revision,
            $responses,
            $lastQuestionId,
            $lockQuestionIds,
        ): LessonAssessmentAttempt {
            [$lockedAttempt, $version] = $this->lockedAttempt($user, $attempt);
            $this->assertMutableRevision($lockedAttempt, $revision);
            if (! array_is_list($responses) || ! array_is_list($lockQuestionIds)) {
                throw $this->invalidResponse();
            }
            $questions = $this->questionMap($version);

            if ($lastQuestionId !== null && ! isset($questions[$lastQuestionId])) {
                throw $this->invalidResponse();
            }

            $lockQuestionIdMap = [];
            foreach ($lockQuestionIds as $questionId) {
                if (! is_string($questionId) || ! isset($questions[$questionId]) || isset($lockQuestionIdMap[$questionId])) {
                    throw $this->invalidResponse();
                }
                $lockQuestionIdMap[$questionId] = true;
            }

            $validatedResponses = [];
            foreach ($responses as $entry) {
                if (! $this->hasExactKeys($entry, ['questionId', 'response'])) {
                    throw $this->invalidResponse();
                }

                $questionId = $entry['questionId'];
                if (! is_string($questionId) || ! isset($questions[$questionId]) || isset($validatedResponses[$questionId])) {
                    throw $this->invalidResponse();
                }

                $storedResponse = LessonAssessmentAttemptResponse::query()
                    ->where('lesson_assessment_attempt_id', $lockedAttempt->id)
                    ->where('question_id', $questionId)
                    ->lockForUpdate()
                    ->first();
                if ($storedResponse?->is_locked === true) {
                    throw new LessonAssessmentException(
                        LessonAssessmentException::LOCKED_RESPONSE,
                        'The assessment response is locked.',
                        409,
                    );
                }

                if (! is_array($entry['response'])) {
                    throw $this->invalidResponse();
                }
                $validatedResponses[$questionId] = [
                    'response' => $entry['response'],
                    'is_complete' => $this->validateResponse($questions[$questionId], $entry['response']),
                ];
            }

            foreach ($validatedResponses as $questionId => $validatedResponse) {
                LessonAssessmentAttemptResponse::query()->updateOrCreate(
                    [
                        'lesson_assessment_attempt_id' => $lockedAttempt->id,
                        'question_id' => $questionId,
                    ],
                    $validatedResponse,
                );
            }

            if ($lockQuestionIdMap !== []) {
                $responsesToLock = LessonAssessmentAttemptResponse::query()
                    ->where('lesson_assessment_attempt_id', $lockedAttempt->id)
                    ->whereIn('question_id', array_keys($lockQuestionIdMap))
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('question_id');

                foreach (array_keys($lockQuestionIdMap) as $questionId) {
                    $storedResponse = $responsesToLock->get($questionId);
                    if (
                        ! $storedResponse instanceof LessonAssessmentAttemptResponse ||
                        ! $storedResponse->is_complete
                    ) {
                        throw new LessonAssessmentException(
                            LessonAssessmentException::INCOMPLETE,
                            'The assessment has incomplete responses.',
                            422,
                        );
                    }

                    if (! $storedResponse->is_locked) {
                        $storedResponse->is_locked = true;
                        $storedResponse->revision++;
                        $storedResponse->save();
                    }
                }
            }

            if (($validatedResponses !== [] || $lockQuestionIdMap !== []) && $lockedAttempt->checked_at !== null) {
                $lockedAttempt->checked_at = null;
            }
            $lockedAttempt->last_question_id = $lastQuestionId;
            $lockedAttempt->revision = $revision + 1;
            $lockedAttempt->save();

            return $lockedAttempt->refresh();
        });
    }

    /** @return array{attempt: LessonAssessmentAttempt, automaticGate: array<string, mixed>} */
    public function submit(User $user, LessonAssessmentAttempt $attempt, int $revision): array
    {
        return DB::transaction(function () use ($user, $attempt, $revision): array {
            $attemptSnapshot = LessonAssessmentAttempt::query()->find($attempt->getKey());
            if (! $attemptSnapshot instanceof LessonAssessmentAttempt || $attemptSnapshot->user_id !== $user->id) {
                throw $this->attemptNotFound();
            }

            $lockedAssessment = LessonAssessment::query()
                ->lockForUpdate()
                ->find($attemptSnapshot->lesson_assessment_id);
            if (! $lockedAssessment instanceof LessonAssessment) {
                throw $this->assessmentNotFound();
            }
            [$lockedAttempt, $version] = $this->lockedAttempt($user, $attempt);
            if ($lockedAttempt->revision !== $revision) {
                throw $this->attemptConflict();
            }

            $questions = $this->questionMap($version);
            if ($lockedAttempt->status !== LessonAssessmentAttempt::STATUS_IN_PROGRESS) {
                throw $this->attemptConflict();
            }

            $storedResponses = LessonAssessmentAttemptResponse::query()
                ->where('lesson_assessment_attempt_id', $lockedAttempt->id)
                ->lockForUpdate()
                ->get()
                ->keyBy('question_id');
            $automaticQuestions = [];
            $manualQuestions = [];
            $automaticResponses = [];
            $automaticAnswerKey = [];
            foreach ($questions as $questionId => $question) {
                $stored = $storedResponses->get($questionId);
                if (
                    ! $stored instanceof LessonAssessmentAttemptResponse
                    || ! is_array($stored->response)
                    || ! $stored->is_complete
                ) {
                    throw new LessonAssessmentException(
                        LessonAssessmentException::INCOMPLETE,
                        'The assessment has incomplete responses.',
                        422,
                    );
                }
                $gradingMode = $question['gradingMode'] ?? null;
                if ($gradingMode === 'automatic') {
                    $automaticQuestions[] = $question;
                    $automaticResponses[$questionId] = $stored->response;
                } elseif ($gradingMode === 'manual') {
                    $manualQuestions[] = $question;
                } else {
                    throw $this->attemptConflict();
                }
            }
            if ($storedResponses->count() !== count($questions)) {
                throw $this->attemptConflict();
            }

            $answerKey = $version->answer_key;
            $sections = $version->sections;
            if (! is_array($answerKey) || ! is_array($sections) || ! array_is_list($sections)) {
                throw $this->attemptConflict();
            }

            if ($automaticQuestions === [] || $manualQuestions === []) {
                throw $this->attemptConflict();
            }
            foreach ($automaticQuestions as $question) {
                $questionId = $question['id'];
                $keyEntry = $answerKey[$questionId] ?? null;
                if (! is_array($keyEntry)) {
                    throw $this->attemptConflict();
                }
                $automaticAnswerKey[$questionId] = $keyEntry;
            }

            $graded = $this->grade(
                $sections,
                $automaticQuestions,
                $automaticAnswerKey,
                $automaticResponses,
            );
            $submittedAt = now();
            $automaticQuestionIds = array_fill_keys(array_column($automaticQuestions, 'id'), true);
            foreach ($storedResponses as $questionId => $stored) {
                $stored->is_locked = true;
                $stored->revision++;
                $stored->checked_at = isset($automaticQuestionIds[$questionId]) ? $submittedAt : null;
                $stored->save();
            }

            $automaticSnapshot = [
                ...$graded['score'],
                'correctCount' => $graded['correctCount'],
                'questionResults' => $graded['questionResults'],
            ];
            $passed = $automaticSnapshot['passed'] === true;
            $lockedAttempt->correct_count = $graded['correctCount'];
            $lockedAttempt->check_count++;
            $lockedAttempt->revision++;
            $lockedAttempt->checked_at = $submittedAt;
            $lockedAttempt->submitted_at = $submittedAt;
            $lockedAttempt->automatic_score = $automaticSnapshot;
            $lockedAttempt->manual_score = null;
            $lockedAttempt->final_score = null;
            $lockedAttempt->finalized_at = null;
            $lockedAttempt->passed_at = null;

            if ($passed) {
                $lockedAttempt->status = LessonAssessmentAttempt::STATUS_PENDING_REVIEW;
                $lockedAttempt->failure_reason = null;
            } else {
                $lockedAttempt->status = LessonAssessmentAttempt::STATUS_FAILED;
                $lockedAttempt->failure_reason = LessonAssessmentAttempt::FAILURE_AUTOMATIC_THRESHOLD;
            }

            $lockedAttempt->save();
            if ($passed) {
                foreach ($manualQuestions as $question) {
                    $questionId = $question['id'];
                    /** @var LessonAssessmentAttemptResponse $stored */
                    $stored = $storedResponses->get($questionId);
                    $keyResponse = $answerKey[$questionId]['response'] ?? null;
                    if (! is_array($keyResponse) || ! is_array($stored->response)) {
                        throw $this->attemptConflict();
                    }
                    $responseSnapshot = $stored->response;
                    $rubricSnapshot = ['expectedAnswer' => $keyResponse];
                    LessonAssessmentManualReview::query()->create([
                        'lesson_assessment_attempt_id' => $lockedAttempt->id,
                        'lesson_assessment_attempt_response_id' => $stored->id,
                        'question_id' => $questionId,
                        'status' => LessonAssessmentManualReview::STATUS_PENDING,
                        'response_snapshot' => $responseSnapshot,
                        'response_snapshot_sha256' => $this->snapshotHash($responseSnapshot),
                        'rubric_snapshot' => $rubricSnapshot,
                        'rubric_snapshot_sha256' => $this->snapshotHash($rubricSnapshot),
                        'revision' => 0,
                    ]);
                }
            }

            return [
                'attempt' => $lockedAttempt->refresh(),
                'automaticGate' => $automaticSnapshot,
            ];
        });
    }

    /**
     * @param  list<array<string, mixed>>  $sections
     * @param  list<array<string, mixed>>  $questions
     * @param  array<string, mixed>  $answerKey
     * @param  array<string, array<string, mixed>>  $responses
     * @return array<string, mixed>
     */
    private function grade(array $sections, array $questions, array $answerKey, array $responses): array
    {
        try {
            return $this->scoring->grade($sections, $questions, $answerKey, $responses);
        } catch (\RuntimeException) {
            throw $this->attemptConflict();
        }
    }

    private function existingAttempt(
        User $user,
        LessonAssessment $assessment,
        bool $retake,
    ): ?LessonAssessmentAttempt {
        $active = LessonAssessmentAttempt::query()
            ->where('user_id', $user->id)
            ->where('lesson_assessment_id', $assessment->id)
            ->whereIn('status', [
                LessonAssessmentAttempt::STATUS_IN_PROGRESS,
                LessonAssessmentAttempt::STATUS_PENDING_REVIEW,
            ])
            ->orderBy('id')
            ->first();
        if ($active instanceof LessonAssessmentAttempt) {
            return $active;
        }

        if ($retake) {
            return null;
        }

        return LessonAssessmentAttempt::query()
            ->where('user_id', $user->id)
            ->where('lesson_assessment_id', $assessment->id)
            ->whereIn('status', [
                LessonAssessmentAttempt::STATUS_FAILED,
                LessonAssessmentAttempt::STATUS_PASSED,
            ])
            ->orderByDesc('id')
            ->first();
    }

    public function developmentToolsAllowed(User $user): bool
    {
        if (! app()->environment(['local', 'testing'])) {
            return false;
        }

        $profiles = config('local_development.dev_profiles.profiles', []);
        if (! is_array($profiles)) {
            return false;
        }

        foreach ($profiles as $profile) {
            if (is_array($profile) && ($profile['email'] ?? null) === $user->email) {
                return $this->developmentDatabaseAllowed();
            }
        }

        return false;
    }

    /** @param list<int> $attemptIds */
    private function deleteDevelopmentReviewEvents(array $attemptIds): void
    {
        $events = DB::table('lesson_assessment_review_events')
            ->whereIn('lesson_assessment_attempt_id', $attemptIds);
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $events->delete();

            return;
        }

        // PostgreSQL DDL is transactional. ALTER TABLE also holds an exclusive lock,
        // so no concurrent transaction can write while this local-only reset deletes events.
        DB::statement(
            'ALTER TABLE lesson_assessment_review_events '
            .'DISABLE TRIGGER lesson_assessment_review_events_append_only_trigger',
        );
        $events->delete();
        DB::statement(
            'ALTER TABLE lesson_assessment_review_events '
            .'ENABLE TRIGGER lesson_assessment_review_events_append_only_trigger',
        );
    }

    private function developmentDatabaseAllowed(): bool
    {
        $connectionName = DB::getDefaultConnection();
        $connection = config("database.connections.{$connectionName}");
        if (! is_array($connection)) {
            return false;
        }

        $driver = (string) ($connection['driver'] ?? '');
        $database = (string) ($connection['database'] ?? '');
        if ($driver === 'sqlite' && $database === ':memory:') {
            return true;
        }

        $url = $connection['url'] ?? null;
        $host = $connection['host'] ?? null;
        $allowedDatabase = (string) config('local_development.dev_profiles.database.database');
        $allowedHosts = config('local_development.dev_profiles.database.hosts', []);

        return $driver === 'pgsql'
            && (! is_string($url) || trim($url) === '')
            && is_string($host)
            && is_array($allowedHosts)
            && in_array($host, $allowedHosts, true)
            && $database === $allowedDatabase;
    }

    /** @return array{LessonAssessmentAttempt, LessonAssessmentVersion} */
    private function lockedAttempt(User $user, LessonAssessmentAttempt $attempt): array
    {
        $lockedAttempt = LessonAssessmentAttempt::query()
            ->lockForUpdate()
            ->find($attempt->getKey());
        if (! $lockedAttempt instanceof LessonAssessmentAttempt || $lockedAttempt->user_id !== $user->id) {
            throw $this->attemptNotFound();
        }

        $version = LessonAssessmentVersion::query()->find($lockedAttempt->lesson_assessment_version_id);
        if (
            ! $version instanceof LessonAssessmentVersion
            || $version->lesson_assessment_id !== $lockedAttempt->lesson_assessment_id
        ) {
            throw $this->attemptConflict();
        }

        return [$lockedAttempt, $version];
    }

    private function assertMutableRevision(LessonAssessmentAttempt $attempt, int $revision): void
    {
        if ($attempt->status !== LessonAssessmentAttempt::STATUS_IN_PROGRESS || $attempt->revision !== $revision) {
            throw $this->attemptConflict();
        }
    }

    /**
     * @param  array<string, mixed>  $question
     * @param  array<string, mixed>  $response
     */
    private function validateResponse(array $question, array $response): bool
    {
        $type = $question['type'] ?? null;
        $optionIds = in_array($type, [
            'single_choice',
            'multiple_choice',
            'ordered_choice',
            'slot_choice',
            'diagram_labels',
        ], true) ? $this->optionIds($question) : [];

        if ($type === 'single_choice') {
            if (! $this->hasExactKeys($response, ['selectedOptionId'])) {
                throw $this->invalidResponse();
            }
            if ($response['selectedOptionId'] === null) {
                return false;
            }
            if (! is_string($response['selectedOptionId']) || ! isset($optionIds[$response['selectedOptionId']])) {
                throw $this->invalidResponse();
            }

            return true;
        }

        if (in_array($type, ['multiple_choice', 'ordered_choice'], true)) {
            if (! $this->hasExactKeys($response, ['selectedOptionIds'])) {
                throw $this->invalidResponse();
            }
            $selected = $response['selectedOptionIds'];
            if (! is_array($selected) || ! array_is_list($selected)) {
                throw $this->invalidResponse();
            }
            $seen = [];
            foreach ($selected as $optionId) {
                if (! is_string($optionId) || ! isset($optionIds[$optionId]) || isset($seen[$optionId])) {
                    throw $this->invalidResponse();
                }
                $seen[$optionId] = true;
            }

            return $type === 'ordered_choice'
                ? count($selected) === count($optionIds)
                : $selected !== [];
        }

        if (in_array($type, ['slot_choice', 'diagram_labels'], true)) {
            if (! $this->hasExactKeys($response, ['selections']) || ! is_array($response['selections'])) {
                throw $this->invalidResponse();
            }
            $parts = $this->partMap($question);
            foreach ($response['selections'] as $partId => $optionId) {
                if (
                    ! is_string($partId)
                    || ! isset($parts[$partId])
                    || ! is_string($optionId)
                    || ! isset($parts[$partId][$optionId])
                ) {
                    throw $this->invalidResponse();
                }
            }

            return count($response['selections']) === count($parts) && $parts !== [];
        }

        if (in_array($type, ['text_fields', 'diagram_text'], true)) {
            if (! $this->hasExactKeys($response, ['texts']) || ! is_array($response['texts']) || array_is_list($response['texts'])) {
                throw $this->invalidResponse();
            }
            $partIds = $this->textPartIds($question);
            $complete = $partIds !== [] && count($response['texts']) === count($partIds);
            foreach ($response['texts'] as $partId => $text) {
                if (
                    ! is_string($partId)
                    || ! isset($partIds[$partId])
                    || ! is_string($text)
                    || mb_strlen($text, 'UTF-8') > 500
                ) {
                    throw $this->invalidResponse();
                }
                $complete = $complete && trim($text) !== '';
            }

            return $complete;
        }

        if ($type === 'long_text') {
            if (
                ! $this->hasExactKeys($response, ['text'])
                || ! is_string($response['text'])
                || mb_strlen($response['text'], 'UTF-8') > 10000
            ) {
                throw $this->invalidResponse();
            }

            return trim($response['text']) !== '';
        }

        throw $this->attemptConflict();
    }

    /** @return array<string, array<string, mixed>> */
    private function questionMap(LessonAssessmentVersion $version): array
    {
        $questions = $version->questions;
        if (! is_array($questions) || ! array_is_list($questions)) {
            throw $this->attemptConflict();
        }

        $mapped = [];
        foreach ($questions as $question) {
            $questionId = is_array($question) ? ($question['id'] ?? null) : null;
            if (! is_string($questionId) || isset($mapped[$questionId])) {
                throw $this->attemptConflict();
            }
            $mapped[$questionId] = $question;
        }

        return $mapped;
    }

    /** @param array<string, mixed> $question
     * @return array<string, true>
     */
    private function optionIds(array $question): array
    {
        if (! is_array($question['options'] ?? null) || ! array_is_list($question['options'])) {
            throw $this->attemptConflict();
        }

        $optionIds = [];
        foreach ($question['options'] as $option) {
            $optionId = is_array($option) ? ($option['id'] ?? null) : null;
            if (! is_string($optionId) || isset($optionIds[$optionId])) {
                throw $this->attemptConflict();
            }
            $optionIds[$optionId] = true;
        }

        return $optionIds;
    }

    /** @param array<string, mixed> $question
     * @return array<string, array<string, true>>
     */
    private function partMap(array $question): array
    {
        if (! is_array($question['parts'] ?? null) || ! array_is_list($question['parts'])) {
            throw $this->attemptConflict();
        }

        $parts = [];
        foreach ($question['parts'] as $part) {
            if (! is_array($part) || ! is_string($part['id'] ?? null) || ! is_array($part['optionIds'] ?? null)) {
                throw $this->attemptConflict();
            }
            $partId = $part['id'];
            if (isset($parts[$partId])) {
                throw $this->attemptConflict();
            }
            $parts[$partId] = [];
            foreach ($part['optionIds'] as $optionId) {
                if (! is_string($optionId) || isset($parts[$partId][$optionId])) {
                    throw $this->attemptConflict();
                }
                $parts[$partId][$optionId] = true;
            }
        }

        return $parts;
    }

    /** @param array<string, mixed> $question
     * @return array<string, true>
     */
    private function textPartIds(array $question): array
    {
        if (! is_array($question['parts'] ?? null) || ! array_is_list($question['parts'])) {
            throw $this->attemptConflict();
        }

        $partIds = [];
        foreach ($question['parts'] as $part) {
            $partId = is_array($part) ? ($part['id'] ?? null) : null;
            if (! is_string($partId) || isset($partIds[$partId])) {
                throw $this->attemptConflict();
            }
            $partIds[$partId] = true;
        }

        return $partIds;
    }

    /** @param list<string> $expectedKeys */
    private function hasExactKeys(mixed $value, array $expectedKeys): bool
    {
        if (! is_array($value)) {
            return false;
        }

        $actualKeys = array_keys($value);
        sort($actualKeys, SORT_STRING);
        sort($expectedKeys, SORT_STRING);

        return $actualKeys === $expectedKeys;
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        return in_array((string) $exception->getCode(), ['23000', '23505'], true);
    }

    private function assessmentNotFound(): LessonAssessmentException
    {
        return new LessonAssessmentException(
            LessonAssessmentException::ASSESSMENT_NOT_FOUND,
            'The assessment was not found.',
            404,
        );
    }

    private function attemptNotFound(): LessonAssessmentException
    {
        return new LessonAssessmentException(
            LessonAssessmentException::ATTEMPT_NOT_FOUND,
            'The assessment attempt was not found.',
            404,
        );
    }

    private function attemptConflict(): LessonAssessmentException
    {
        return new LessonAssessmentException(
            LessonAssessmentException::ATTEMPT_CONFLICT,
            'The assessment attempt has changed.',
            409,
        );
    }

    private function invalidResponse(): LessonAssessmentException
    {
        return new LessonAssessmentException(
            LessonAssessmentException::INVALID_RESPONSE,
            'The assessment response is invalid.',
            422,
        );
    }

    /** @param array<string, mixed> $snapshot */
    private function snapshotHash(array $snapshot): string
    {
        try {
            return hash('sha256', json_encode(
                $this->canonicalize($snapshot),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));
        } catch (\JsonException) {
            throw $this->attemptConflict();
        }
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
}
