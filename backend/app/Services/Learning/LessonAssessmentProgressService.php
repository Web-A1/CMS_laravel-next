<?php

namespace App\Services\Learning;

use App\Models\LessonAssessment;
use App\Models\LessonAssessmentAttempt;
use App\Models\LessonAssessmentAttemptResponse;
use App\Models\LessonAssessmentCompletion;
use App\Models\LessonAssessmentVersion;
use Illuminate\Support\Collection;

class LessonAssessmentProgressService
{
    /**
     * @param  array<int, int>  $lessonIds
     * @return array<int, array{testAvailable:bool,testPassed:bool,testProgress:?array{status:string,answeredCount:int,correctCount:int,totalCount:int,completedAt:?string,retakeAvailable:bool,retakeInProgress:bool}}>
     */
    public function forLessons(int $userId, array $lessonIds): array
    {
        $lessonIds = array_values(array_unique(array_filter(
            array_map('intval', $lessonIds),
            fn (int $lessonId): bool => $lessonId > 0,
        )));
        if ($lessonIds === []) {
            return [];
        }

        $assessmentsByLesson = LessonAssessment::query()
            ->whereIn('lesson_id', $lessonIds)
            ->get(['id', 'lesson_id'])
            ->keyBy('lesson_id');
        $assessmentIds = $assessmentsByLesson->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        if ($assessmentIds === []) {
            return $this->unavailableFor($lessonIds);
        }

        $publishedVersionsByAssessment = LessonAssessmentVersion::query()
            ->whereIn('lesson_assessment_id', $assessmentIds)
            ->where('status', LessonAssessmentVersion::STATUS_PUBLISHED)
            ->get(['id', 'lesson_assessment_id', 'sections', 'questions', 'answer_key'])
            ->keyBy('lesson_assessment_id');

        $completionsByLesson = LessonAssessmentCompletion::query()
            ->where('user_id', $userId)
            ->whereIn('lesson_id', $lessonIds)
            ->get(['lesson_id', 'lesson_assessment_id', 'passed_attempt_id', 'completed_at'])
            ->keyBy('lesson_id');
        $completionAttemptIds = $completionsByLesson
            ->pluck('passed_attempt_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        $completedAttemptsById = $completionAttemptIds === []
            ? collect()
            : LessonAssessmentAttempt::query()
                ->whereIn('id', $completionAttemptIds)
                ->where('user_id', $userId)
                ->where('status', LessonAssessmentAttempt::STATUS_PASSED)
                ->get([
                    'id',
                    'lesson_assessment_id',
                    'lesson_assessment_version_id',
                    'correct_count',
                    'checked_at',
                    'automatic_score',
                    'final_score',
                    'submitted_at',
                    'finalized_at',
                ])
                ->keyBy('id');

        $latestFailedAttemptIds = LessonAssessmentAttempt::query()
            ->selectRaw('MAX(id)')
            ->where('user_id', $userId)
            ->whereIn('lesson_assessment_id', $assessmentIds)
            ->where('status', LessonAssessmentAttempt::STATUS_FAILED)
            ->groupBy('lesson_assessment_id');
        $nonPassedAttempts = LessonAssessmentAttempt::query()
            ->where('user_id', $userId)
            ->whereIn('lesson_assessment_id', $assessmentIds)
            ->where(fn ($query) => $query
                ->whereIn('status', [
                    LessonAssessmentAttempt::STATUS_IN_PROGRESS,
                    LessonAssessmentAttempt::STATUS_PENDING_REVIEW,
                ])
                ->orWhereIn('id', $latestFailedAttemptIds))
            ->orderByDesc('id')
            ->get([
                'id',
                'lesson_assessment_id',
                'lesson_assessment_version_id',
                'status',
                'correct_count',
                'checked_at',
                'automatic_score',
                'final_score',
                'submitted_at',
                'finalized_at',
            ]);
        $activeAttempts = $nonPassedAttempts
            ->whereIn('status', [
                LessonAssessmentAttempt::STATUS_IN_PROGRESS,
                LessonAssessmentAttempt::STATUS_PENDING_REVIEW,
            ])
            ->values();
        $activeAttemptsByAssessment = $activeAttempts
            ->groupBy('lesson_assessment_id')
            ->map(fn ($attempts) => $attempts->first());
        $failedAttempts = $nonPassedAttempts
            ->where('status', LessonAssessmentAttempt::STATUS_FAILED)
            ->values();
        $failedAttemptsByAssessment = $failedAttempts
            ->keyBy('lesson_assessment_id');

        $attempts = $completedAttemptsById
            ->values()
            ->merge($activeAttempts)
            ->merge($failedAttempts)
            ->unique('id')
            ->values();
        $versionsById = $attempts->isEmpty()
            ? collect()
            : LessonAssessmentVersion::query()
                ->whereIn('id', $attempts->pluck('lesson_assessment_version_id')->all())
                ->get(['id', 'lesson_assessment_id', 'sections', 'questions', 'answer_key'])
                ->keyBy('id');
        $responsesByAttempt = $attempts->isEmpty()
            ? collect()
            : LessonAssessmentAttemptResponse::query()
                ->whereIn('lesson_assessment_attempt_id', $attempts->pluck('id')->all())
                ->get(['lesson_assessment_attempt_id', 'question_id', 'response', 'is_complete'])
                ->groupBy('lesson_assessment_attempt_id');
        $answeredCountsByAttempt = $responsesByAttempt->map(
            fn (Collection $responses): int => $responses->where('is_complete', true)->count(),
        );

        $progressByLesson = [];
        foreach ($lessonIds as $lessonId) {
            $assessment = $assessmentsByLesson->get($lessonId);
            if (! $assessment instanceof LessonAssessment) {
                $progressByLesson[$lessonId] = $this->unavailable();

                continue;
            }

            $completion = $completionsByLesson->get($lessonId);
            $publishedVersion = $publishedVersionsByAssessment->get($assessment->id);
            $retakeAvailable = $publishedVersion instanceof LessonAssessmentVersion
                && (int) $publishedVersion->lesson_assessment_id === (int) $assessment->id;
            $activeAttempt = $activeAttemptsByAssessment->get($assessment->id);
            $activeVersion = $activeAttempt instanceof LessonAssessmentAttempt
                ? $versionsById->get($activeAttempt->lesson_assessment_version_id)
                : null;
            $failedAttempt = $failedAttemptsByAssessment->get($assessment->id);
            $failedVersion = $failedAttempt instanceof LessonAssessmentAttempt
                ? $versionsById->get($failedAttempt->lesson_assessment_version_id)
                : null;
            $retakeInProgress = $activeAttempt instanceof LessonAssessmentAttempt
                && $activeVersion instanceof LessonAssessmentVersion
                && (int) $activeVersion->lesson_assessment_id === (int) $assessment->id;
            $completedAttempt = $completion instanceof LessonAssessmentCompletion
                ? $completedAttemptsById->get($completion->passed_attempt_id)
                : null;
            $completedVersion = $completedAttempt instanceof LessonAssessmentAttempt
                ? $versionsById->get($completedAttempt->lesson_assessment_version_id)
                : null;
            if (
                $completion instanceof LessonAssessmentCompletion
                && $completedAttempt instanceof LessonAssessmentAttempt
                && $completedVersion instanceof LessonAssessmentVersion
                && (int) $completion->lesson_assessment_id === (int) $assessment->id
                && (int) $completedAttempt->lesson_assessment_id === (int) $assessment->id
                && (int) $completedVersion->lesson_assessment_id === (int) $assessment->id
            ) {
                $progressByLesson[$lessonId] = $this->progress(
                    'passed',
                    $completedAttempt,
                    $completedVersion,
                    $answeredCountsByAttempt,
                    completedAt: $completion->completed_at?->toJSON(),
                    passed: true,
                    retakeAvailable: $retakeAvailable,
                    retakeInProgress: $retakeInProgress,
                );

                continue;
            }

            if (
                $activeAttempt instanceof LessonAssessmentAttempt
                && $activeVersion instanceof LessonAssessmentVersion
                && (int) $activeVersion->lesson_assessment_id === (int) $assessment->id
            ) {
                $progressByLesson[$lessonId] = $this->progress(
                    (string) $activeAttempt->status,
                    $activeAttempt,
                    $activeVersion,
                    $answeredCountsByAttempt,
                    retakeAvailable: false,
                );

                continue;
            }

            if (
                $failedAttempt instanceof LessonAssessmentAttempt
                && $failedVersion instanceof LessonAssessmentVersion
                && (int) $failedVersion->lesson_assessment_id === (int) $assessment->id
            ) {
                $progressByLesson[$lessonId] = $this->progress(
                    LessonAssessmentAttempt::STATUS_FAILED,
                    $failedAttempt,
                    $failedVersion,
                    $answeredCountsByAttempt,
                    retakeAvailable: $retakeAvailable,
                );

                continue;
            }

            if ($publishedVersion instanceof LessonAssessmentVersion) {
                $progressByLesson[$lessonId] = [
                    'testAvailable' => true,
                    'testPassed' => false,
                    'testProgress' => [
                        'status' => 'not_started',
                        'answeredCount' => 0,
                        'correctCount' => 0,
                        'totalCount' => $this->questionCount($publishedVersion),
                        'score' => null,
                        'mastered' => false,
                        'completedAt' => null,
                        'retakeAvailable' => true,
                        'retakeInProgress' => false,
                    ],
                ];

                continue;
            }

            $progressByLesson[$lessonId] = $this->unavailable();
        }

        return $progressByLesson;
    }

    /** @return array{testAvailable:bool,testPassed:bool,testProgress:null} */
    private function unavailable(): array
    {
        return [
            'testAvailable' => false,
            'testPassed' => false,
            'testProgress' => null,
        ];
    }

    /**
     * @param  array<int, int>  $lessonIds
     * @return array<int, array{testAvailable:bool,testPassed:bool,testProgress:null}>
     */
    private function unavailableFor(array $lessonIds): array
    {
        return array_fill_keys($lessonIds, $this->unavailable());
    }

    /**
     * @param  Collection<int, int>  $answeredCountsByAttempt
     * @return array{testAvailable:bool,testPassed:bool,testProgress:array{status:string,answeredCount:int,correctCount:int,totalCount:int,completedAt:?string,retakeAvailable:bool,retakeInProgress:bool}}
     */
    private function progress(
        string $status,
        LessonAssessmentAttempt $attempt,
        LessonAssessmentVersion $version,
        Collection $answeredCountsByAttempt,
        ?string $completedAt = null,
        bool $passed = false,
        bool $retakeAvailable = false,
        bool $retakeInProgress = false,
    ): array {
        $score = $attempt->finalized_at !== null && is_array($attempt->final_score)
            ? $attempt->final_score
            : null;
        $automaticGate = $attempt->submitted_at !== null && is_array($attempt->automatic_score)
            ? $this->automaticGate($attempt->automatic_score)
            : null;

        $progress = [
            'status' => $status,
            'answeredCount' => (int) ($answeredCountsByAttempt->get($attempt->id) ?? 0),
            'correctCount' => (int) $attempt->correct_count,
            'totalCount' => $this->questionCount($version),
            'score' => $score,
            'mastered' => is_array($score) && ($score['mastered'] ?? false) === true,
        ];
        if ($automaticGate !== null) {
            $progress['automaticGate'] = $automaticGate;
        }
        $progress['completedAt'] = $completedAt;
        $progress['retakeAvailable'] = $retakeAvailable;
        $progress['retakeInProgress'] = $retakeInProgress;

        return [
            'testAvailable' => true,
            'testPassed' => $passed,
            'testProgress' => $progress,
        ];
    }

    private function questionCount(LessonAssessmentVersion $version): int
    {
        return is_array($version->questions) && array_is_list($version->questions)
            ? count($version->questions)
            : 0;
    }

    /** @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    private function automaticGate(array $snapshot): array
    {
        return [
            'passed' => ($snapshot['passed'] ?? null) === true,
            'overall' => is_array($snapshot['overall'] ?? null) ? $snapshot['overall'] : null,
            'sections' => is_array($snapshot['sections'] ?? null) ? array_values($snapshot['sections']) : [],
            'correctCount' => (int) ($snapshot['correctCount'] ?? 0),
        ];
    }
}
