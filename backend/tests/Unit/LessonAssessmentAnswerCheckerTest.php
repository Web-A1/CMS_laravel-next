<?php

namespace Tests\Unit;

use App\Services\Learning\LessonAssessmentAnswerChecker;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class LessonAssessmentAnswerCheckerTest extends TestCase
{
    #[DataProvider('answerProvider')]
    public function test_it_checks_all_supported_question_types(
        array $question,
        array $answer,
        array $response,
        bool $correct,
        array $incorrectPartIds = [],
        ?int $earnedParts = null,
        int $maxParts = 1,
    ): void {
        $result = (new LessonAssessmentAnswerChecker)->check($question, $answer, $response);

        $this->assertSame([
            'correct' => $correct,
            'earnedParts' => $earnedParts ?? ($correct ? $maxParts : 0),
            'maxParts' => $maxParts,
            'incorrectPartIds' => $incorrectPartIds,
        ], $result);
    }

    public function test_unordered_multiple_choice_uses_exact_set_equality(): void
    {
        $question = $this->choiceQuestion('multiple_choice', ['o1', 'o2', 'o3'], false);
        $invalidDirectQuestion = $this->choiceQuestion('multiple_choice', ['o1', 'o2', 'o3'], true);
        $answer = ['selectedOptionIds' => ['o1', 'o2']];
        $checker = new LessonAssessmentAnswerChecker;

        $this->assertTrue($checker->check($question, $answer, [
            'selectedOptionIds' => ['o2', 'o1'],
        ])['correct']);
        $this->assertFalse($checker->check($question, $answer, [
            'selectedOptionIds' => ['o1', 'o2', 'o3'],
        ])['correct']);
        $this->assertFalse($checker->check($question, $answer, [
            'selectedOptionIds' => ['o1', 'o1'],
        ])['correct']);
        $this->assertTrue($checker->check($invalidDirectQuestion, $answer, [
            'selectedOptionIds' => ['o2', 'o1'],
        ])['correct']);
    }

    public function test_ordered_choice_requires_exact_list_equality(): void
    {
        $question = $this->choiceQuestion('ordered_choice', ['o1', 'o2', 'o3']);
        $answer = ['selectedOptionIds' => ['o1', 'o2', 'o3']];
        $checker = new LessonAssessmentAnswerChecker;

        $this->assertTrue($checker->check($question, $answer, $answer)['correct']);
        $this->assertFalse($checker->check($question, $answer, [
            'selectedOptionIds' => ['o2', 'o1', 'o3'],
        ])['correct']);
    }

    public function test_unknown_options_and_incomplete_responses_cannot_be_correct(): void
    {
        $checker = new LessonAssessmentAnswerChecker;
        $single = $this->choiceQuestion('single_choice', ['o1', 'o2']);
        $slots = $this->partsQuestion('slot_choice');

        $this->assertFalse($checker->check(
            $single,
            ['selectedOptionId' => 'o1'],
            ['selectedOptionId' => 'unknown'],
        )['correct']);
        $this->assertFalse($checker->check(
            $single,
            ['selectedOptionId' => 'o1'],
            [],
        )['correct']);
        $this->assertSame(['p02'], $checker->check(
            $slots,
            ['selections' => ['p01' => 'o1', 'p02' => 'o2']],
            ['selections' => ['p01' => 'o1']],
        )['incorrectPartIds']);
    }

    public function test_zero_part_question_fails_closed_without_a_synthetic_atomic_part(): void
    {
        $result = (new LessonAssessmentAnswerChecker)->check(
            [...$this->choiceQuestion('slot_choice', ['o1']), 'parts' => []],
            ['selections' => []],
            ['selections' => []],
        );

        $this->assertSame([
            'correct' => false,
            'earnedParts' => 0,
            'maxParts' => 0,
            'incorrectPartIds' => [],
        ], $result);
    }

    public function test_text_fields_are_checked_with_normalized_exact_answers(): void
    {
        $question = [
            'id' => 'fill-01',
            'type' => 'text_fields',
            'parts' => [
                ['id' => 'p01', 'prompt' => 'Пропуск 1'],
                ['id' => 'p02', 'prompt' => 'Пропуск 2'],
            ],
        ];
        $answer = [
            'acceptedTexts' => [
                'p01' => ['Жёлтая связка'],
                'p02' => ['discus intervertebralis'],
            ],
        ];
        $checker = new LessonAssessmentAnswerChecker;

        $this->assertSame([
            'correct' => true,
            'earnedParts' => 2,
            'maxParts' => 2,
            'incorrectPartIds' => [],
        ], $checker->check($question, $answer, [
            'texts' => [
                'p01' => '  желтая, связка! ',
                'p02' => 'DISCUS   INTERVERTEBRALIS',
            ],
        ]));
        $this->assertSame(['p02'], $checker->check($question, $answer, [
            'texts' => ['p01' => 'Жёлтая связка', 'p02' => 'диск'],
        ])['incorrectPartIds']);
    }

    public function test_unordered_text_groups_accept_any_permutation_but_not_duplicates(): void
    {
        $question = [
            'id' => 'fundamentals-01',
            'type' => 'text_fields',
            'parts' => [
                ['id' => 'p01', 'prompt' => 'Строка 1'],
                ['id' => 'p02', 'prompt' => 'Строка 2'],
                ['id' => 'p03', 'prompt' => 'Строка 3'],
            ],
        ];
        $answer = [
            'acceptedTexts' => [
                'p01' => ['Связки'],
                'p02' => ['Швы'],
                'p03' => ['Роднички'],
            ],
            'unorderedPartGroups' => [['p01', 'p02', 'p03']],
        ];
        $checker = new LessonAssessmentAnswerChecker;

        $this->assertSame([
            'correct' => true,
            'earnedParts' => 3,
            'maxParts' => 3,
            'incorrectPartIds' => [],
        ], $checker->check($question, $answer, [
            'texts' => [
                'p01' => 'роднички',
                'p02' => 'СВЯЗКИ',
                'p03' => 'швы',
            ],
        ]));
        $this->assertSame(['p02'], $checker->check($question, $answer, [
            'texts' => [
                'p01' => 'связки',
                'p02' => 'связки',
                'p03' => 'роднички',
            ],
        ])['incorrectPartIds']);
    }

    public function test_long_text_requires_all_semantic_fragments_in_any_order(): void
    {
        $question = ['id' => 'expanded-01', 'type' => 'long_text', 'rows' => 3];
        $answer = [
            'requiredFragments' => [
                ['шейный отдел', 'шейные позвонки'],
                ['грудной отдел'],
                ['поясничный отдел'],
            ],
        ];
        $checker = new LessonAssessmentAnswerChecker;

        $this->assertTrue($checker->check($question, $answer, [
            'text' => 'Поясничный отдел; затем ШЕЙНЫЕ ПОЗВОНКИ, а также грудной отдел.',
        ])['correct']);
        $this->assertSame(['answer'], $checker->check($question, $answer, [
            'text' => 'Шейный и грудной отделы.',
        ])['incorrectPartIds']);
    }

    public function test_long_text_rejects_keyword_soup_and_negated_concepts(): void
    {
        $question = ['id' => 'expanded-01', 'type' => 'long_text', 'rows' => 3];
        $answer = [
            'requiredFragments' => [
                ['шейный отдел', 'шейные позвонки'],
                ['грудной отдел'],
                ['поясничный отдел'],
            ],
        ];
        $checker = new LessonAssessmentAnswerChecker;

        $this->assertSame(['answer'], $checker->check($question, $answer, [
            'text' => 'Шейный отдел, грудной отдел, поясничный отдел.',
        ])['incorrectPartIds']);
        $this->assertSame(['answer'], $checker->check($question, $answer, [
            'text' => 'Шейный отдел, грудной отдел и поясничный отдел не относятся к позвоночнику.',
        ])['incorrectPartIds']);
    }

    public static function answerProvider(): array
    {
        $test = new self('test_it_checks_all_supported_question_types');

        return [
            'single choice correct by ID, not label' => [
                $test->choiceQuestion('single_choice', ['o1', 'o2']),
                ['selectedOptionId' => 'o1'],
                ['selectedOptionId' => 'o1', 'label' => 'ignored'],
                true,
            ],
            'multiple choice exact set' => [
                $test->choiceQuestion('multiple_choice', ['o1', 'o2', 'o3'], false),
                ['selectedOptionIds' => ['o1', 'o2']],
                ['selectedOptionIds' => ['o2', 'o1']],
                true,
            ],
            'ordered choice wrong order' => [
                $test->choiceQuestion('ordered_choice', ['o1', 'o2', 'o3']),
                ['selectedOptionIds' => ['o1', 'o2', 'o3']],
                ['selectedOptionIds' => ['o2', 'o1', 'o3']],
                false,
            ],
            'slot choice reports only incorrect part IDs' => [
                $test->partsQuestion('slot_choice'),
                ['selections' => ['p01' => 'o1', 'p02' => 'o2']],
                ['selections' => ['p01' => 'o2', 'p02' => 'o2']],
                false,
                ['p01'],
                1,
                2,
            ],
            'diagram labels report canonical incorrect part IDs' => [
                $test->partsQuestion('diagram_labels'),
                ['selections' => ['p01' => 'o1', 'p02' => 'o2']],
                ['selections' => ['p01' => 'o1', 'p02' => 'o1']],
                false,
                ['p02'],
                1,
                2,
            ],
        ];
    }

    private function choiceQuestion(string $type, array $optionIds, ?bool $orderMatters = null): array
    {
        $question = [
            'id' => 'question-01',
            'type' => $type,
            'options' => array_map(
                fn (string $id): array => ['id' => $id, 'label' => "Label {$id}"],
                $optionIds,
            ),
        ];

        if ($orderMatters !== null) {
            $question['orderMatters'] = $orderMatters;
        }

        return $question;
    }

    private function partsQuestion(string $type): array
    {
        return [
            ...$this->choiceQuestion($type, ['o1', 'o2', 'o3']),
            'parts' => [
                ['id' => 'p01', 'prompt' => 'Part 1', 'optionIds' => ['o1', 'o2']],
                ['id' => 'p02', 'prompt' => 'Part 2', 'optionIds' => ['o1', 'o2']],
            ],
        ];
    }
}
