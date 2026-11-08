<?php

namespace App\Services\Learning;

use App\Models\Course;
use App\Models\Lesson;
use App\Models\LessonAssessment;
use App\Models\LessonAssessmentAsset;
use App\Models\LessonAssessmentAttempt;
use App\Models\LessonAssessmentAttemptResponse;
use App\Models\LessonAssessmentManualReview;
use App\Models\LessonAssessmentVersion;
use App\Models\Subject;
use stdClass;

class LessonAssessmentPresenter
{
    public function __construct(private readonly LessonAssessmentScoringService $scoring) {}

    /** @return array<string, string>|null */
    public function developmentCalibrationTerms(
        LessonAssessmentVersion $version,
        string $questionId,
    ): ?array {
        $questions = is_array($version->questions) && array_is_list($version->questions)
            ? $version->questions
            : [];
        $question = collect($questions)->first(
            fn (mixed $candidate): bool => is_array($candidate)
                && ($candidate['id'] ?? null) === $questionId
                && in_array($candidate['type'] ?? null, ['diagram_labels', 'diagram_text'], true),
        );
        $answerKey = $version->answer_key;
        $acceptedTexts = is_array($answerKey)
            ? ($answerKey[$questionId]['response']['acceptedTexts'] ?? null)
            : null;
        if (! is_array($question) || ! is_array($acceptedTexts) || array_is_list($acceptedTexts)) {
            return null;
        }

        $terms = [];
        $parts = is_array($question['parts'] ?? null) && array_is_list($question['parts'])
            ? $question['parts']
            : [];
        foreach ($parts as $part) {
            $partId = is_array($part) ? ($part['id'] ?? null) : null;
            $variants = is_string($partId) ? ($acceptedTexts[$partId] ?? null) : null;
            $canonical = is_array($variants) && array_is_list($variants) ? ($variants[0] ?? null) : null;
            if (is_string($partId) && is_string($canonical) && trim($canonical) !== '') {
                $terms[$partId] = $canonical;
            }
        }

        return $terms === [] ? null : $terms;
    }

    /**
     * @return array{data: array{assessment: array<string, mixed>, attempt: array<string, mixed>|null}}
     */
    public function present(
        Subject $subject,
        Course $course,
        Lesson $lesson,
        LessonAssessment $assessment,
        LessonAssessmentVersion $version,
        ?LessonAssessmentAttempt $attempt,
    ): array {
        $assets = $version->assets()
            ->get(['asset_key', 'width', 'height'])
            ->keyBy('asset_key');
        $questions = is_array($version->questions) && array_is_list($version->questions)
            ? array_values(array_filter(array_map(
                fn (mixed $question): ?array => is_array($question)
                    ? $this->question($question, $assets->get($question['assetKey'] ?? null), $subject, $course, $lesson)
                    : null,
                $version->questions,
            )))
            : [];
        $automaticQuestionCount = count(array_filter(
            $questions,
            static fn (array $question): bool => ($question['gradingMode'] ?? null) === 'automatic',
        ));
        $manualQuestionCount = count(array_filter(
            $questions,
            static fn (array $question): bool => ($question['gradingMode'] ?? null) === 'manual',
        ));
        $policy = is_array($version->scoring_policy) ? $version->scoring_policy : [];

        return [
            'data' => [
                'assessment' => [
                    'id' => (int) $assessment->id,
                    'contentVersion' => (int) $version->content_version,
                    'scoringPolicy' => [
                        'schemaVersion' => (int) ($policy['schemaVersion'] ?? 0),
                        'contentVersion' => (int) $version->content_version,
                        'mode' => (string) ($policy['mode'] ?? ''),
                        'automaticWeightBps' => (int) ($policy['automaticWeightBps'] ?? 0),
                        'manualWeightBps' => (int) ($policy['manualWeightBps'] ?? 0),
                        'automaticGateOverallRequiredBps' => (int) ($policy['automaticGateOverallRequiredBps'] ?? 0),
                        'automaticGateSectionRequiredBps' => (int) ($policy['automaticGateSectionRequiredBps'] ?? 0),
                        'manualRequiredBps' => (int) ($policy['manualRequiredBps'] ?? 0),
                        'finalRequiredBps' => (int) ($policy['finalRequiredBps'] ?? 0),
                        'automaticQuestionCount' => $automaticQuestionCount,
                        'manualQuestionCount' => $manualQuestionCount,
                    ],
                    'title' => (string) $lesson->title,
                    'totalCount' => count($questions),
                    'retakeAvailable' => (! $attempt instanceof LessonAssessmentAttempt
                            || in_array($attempt->status, [
                                LessonAssessmentAttempt::STATUS_FAILED,
                                LessonAssessmentAttempt::STATUS_PASSED,
                            ], true))
                        && LessonAssessmentVersion::query()
                            ->where('lesson_assessment_id', $assessment->id)
                            ->where('status', LessonAssessmentVersion::STATUS_PUBLISHED)
                            ->exists(),
                    'sections' => $this->sections($version->sections),
                    'questions' => $questions,
                ],
                'attempt' => $attempt === null ? null : $this->attempt($attempt, $version),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $question
     * @return array<string, mixed>|null
     */
    private function question(
        array $question,
        ?LessonAssessmentAsset $asset,
        Subject $subject,
        Course $course,
        Lesson $lesson,
    ): ?array {
        if (
            ! is_string($question['id'] ?? null)
            || ! is_string($question['sectionId'] ?? null)
            || ! is_string($question['type'] ?? null)
            || ! in_array($question['gradingMode'] ?? null, ['automatic', 'manual'], true)
            || ! is_string($question['prompt'] ?? null)
        ) {
            return null;
        }

        $payload = [
            'id' => $question['id'],
            'sectionId' => $question['sectionId'],
            'type' => $question['type'],
            'gradingMode' => $question['gradingMode'],
            'prompt' => $question['prompt'],
        ];

        if (is_array($question['options'] ?? null) && array_is_list($question['options'])) {
            $payload['options'] = array_values(array_filter(array_map(
                fn (mixed $option): ?array => is_array($option)
                    && is_string($option['id'] ?? null)
                    && is_string($option['label'] ?? null)
                        ? ['id' => $option['id'], 'label' => $option['label']]
                        : null,
                $question['options'],
            )));
        }

        if (is_bool($question['orderMatters'] ?? null)) {
            $payload['orderMatters'] = $question['orderMatters'];
        }

        if (is_int($question['rows'] ?? null) && $question['rows'] > 0) {
            $payload['rows'] = $question['rows'];
        }

        if (is_array($question['parts'] ?? null) && array_is_list($question['parts'])) {
            $payload['parts'] = array_values(array_filter(array_map(
                fn (mixed $part): ?array => $this->part($part),
                $question['parts'],
            )));
        }

        if (in_array($question['type'], ['diagram_labels', 'diagram_text'], true) && $asset instanceof LessonAssessmentAsset) {
            $payload['assetPath'] = sprintf(
                '/api/v1/learning/subjects/%s/courses/%s/lessons/%s/assessment/assets/%s',
                rawurlencode((string) $subject->slug),
                rawurlencode((string) $course->slug),
                rawurlencode((string) $lesson->slug),
                rawurlencode((string) $asset->asset_key),
            );
            $payload['width'] = (int) $asset->width;
            $payload['height'] = (int) $asset->height;
            $payload['callouts'] = is_array($question['callouts'] ?? null) && array_is_list($question['callouts'])
                ? array_values(array_filter(array_map(
                    fn (mixed $callout): ?array => $this->callout($callout),
                    $question['callouts'],
                )))
                : [];
        }

        return $payload;
    }

    /** @return array<string, mixed>|null */
    private function part(mixed $part): ?array
    {
        if (
            ! is_array($part)
            || ! is_string($part['id'] ?? null)
            || ! is_string($part['prompt'] ?? null)
        ) {
            return null;
        }

        $payload = [
            'id' => $part['id'],
            'prompt' => $part['prompt'],
        ];
        if (array_key_exists('optionIds', $part)) {
            if (
                ! is_array($part['optionIds'])
                || ! array_is_list($part['optionIds'])
                || count(array_filter($part['optionIds'], 'is_string')) !== count($part['optionIds'])
            ) {
                return null;
            }
            $payload['optionIds'] = array_values($part['optionIds']);
        }

        return $payload;
    }

    /** @return array{targetId?: string, partId: string, x: float|int, y: float|int, labelX?: float|int, labelY?: float|int, labelSide?: string, labelWidth?: float|int, leaderStyle?: string, targetMarker?: string}|null */
    private function callout(mixed $callout): ?array
    {
        if (
            ! is_array($callout)
            || ! is_string($callout['partId'] ?? null)
            || ! is_numeric($callout['x'] ?? null)
            || ! is_numeric($callout['y'] ?? null)
        ) {
            return null;
        }

        $x = (float) $callout['x'];
        $y = (float) $callout['y'];
        if (! is_finite($x) || ! is_finite($y) || $x < 0 || $x > 1 || $y < 0 || $y > 1) {
            return null;
        }

        $hasTargetId = array_key_exists('targetId', $callout);
        if ($hasTargetId && ! is_string($callout['targetId'])) {
            return null;
        }

        $payload = [
            ...($hasTargetId ? ['targetId' => $callout['targetId']] : []),
            'partId' => $callout['partId'],
            'x' => $callout['x'],
            'y' => $callout['y'],
        ];
        $hasLabelX = array_key_exists('labelX', $callout);
        $hasLabelY = array_key_exists('labelY', $callout);
        if ($hasLabelX !== $hasLabelY) {
            return null;
        }

        $hasLabelSide = array_key_exists('labelSide', $callout);
        $hasLabelWidth = array_key_exists('labelWidth', $callout);
        if ($hasLabelSide !== $hasLabelWidth || $hasLabelSide && ! $hasLabelX) {
            return null;
        }

        $hasLeaderStyle = array_key_exists('leaderStyle', $callout);
        if (
            $hasLeaderStyle
            && (! $hasLabelX
                || ! is_string($callout['leaderStyle'])
                || ! in_array($callout['leaderStyle'], ['elbow', 'straight'], true))
        ) {
            return null;
        }
        $hasTargetMarker = array_key_exists('targetMarker', $callout);
        if (
            $hasTargetMarker
            && (! is_string($callout['targetMarker'])
                || ! in_array($callout['targetMarker'], ['point', 'arrow'], true))
        ) {
            return null;
        }
        if ($hasTargetMarker) {
            $payload['targetMarker'] = $callout['targetMarker'];
        }

        $hasInlineLabel = $hasLabelX && $hasLabelY;
        if ($hasInlineLabel) {
            if (! is_numeric($callout['labelX']) || ! is_numeric($callout['labelY'])) {
                return null;
            }

            $labelX = (float) $callout['labelX'];
            $labelY = (float) $callout['labelY'];
            if (! is_finite($labelX) || ! is_finite($labelY) || $labelX < 0 || $labelX > 1 || $labelY < 0 || $labelY > 1) {
                return null;
            }

            $payload['labelX'] = $callout['labelX'];
            $payload['labelY'] = $callout['labelY'];
            if ($hasLeaderStyle) {
                $payload['leaderStyle'] = $callout['leaderStyle'];
            }
        }

        if ($hasLabelSide && $hasLabelWidth) {
            if (
                ! is_string($callout['labelSide'])
                || ! in_array($callout['labelSide'], ['left', 'right', 'top', 'bottom'], true)
                || ! is_numeric($callout['labelWidth'])
            ) {
                return null;
            }

            $labelX = (float) $callout['labelX'];
            $labelWidth = (float) $callout['labelWidth'];
            if (
                ! is_finite($labelWidth)
                || $labelWidth <= 0
                || $labelWidth > 1
                || ($callout['labelSide'] === 'left' && $labelWidth > $labelX)
                || ($callout['labelSide'] === 'right' && $labelX + $labelWidth > 1)
                || (in_array($callout['labelSide'], ['top', 'bottom'], true)
                    && ($labelX - $labelWidth / 2 < 0 || $labelX + $labelWidth / 2 > 1))
            ) {
                return null;
            }

            $payload['labelSide'] = $callout['labelSide'];
            $payload['labelWidth'] = $callout['labelWidth'];
        }

        return $payload;
    }

    /** @return list<array{id: string, title: string}> */
    private function sections(mixed $storedSections): array
    {
        if (! is_array($storedSections) || ! array_is_list($storedSections)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static function (mixed $section): ?array {
                if (
                    ! is_array($section)
                    || count($section) !== 2
                    || ! is_string($section['id'] ?? null)
                    || ! is_string($section['title'] ?? null)
                ) {
                    return null;
                }

                return [
                    'id' => $section['id'],
                    'title' => $section['title'],
                ];
            },
            $storedSections,
        )));
    }

    /**
     * @return array<string, mixed>
     */
    private function attempt(LessonAssessmentAttempt $attempt, LessonAssessmentVersion $version): array
    {
        $questions = is_array($version->questions) && array_is_list($version->questions)
            ? $version->questions
            : [];
        $stored = $attempt->responses()
            ->get(['question_id', 'response', 'is_complete', 'is_locked'])
            ->keyBy('question_id');
        $responses = [];
        $lockedQuestionIds = [];
        $answeredCount = 0;

        foreach ($questions as $question) {
            $response = $stored->get($question['id']);
            if (! $response instanceof LessonAssessmentAttemptResponse) {
                continue;
            }

            $responses[] = [
                'questionId' => (string) $response->question_id,
                'response' => $this->responseValue($response->response),
            ];
            if ($response->is_complete) {
                $answeredCount++;
            }
            if ($response->is_locked) {
                $lockedQuestionIds[] = (string) $response->question_id;
            }
        }

        $reviews = $attempt->manualReviews()->orderBy('id')->get();
        $payload = [
            'id' => (int) $attempt->id,
            'status' => (string) $attempt->status,
            'revision' => (int) $attempt->revision,
            'submissionCount' => (int) $attempt->check_count,
            'answeredCount' => $answeredCount,
            'correctCount' => (int) $attempt->correct_count,
            'lastQuestionId' => $attempt->last_question_id,
            'submittedAt' => $attempt->submitted_at?->toJSON(),
            'finalizedAt' => $attempt->finalized_at?->toJSON(),
            'passedAt' => $attempt->passed_at?->toJSON(),
            'failureReason' => $attempt->failure_reason,
            'pendingManualQuestionCount' => $reviews->where('status', LessonAssessmentManualReview::STATUS_PENDING)->count(),
            'reviewedManualQuestionCount' => $reviews->where('status', LessonAssessmentManualReview::STATUS_DECIDED)->count(),
            'reviewedManualQuestionIds' => $reviews
                ->where('status', LessonAssessmentManualReview::STATUS_DECIDED)
                ->pluck('question_id')
                ->map(static fn (mixed $questionId): string => (string) $questionId)
                ->values()
                ->all(),
            'responses' => $responses,
            'lockedQuestionIds' => $lockedQuestionIds,
        ];

        if ($attempt->submitted_at !== null && is_array($attempt->automatic_score)) {
            $payload['automaticGate'] = $this->automaticGate($attempt->automatic_score);
        }
        if ($attempt->status === LessonAssessmentAttempt::STATUS_PENDING_REVIEW) {
            $payload['manualProgressBps'] = $this->scoring->manualProgressBps(
                $reviews
                    ->where('status', LessonAssessmentManualReview::STATUS_DECIDED)
                    ->pluck('score_bps')
                    ->map(static fn (mixed $scoreBps): int => (int) $scoreBps)
                    ->values()
                    ->all(),
                $reviews->count(),
            );
        }
        if ($attempt->finalized_at !== null && is_array($attempt->final_score)) {
            $payload['score'] = $this->finalScore($attempt->final_score);
            $payload['manualResults'] = $reviews->map(
                static fn (LessonAssessmentManualReview $review): array => [
                    'questionId' => (string) $review->question_id,
                    'scoreBps' => (int) $review->score_bps,
                    'comment' => $review->comment,
                ],
            )->all();
        }

        return $payload;
    }

    /** @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    private function automaticGate(array $snapshot): array
    {
        $results = is_array($snapshot['questionResults'] ?? null) ? $snapshot['questionResults'] : [];

        return [
            'passed' => ($snapshot['passed'] ?? null) === true,
            'overall' => $snapshot['overall'] ?? null,
            'sections' => is_array($snapshot['sections'] ?? null) ? array_values($snapshot['sections']) : [],
            'correctCount' => (int) ($snapshot['correctCount'] ?? 0),
            'questionResults' => array_values(array_filter(array_map(
                static fn (mixed $result): ?array => is_array($result)
                    && is_string($result['questionId'] ?? null)
                    && is_bool($result['correct'] ?? null)
                        ? [
                            'questionId' => $result['questionId'],
                            'correct' => $result['correct'],
                            'earnedParts' => (int) ($result['earnedParts'] ?? 0),
                            'maxParts' => (int) ($result['maxParts'] ?? 0),
                            'incorrectPartIds' => is_array($result['incorrectPartIds'] ?? null)
                                ? array_values(array_filter($result['incorrectPartIds'], 'is_string'))
                                : [],
                        ]
                        : null,
                $results,
            ))),
        ];
    }

    /** @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    private function finalScore(array $snapshot): array
    {
        return [
            'checked' => true,
            'passed' => ($snapshot['passed'] ?? null) === true,
            'mastered' => ($snapshot['mastered'] ?? null) === true,
            'overall' => $snapshot['overall'] ?? null,
            'components' => $snapshot['components'] ?? null,
        ];
    }

    /** @return array<string, mixed>|stdClass */
    private function responseValue(mixed $response): array|stdClass
    {
        if (! is_array($response)) {
            return new stdClass;
        }

        if (array_keys($response) === ['selectedOptionId']) {
            $value = $response['selectedOptionId'];

            return ['selectedOptionId' => is_string($value) ? $value : null];
        }

        if (array_keys($response) === ['selectedOptionIds'] && is_array($response['selectedOptionIds'])) {
            return [
                'selectedOptionIds' => array_values(array_filter($response['selectedOptionIds'], 'is_string')),
            ];
        }

        if (array_keys($response) === ['selections'] && is_array($response['selections'])) {
            return ['selections' => array_filter(
                $response['selections'],
                fn (mixed $value, mixed $key): bool => is_string($key) && is_string($value),
                ARRAY_FILTER_USE_BOTH,
            )];
        }

        if (array_keys($response) === ['texts'] && is_array($response['texts'])) {
            return ['texts' => array_filter(
                $response['texts'],
                fn (mixed $value, mixed $key): bool => is_string($key) && is_string($value),
                ARRAY_FILTER_USE_BOTH,
            )];
        }

        if (array_keys($response) === ['text'] && is_string($response['text'])) {
            return ['text' => $response['text']];
        }

        return new stdClass;
    }
}
