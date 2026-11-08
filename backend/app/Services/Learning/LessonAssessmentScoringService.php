<?php

namespace App\Services\Learning;

use OverflowException;
use UnexpectedValueException;

class LessonAssessmentScoringService
{
    public const OVERALL_REQUIRED_BPS = 8000;

    public const SECTION_REQUIRED_BPS = 6000;

    public const MASTERY_REQUIRED_BPS = 10000;

    public function __construct(private readonly LessonAssessmentAnswerChecker $checker) {}

    /**
     * @param  list<array<string, mixed>>  $sections
     * @param  list<array<string, mixed>>  $questions
     * @param  array<string, mixed>  $answerKey
     * @param  array<string, array<string, mixed>>  $responses
     * @return array{
     *     questionResults: list<array{questionId:string,correct:bool,earnedParts:int,maxParts:int,incorrectPartIds:list<string>}>,
     *     correctCount: int,
     *     score: array<string, mixed>
     * }
     */
    public function grade(array $sections, array $questions, array $answerKey, array $responses): array
    {
        $questionResults = [];
        $correctCount = 0;

        foreach ($questions as $question) {
            $questionId = is_array($question) ? ($question['id'] ?? null) : null;
            $keyEntry = is_string($questionId) ? ($answerKey[$questionId] ?? null) : null;
            $response = is_string($questionId) ? ($responses[$questionId] ?? null) : null;
            if (
                ! is_array($question)
                || ! is_string($questionId)
                || ! is_array($keyEntry)
                || ! is_array($keyEntry['response'] ?? null)
                || ! is_array($response)
            ) {
                throw new UnexpectedValueException('Assessment scoring input is invalid.');
            }

            $result = $this->checker->check($question, $keyEntry['response'], $response);
            if ($result['maxParts'] < 1 || $result['earnedParts'] < 0 || $result['earnedParts'] > $result['maxParts']) {
                throw new UnexpectedValueException('Assessment question has no valid graded atomic parts.');
            }

            if ($result['correct']) {
                $correctCount++;
            }
            $questionResults[] = [
                'questionId' => $questionId,
                'correct' => $result['correct'],
                'earnedParts' => $result['earnedParts'],
                'maxParts' => $result['maxParts'],
                'incorrectPartIds' => $result['incorrectPartIds'],
            ];
        }

        return [
            'questionResults' => $questionResults,
            'correctCount' => $correctCount,
            'score' => $this->summarize($sections, $questions, $questionResults),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $sections
     * @param  list<array<string, mixed>>  $questions
     * @param  list<array{questionId:string,correct:bool,earnedParts:int,maxParts:int,incorrectPartIds:list<string>}>  $questionResults
     * @return array<string, mixed>
     */
    public function summarize(array $sections, array $questions, array $questionResults): array
    {
        if ($questions === [] || count($questions) !== count($questionResults)) {
            throw new UnexpectedValueException('Assessment score requires one result per question.');
        }

        $sectionFractions = [];
        $sectionOrder = [];
        foreach ($sections as $section) {
            $sectionId = is_array($section) ? ($section['id'] ?? null) : null;
            if (! is_string($sectionId) || isset($sectionFractions[$sectionId])) {
                throw new UnexpectedValueException('Assessment section definitions are invalid.');
            }
            $sectionOrder[] = $sectionId;
            $sectionFractions[$sectionId] = [];
        }

        $resultsByQuestion = [];
        foreach ($questionResults as $result) {
            $questionId = $result['questionId'] ?? null;
            $earnedParts = $result['earnedParts'] ?? null;
            $maxParts = $result['maxParts'] ?? null;
            if (
                ! is_string($questionId)
                || isset($resultsByQuestion[$questionId])
                || ! is_int($earnedParts)
                || ! is_int($maxParts)
                || $maxParts < 1
                || $earnedParts < 0
                || $earnedParts > $maxParts
            ) {
                throw new UnexpectedValueException('Assessment question score is invalid.');
            }
            $resultsByQuestion[$questionId] = [$earnedParts, $maxParts];
        }

        $overallFractions = [];
        foreach ($questions as $question) {
            $questionId = is_array($question) ? ($question['id'] ?? null) : null;
            $sectionId = is_array($question) ? ($question['sectionId'] ?? null) : null;
            if (
                ! is_string($questionId)
                || ! is_string($sectionId)
                || ! array_key_exists($sectionId, $sectionFractions)
                || ! isset($resultsByQuestion[$questionId])
            ) {
                throw new UnexpectedValueException('Assessment question membership is invalid.');
            }

            $fraction = $this->reduce(...$resultsByQuestion[$questionId]);
            $overallFractions[] = $fraction;
            $sectionFractions[$sectionId][] = $fraction;
        }

        $overallRatio = $this->aggregate($overallFractions);
        $overallPassed = $this->meetsThreshold($overallRatio, self::OVERALL_REQUIRED_BPS);
        $sectionScores = [];
        $sectionsPassed = true;
        foreach ($sectionOrder as $sectionId) {
            if ($sectionFractions[$sectionId] === []) {
                continue;
            }

            $ratio = $this->aggregate($sectionFractions[$sectionId]);
            $passed = $this->meetsThreshold($ratio, self::SECTION_REQUIRED_BPS);
            $sectionsPassed = $sectionsPassed && $passed;
            $sectionScores[] = [
                'sectionId' => $sectionId,
                ...$this->ratioPayload($ratio, self::SECTION_REQUIRED_BPS, $passed),
            ];
        }

        $passed = $overallPassed && $sectionsPassed;
        $mastered = $overallRatio[0] === $overallRatio[1];

        return [
            'checked' => true,
            'passed' => $passed,
            'mastered' => $mastered,
            'overall' => $this->ratioPayload($overallRatio, self::OVERALL_REQUIRED_BPS, $overallPassed),
            'sections' => $sectionScores,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $sections
     * @param  list<array<string, mixed>>  $questions
     * @param  list<array{questionId:string,correct:bool,earnedParts:int,maxParts:int,incorrectPartIds:list<string>}>  $questionResults
     * @return array<string, mixed>
     */
    public function summarizeAutomatic(array $sections, array $questions, array $questionResults): array
    {
        $automaticQuestionIds = [];
        foreach ($questions as $question) {
            if (($question['gradingMode'] ?? null) === 'automatic') {
                $automaticQuestionIds[] = $question['id'] ?? null;
            }
        }

        if ($automaticQuestionIds === [] || in_array(null, $automaticQuestionIds, true)) {
            throw new UnexpectedValueException('Assessment requires automatic questions.');
        }

        $automaticIds = array_fill_keys($automaticQuestionIds, true);
        $automaticQuestions = array_values(array_filter(
            $questions,
            static fn (array $question): bool => isset($automaticIds[$question['id'] ?? '']),
        ));
        $automaticResults = array_values(array_filter(
            $questionResults,
            static fn (array $result): bool => isset($automaticIds[$result['questionId'] ?? '']),
        ));

        return $this->summarize($sections, $automaticQuestions, $automaticResults);
    }

    /** @param list<int> $itemScoresBps */
    public function manualComponentBps(array $itemScoresBps): int
    {
        if ($itemScoresBps === []) {
            throw new UnexpectedValueException('Manual score requires at least one review item.');
        }

        $sum = 0;
        foreach ($itemScoresBps as $scoreBps) {
            if (! is_int($scoreBps) || $scoreBps < 0 || $scoreBps > 10000) {
                throw new UnexpectedValueException('Manual review score must be between 0 and 10000 basis points.');
            }
            $sum = $this->add($sum, $scoreBps);
        }

        return $this->divideHalfUp($sum, count($itemScoresBps));
    }

    /** @param  list<int>  $reviewedItemScoresBps */
    public function manualProgressBps(array $reviewedItemScoresBps, int $totalItemCount): int
    {
        if ($totalItemCount < 1 || count($reviewedItemScoresBps) > $totalItemCount) {
            throw new UnexpectedValueException('Manual review progress requires a valid total item count.');
        }

        return $this->manualComponentBps(array_pad($reviewedItemScoresBps, $totalItemCount, 0));
    }

    public function finalBps(int $automaticBps, int $manualBps): int
    {
        foreach ([$automaticBps, $manualBps] as $scoreBps) {
            if ($scoreBps < 0 || $scoreBps > 10000) {
                throw new UnexpectedValueException('Hybrid component score must be between 0 and 10000 basis points.');
            }
        }

        return $this->divideHalfUp(
            $this->add($this->multiply($automaticBps, 7000), $this->multiply($manualBps, 3000)),
            10000,
        );
    }

    /**
     * @param  array<string, mixed>  $leftScore
     * @param  array<string, mixed>  $rightScore
     */
    public function compareOverall(array $leftScore, array $rightScore): int
    {
        $left = $this->scoreRatio($leftScore);
        $right = $this->scoreRatio($rightScore);
        $leftProduct = $this->multiply($left[0], $right[1]);
        $rightProduct = $this->multiply($right[0], $left[1]);

        return $leftProduct <=> $rightProduct;
    }

    /** @param list<array{0:int,1:int}> $fractions
     * @return array{0:int,1:int}
     */
    private function aggregate(array $fractions): array
    {
        if ($fractions === []) {
            throw new UnexpectedValueException('Cannot aggregate an empty assessment score.');
        }

        $sumNumerator = 0;
        $sumDenominator = 1;
        foreach ($fractions as [$numerator, $denominator]) {
            $commonDivisor = $this->gcd($sumDenominator, $denominator);
            $leftMultiplier = intdiv($denominator, $commonDivisor);
            $rightMultiplier = intdiv($sumDenominator, $commonDivisor);
            $commonDenominator = $this->multiply($sumDenominator, $leftMultiplier);
            $leftNumerator = $this->multiply($sumNumerator, $leftMultiplier);
            $rightNumerator = $this->multiply($numerator, $rightMultiplier);
            $sumNumerator = $this->add($leftNumerator, $rightNumerator);
            [$sumNumerator, $sumDenominator] = $this->reduce($sumNumerator, $commonDenominator);
        }

        return $this->reduce($sumNumerator, $this->multiply($sumDenominator, count($fractions)));
    }

    /** @return array{0:int,1:int} */
    private function reduce(int $numerator, int $denominator): array
    {
        if ($denominator < 1 || $numerator < 0) {
            throw new UnexpectedValueException('Assessment score ratio is invalid.');
        }
        if ($numerator === 0) {
            return [0, 1];
        }

        $divisor = $this->gcd($numerator, $denominator);

        return [intdiv($numerator, $divisor), intdiv($denominator, $divisor)];
    }

    private function gcd(int $left, int $right): int
    {
        while ($right !== 0) {
            [$left, $right] = [$right, $left % $right];
        }

        return abs($left);
    }

    /** @param array{0:int,1:int} $ratio */
    private function meetsThreshold(array $ratio, int $requiredBps): bool
    {
        return $this->multiply($ratio[0], 10000) >= $this->multiply($ratio[1], $requiredBps);
    }

    /**
     * @param  array{0:int,1:int}  $ratio
     * @return array{numerator:int,denominator:int,percentBps:int,requiredBps:int,passed:bool}
     */
    private function ratioPayload(array $ratio, int $requiredBps, bool $passed): array
    {
        [$numerator, $denominator] = $ratio;
        $doubledDenominator = $this->multiply($denominator, 2);
        $scaledNumerator = $this->multiply($numerator, 20000);
        $percentBps = intdiv($this->add($scaledNumerator, $denominator), $doubledDenominator);

        return [
            'numerator' => $numerator,
            'denominator' => $denominator,
            'percentBps' => $percentBps,
            'requiredBps' => $requiredBps,
            'passed' => $passed,
        ];
    }

    /** @param array<string, mixed> $score
     * @return array{0:int,1:int}
     */
    private function scoreRatio(array $score): array
    {
        $overall = $score['overall'] ?? null;
        $numerator = is_array($overall) ? ($overall['numerator'] ?? null) : null;
        $denominator = is_array($overall) ? ($overall['denominator'] ?? null) : null;
        if (
            ! is_int($numerator)
            || ! is_int($denominator)
            || $numerator < 0
            || $denominator < 1
            || $numerator > $denominator
        ) {
            throw new UnexpectedValueException('Assessment score ratio is invalid.');
        }

        return [$numerator, $denominator];
    }

    private function multiply(int $left, int $right): int
    {
        if ($left !== 0 && $right > intdiv(PHP_INT_MAX, $left)) {
            throw new OverflowException('Assessment score exceeds integer bounds.');
        }

        return $left * $right;
    }

    private function add(int $left, int $right): int
    {
        if ($right > PHP_INT_MAX - $left) {
            throw new OverflowException('Assessment score exceeds integer bounds.');
        }

        return $left + $right;
    }

    private function divideHalfUp(int $numerator, int $denominator): int
    {
        if ($numerator < 0 || $denominator < 1) {
            throw new UnexpectedValueException('Assessment score ratio is invalid.');
        }

        return intdiv($this->add($numerator, intdiv($denominator, 2)), $denominator);
    }
}
