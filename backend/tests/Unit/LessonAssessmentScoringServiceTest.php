<?php

namespace Tests\Unit;

use App\Services\Learning\LessonAssessmentAnswerChecker;
use App\Services\Learning\LessonAssessmentScoringService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class LessonAssessmentScoringServiceTest extends TestCase
{
    #[DataProvider('overallBoundaryProvider')]
    public function test_exact_overall_boundaries(
        int $earnedParts,
        int $maxParts,
        int $percentBps,
        bool $passed,
        bool $mastered,
    ): void {
        $score = $this->service()->summarize(
            [$this->section('main')],
            [$this->question('q1', 'main')],
            [$this->questionResult('q1', $earnedParts, $maxParts)],
        );

        $this->assertSame($earnedParts, $score['overall']['numerator']);
        $this->assertSame($maxParts, $score['overall']['denominator']);
        $this->assertSame($percentBps, $score['overall']['percentBps']);
        $this->assertSame($passed, $score['passed']);
        $this->assertSame($mastered, $score['mastered']);
    }

    public function test_overall_pass_cannot_override_a_failing_section(): void
    {
        $score = $this->service()->summarize(
            [$this->section('strong'), $this->section('weak')],
            [
                $this->question('q1', 'strong'),
                $this->question('q2', 'strong'),
                $this->question('q3', 'weak'),
            ],
            [
                $this->questionResult('q1', 1, 1),
                $this->questionResult('q2', 1, 1),
                $this->questionResult('q3', 5999, 10000),
            ],
        );

        $this->assertTrue($score['overall']['passed']);
        $this->assertSame(8666, $score['overall']['percentBps']);
        $this->assertFalse($score['sections'][1]['passed']);
        $this->assertFalse($score['passed']);
    }

    public function test_exact_eighty_percent_passes_when_all_non_empty_sections_pass(): void
    {
        $questions = [];
        $results = [];
        foreach (range(1, 5) as $number) {
            $questionId = "q{$number}";
            $questions[] = $this->question($questionId, 'main');
            $results[] = $this->questionResult($questionId, $number < 5 ? 1 : 0, 1);
        }

        $score = $this->service()->summarize(
            [$this->section('main'), $this->section('empty')],
            $questions,
            $results,
        );

        $this->assertSame(4, $score['overall']['numerator']);
        $this->assertSame(5, $score['overall']['denominator']);
        $this->assertTrue($score['passed']);
        $this->assertFalse($score['mastered']);
        $this->assertSame(['main'], array_column($score['sections'], 'sectionId'));
    }

    public function test_exact_eighty_percent_overall_fails_when_a_section_is_5999_basis_points(): void
    {
        $score = $this->service()->summarize(
            [$this->section('weak'), $this->section('strong')],
            [
                $this->question('q1', 'weak'),
                $this->question('q2', 'strong'),
                $this->question('q3', 'strong'),
                $this->question('q4', 'strong'),
                $this->question('q5', 'strong'),
            ],
            [
                $this->questionResult('q1', 5999, 10000),
                $this->questionResult('q2', 4001, 10000),
                $this->questionResult('q3', 1, 1),
                $this->questionResult('q4', 1, 1),
                $this->questionResult('q5', 1, 1),
            ],
        );

        $this->assertSame(4, $score['overall']['numerator']);
        $this->assertSame(5, $score['overall']['denominator']);
        $this->assertSame(8000, $score['overall']['percentBps']);
        $this->assertTrue($score['overall']['passed']);
        $this->assertSame(5999, $score['sections'][0]['percentBps']);
        $this->assertFalse($score['sections'][0]['passed']);
        $this->assertFalse($score['passed']);
        $this->assertFalse($score['mastered']);
    }

    public function test_multipart_partial_credit_is_normalized_per_top_level_question(): void
    {
        $parts = [
            ['id' => 'p1', 'prompt' => 'Part 1', 'optionIds' => ['o1', 'o2']],
            ['id' => 'p2', 'prompt' => 'Part 2', 'optionIds' => ['o1', 'o2']],
            ['id' => 'p3', 'prompt' => 'Part 3', 'optionIds' => ['o1', 'o2']],
        ];
        $questions = [
            ['id' => 'q1', 'sectionId' => 'main', 'type' => 'slot_choice', 'parts' => $parts],
            [
                'id' => 'q2',
                'sectionId' => 'main',
                'type' => 'single_choice',
                'options' => [['id' => 'o1', 'label' => 'One'], ['id' => 'o2', 'label' => 'Two']],
            ],
            ['id' => 'q3', 'sectionId' => 'main', 'type' => 'slot_choice', 'parts' => array_slice($parts, 0, 2)],
        ];
        $answerKey = [
            'q1' => ['response' => ['selections' => ['p1' => 'o1', 'p2' => 'o1', 'p3' => 'o1']]],
            'q2' => ['response' => ['selectedOptionId' => 'o1']],
            'q3' => ['response' => ['selections' => ['p1' => 'o1', 'p2' => 'o1']]],
        ];
        $responses = [
            'q1' => ['selections' => ['p1' => 'o1', 'p2' => 'o1', 'p3' => 'o2']],
            'q2' => ['selectedOptionId' => 'o1'],
            'q3' => ['selections' => ['p1' => 'o2', 'p2' => 'o2']],
        ];

        $graded = $this->service()->grade([$this->section('main')], $questions, $answerKey, $responses);

        $this->assertSame([2, 1, 0], array_column($graded['questionResults'], 'earnedParts'));
        $this->assertSame([3, 1, 2], array_column($graded['questionResults'], 'maxParts'));
        $this->assertSame(1, $graded['correctCount']);
        $this->assertSame(5, $graded['score']['overall']['numerator']);
        $this->assertSame(9, $graded['score']['overall']['denominator']);
        $this->assertSame(5556, $graded['score']['overall']['percentBps']);
    }

    public function test_display_basis_points_use_deterministic_half_up_rounding(): void
    {
        $score = $this->service()->summarize(
            [$this->section('main')],
            [$this->question('q1', 'main')],
            [$this->questionResult('q1', 8, 11)],
        );

        $this->assertSame(7273, $score['overall']['percentBps']);
    }

    public function test_hybrid_scoring_uses_only_automatic_questions_for_gate_and_skips_manual_only_sections(): void
    {
        $score = $this->service()->summarizeAutomatic(
            [$this->section('automatic'), $this->section('manual-only')],
            [
                [...$this->question('automatic-1', 'automatic'), 'gradingMode' => 'automatic'],
                [...$this->question('manual-1', 'manual-only'), 'gradingMode' => 'manual'],
            ],
            [
                $this->questionResult('automatic-1', 4, 5),
                $this->questionResult('manual-1', 0, 1),
            ],
        );

        $this->assertSame(8000, $score['overall']['percentBps']);
        $this->assertTrue($score['passed']);
        $this->assertSame(['automatic'], array_column($score['sections'], 'sectionId'));
    }

    public function test_hybrid_manual_and_final_scores_use_integer_half_up_rounding_at_thresholds(): void
    {
        $service = $this->service();

        $this->assertSame(5999, $service->manualComponentBps([5999, 5999]));
        $this->assertSame(6000, $service->manualComponentBps([5999, 6000]));
        $this->assertSame(0, $service->manualProgressBps([], 7));
        $this->assertSame(1143, $service->manualProgressBps([8000], 7));
        $this->assertSame(8000, $service->manualProgressBps([8000, 8000], 2));
        $this->assertSame(7999, $service->finalBps(7999, 8000));
        $this->assertSame(8000, $service->finalBps(8000, 8000));
    }

    public static function overallBoundaryProvider(): array
    {
        return [
            '79.99 percent' => [7999, 10000, 7999, false, false],
            '80.00 percent' => [4, 5, 8000, true, false],
            '99.99 percent' => [9999, 10000, 9999, true, false],
            '100.00 percent' => [1, 1, 10000, true, true],
        ];
    }

    private function service(): LessonAssessmentScoringService
    {
        return new LessonAssessmentScoringService(new LessonAssessmentAnswerChecker);
    }

    private function section(string $id): array
    {
        return ['id' => $id, 'title' => ucfirst($id)];
    }

    private function question(string $id, string $sectionId): array
    {
        return ['id' => $id, 'sectionId' => $sectionId];
    }

    private function questionResult(string $questionId, int $earnedParts, int $maxParts): array
    {
        return [
            'questionId' => $questionId,
            'correct' => $earnedParts === $maxParts,
            'earnedParts' => $earnedParts,
            'maxParts' => $maxParts,
            'incorrectPartIds' => [],
        ];
    }
}
