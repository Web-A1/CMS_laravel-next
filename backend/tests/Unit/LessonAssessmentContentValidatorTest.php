<?php

namespace Tests\Unit;

use App\Services\Learning\LessonAssessmentContentValidator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LessonAssessmentContentValidatorTest extends TestCase
{
    public function test_it_validates_and_sanitizes_a_complete_authoring_fixture(): void
    {
        $validated = (new LessonAssessmentContentValidator)->validate($this->validFixture());

        $this->assertCount(2, $validated['questions']);
        $this->assertArrayNotHasKey('distractorOptionIds', $validated['questions'][0]);
        $this->assertSame(
            ['selectedOptionId' => 'option-a'],
            $validated['answerKey']['fundamentals-01']['response'],
        );
        $this->assertSame($this->validFixture()['assets'], $validated['assets']);
    }

    public function test_it_allows_a_positive_content_version(): void
    {
        $fixture = $this->validFixture();
        $fixture['contentVersion'] = 2;

        $validated = (new LessonAssessmentContentValidator)->validate($fixture);

        $this->assertSame(2, $validated['contentVersion']);
    }

    public function test_it_requires_the_exact_hybrid_policy_and_explicit_grading_modes(): void
    {
        $validated = (new LessonAssessmentContentValidator)->validate($this->validFixture());

        $this->assertSame([
            'schemaVersion' => 2,
            'mode' => 'hybrid',
            'automaticWeightBps' => 7000,
            'manualWeightBps' => 3000,
            'automaticGateOverallRequiredBps' => 8000,
            'automaticGateSectionRequiredBps' => 6000,
            'manualRequiredBps' => 6000,
            'finalRequiredBps' => 8000,
        ], $validated['scoringPolicy']);
        $this->assertSame(['automatic', 'manual'], array_column($validated['questions'], 'gradingMode'));
    }

    public function test_it_rejects_an_unknown_policy_key_and_a_missing_manual_mode(): void
    {
        $unknownPolicyKey = $this->validFixture();
        $unknownPolicyKey['scoringPolicy']['unexpected'] = true;

        $missingManualMode = $this->validFixture();
        $missingManualMode['questions'][1]['gradingMode'] = 'automatic';

        foreach ([
            'unknown policy key' => ['scoringPolicy', $unknownPolicyKey],
            'missing manual question' => ['questions', $missingManualMode],
        ] as [$path, $fixture]) {
            try {
                (new LessonAssessmentContentValidator)->validate($fixture);
                $this->fail("{$path} should be rejected.");
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString($path, $exception->getMessage());
            }
        }
    }

    public function test_it_rejects_an_empty_or_oversized_question_set(): void
    {
        foreach ([0, LessonAssessmentContentValidator::MAX_QUESTIONS + 1] as $questionCount) {
            $fixture = $this->validFixture();
            $fixture['questions'] = [];
            $fixture['answerKey'] = [];

            for ($number = 1; $number <= $questionCount; $number++) {
                $question = $this->validFixture()['questions'][0];
                $question['id'] = sprintf('question-%03d', $number);
                $fixture['questions'][] = $question;
                $fixture['answerKey'][$question['id']] = [
                    'response' => ['selectedOptionId' => 'option-a'],
                    'source' => ['page' => 1, 'item' => (string) $number],
                ];
            }

            try {
                (new LessonAssessmentContentValidator)->validate($fixture);
                $this->fail("Question count {$questionCount} should be rejected.");
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('questions', $exception->getMessage());
            }
        }
    }

    public function test_it_accepts_webp_assets_when_extension_matches_mime_type(): void
    {
        $fixture = $this->validFixture();
        $fixture['assets'][0]['sourcePath'] = 'diagrams/asset-01.webp';
        $fixture['assets'][0]['mimeType'] = 'image/webp';

        $validated = (new LessonAssessmentContentValidator)->validate($fixture);

        $this->assertSame('image/webp', $validated['assets'][0]['mimeType']);
        $this->assertSame('diagrams/asset-01.webp', $validated['assets'][0]['sourcePath']);
    }

    public function test_it_rejects_asset_extension_and_mime_type_mismatches(): void
    {
        $fixture = $this->validFixture();
        $fixture['assets'][0]['mimeType'] = 'image/webp';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('assets[0].sourcePath');

        (new LessonAssessmentContentValidator)->validate($fixture);
    }

    public function test_it_rejects_unsupported_asset_mime_types(): void
    {
        $fixture = $this->validFixture();
        $fixture['assets'][0]['sourcePath'] = 'diagrams/asset-01.jpg';
        $fixture['assets'][0]['mimeType'] = 'image/jpeg';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('assets[0].mimeType');

        (new LessonAssessmentContentValidator)->validate($fixture);
    }

    public function test_it_allows_a_diagram_name_without_a_callout(): void
    {
        $validated = (new LessonAssessmentContentValidator)->validate($this->diagramFixture());

        $this->assertSame('name', $validated['questions'][0]['parts'][0]['id']);
        $this->assertSame([
            ['partId' => 'label-01', 'x' => 0.25, 'y' => 0.75],
        ], $validated['questions'][0]['callouts']);
    }

    public function test_it_allows_a_diagram_without_a_general_name_part(): void
    {
        $fixture = $this->diagramFixture();
        array_shift($fixture['questions'][0]['parts']);
        unset($fixture['answerKey']['diagram-01']['response']['selections']['name']);

        $validated = (new LessonAssessmentContentValidator)->validate($fixture);

        $this->assertSame('label-01', $validated['questions'][0]['parts'][0]['id']);
    }

    public function test_it_rejects_choice_and_diagram_questions_without_graded_parts(): void
    {
        foreach (['slot_choice', 'diagram_labels'] as $type) {
            $fixture = $this->diagramFixture();
            $fixture['questions'][0]['type'] = $type;
            $fixture['questions'][0]['parts'] = [];
            $fixture['questions'][0]['callouts'] = [];
            $fixture['answerKey']['diagram-01']['response']['selections'] = [];
            if ($type === 'slot_choice') {
                unset($fixture['questions'][0]['assetKey'], $fixture['questions'][0]['callouts']);
            }

            try {
                (new LessonAssessmentContentValidator)->validate($fixture);
                $this->fail("{$type} without graded parts should be rejected.");
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('questions[0].parts', $exception->getMessage());
            }
        }
    }

    public function test_it_accepts_a_diagram_field_anchored_below_its_target(): void
    {
        $fixture = $this->diagramFixture();
        $fixture['questions'][0]['callouts'][0] += [
            'labelX' => 0.5,
            'labelY' => 0.72,
            'labelSide' => 'bottom',
            'labelWidth' => 0.24,
            'leaderStyle' => 'straight',
        ];

        $validated = (new LessonAssessmentContentValidator)->validate($fixture);

        $this->assertSame('bottom', $validated['questions'][0]['callouts'][0]['labelSide']);
    }

    public function test_it_accepts_a_centered_diagram_field_anchored_above_its_target(): void
    {
        $fixture = $this->diagramFixture();
        $fixture['questions'][0]['callouts'][0] += [
            'labelX' => 0.5,
            'labelY' => 0.18,
            'labelSide' => 'top',
            'labelWidth' => 0.24,
            'leaderStyle' => 'straight',
        ];

        $validated = (new LessonAssessmentContentValidator)->validate($fixture);

        $this->assertSame('top', $validated['questions'][0]['callouts'][0]['labelSide']);
    }

    public function test_it_accepts_multiple_uniquely_identified_targets_for_one_diagram_part(): void
    {
        $fixture = $this->diagramFixture();
        $fixture['questions'][0]['callouts'] = [
            [
                'targetId' => 'label-01-1',
                'partId' => 'label-01',
                'x' => 0.25,
                'y' => 0.75,
                'labelX' => 0.2,
                'labelY' => 0.75,
                'leaderStyle' => 'straight',
            ],
            [
                'targetId' => 'label-01-2',
                'partId' => 'label-01',
                'x' => 0.35,
                'y' => 0.65,
            ],
        ];

        $validated = (new LessonAssessmentContentValidator)->validate($fixture);

        $this->assertSame($fixture['questions'][0]['callouts'], $validated['questions'][0]['callouts']);
    }

    public function test_it_rejects_invalid_diagram_leader_styles(): void
    {
        $fixture = $this->diagramFixture();
        $fixture['questions'][0]['callouts'][0] += [
            'labelX' => 0.2,
            'labelY' => 0.75,
            'leaderStyle' => 'curved',
        ];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('questions[0].callouts[0].leaderStyle');

        (new LessonAssessmentContentValidator)->validate($fixture);
    }

    public function test_it_accepts_point_and_arrow_diagram_target_markers(): void
    {
        foreach (['point', 'arrow'] as $targetMarker) {
            $fixture = $this->diagramFixture();
            $fixture['questions'][0]['callouts'][0]['targetMarker'] = $targetMarker;

            $validated = (new LessonAssessmentContentValidator)->validate($fixture);

            $this->assertSame($targetMarker, $validated['questions'][0]['callouts'][0]['targetMarker']);
        }
    }

    public function test_it_rejects_invalid_diagram_target_markers(): void
    {
        $fixture = $this->diagramFixture();
        $fixture['questions'][0]['callouts'][0]['targetMarker'] = 'area';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('questions[0].callouts[0].targetMarker');

        (new LessonAssessmentContentValidator)->validate($fixture);
    }

    public function test_it_rejects_ambiguous_or_duplicate_diagram_target_ids(): void
    {
        $withoutTargetIds = $this->diagramFixture();
        $withoutTargetIds['questions'][0]['callouts'][] = [
            'partId' => 'label-01',
            'x' => 0.35,
            'y' => 0.65,
        ];

        try {
            (new LessonAssessmentContentValidator)->validate($withoutTargetIds);
            $this->fail('Repeated diagram parts without targetId must be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('questions[0].callouts[1].targetId', $exception->getMessage());
        }

        $duplicateTargetId = $this->diagramFixture();
        $duplicateTargetId['questions'][0]['callouts'] = [
            [
                'targetId' => 'label-01-1',
                'partId' => 'label-01',
                'x' => 0.25,
                'y' => 0.75,
            ],
            [
                'targetId' => 'label-01-1',
                'partId' => 'label-01',
                'x' => 0.35,
                'y' => 0.65,
            ],
        ];

        try {
            (new LessonAssessmentContentValidator)->validate($duplicateTargetId);
            $this->fail('Duplicate diagram targetId values must be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('questions[0].callouts[1].targetId', $exception->getMessage());
        }
    }

    public function test_it_accepts_an_ordered_answer_that_is_a_full_option_permutation(): void
    {
        $fixture = $this->orderedFixture();
        $fixture['questions'][0]['distractorOptionIds'] = [];

        $validated = (new LessonAssessmentContentValidator)->validate($fixture);

        $this->assertArrayNotHasKey('distractorOptionIds', $validated['questions'][0]);
        $this->assertSame(
            ['selectedOptionIds' => ['option-c', 'option-a', 'option-b']],
            $validated['answerKey']['expanded-01']['response'],
        );
    }

    public function test_it_accepts_typed_fields_diagrams_and_long_text_without_public_answers(): void
    {
        $fixture = $this->validFixture();
        $fixture['contentVersion'] = 2;
        $fixture['questions'] = [
            [
                'id' => 'fill-01',
                'sectionId' => 'section-01',
                'gradingMode' => 'automatic',
                'type' => 'text_fields',
                'prompt' => 'Заполните строки.',
                'source' => ['page' => 1, 'item' => '1'],
                'parts' => [
                    ['id' => 'p01', 'prompt' => 'Строка 1'],
                    ['id' => 'p02', 'prompt' => 'Строка 2'],
                ],
            ],
            [
                'id' => 'diagram-01',
                'sectionId' => 'section-01',
                'gradingMode' => 'automatic',
                'type' => 'diagram_text',
                'prompt' => 'Подпишите схему.',
                'source' => ['page' => 1, 'item' => '2'],
                'assetKey' => 'asset-01',
                'parts' => [
                    ['id' => 'name', 'prompt' => 'Название'],
                    ['id' => 'p01', 'prompt' => 'Стрелка 1'],
                ],
                'callouts' => [[
                    'partId' => 'p01',
                    'x' => 0.25,
                    'y' => 0.75,
                    'labelX' => 0.65,
                    'labelY' => 0.75,
                    'labelSide' => 'left',
                    'labelWidth' => 0.24,
                ]],
            ],
            [
                'id' => 'expanded-01',
                'sectionId' => 'section-01',
                'gradingMode' => 'manual',
                'type' => 'long_text',
                'prompt' => 'Ответьте развёрнуто.',
                'source' => ['page' => 1, 'item' => '3'],
                'rows' => 5,
            ],
        ];
        $fixture['answerKey'] = [
            'fill-01' => [
                'response' => [
                    'acceptedTexts' => [
                        'p01' => ['Первый термин'],
                        'p02' => ['Второй термин', 'Допустимый вариант'],
                    ],
                    'unorderedPartGroups' => [['p01', 'p02']],
                ],
                'source' => ['page' => 1, 'item' => '1'],
            ],
            'diagram-01' => [
                'response' => ['acceptedTexts' => [
                    'name' => ['Название'],
                    'p01' => ['Подпись'],
                ]],
                'source' => ['page' => 1, 'item' => '2'],
            ],
            'expanded-01' => [
                'response' => [
                    'requiredFragments' => [
                        ['обязательный фрагмент', 'допустимый эквивалент'],
                        ['второй фрагмент'],
                    ],
                    'referenceAnswers' => ['Полный эталонный ответ.'],
                ],
                'source' => ['page' => 1, 'item' => '3'],
            ],
        ];

        $validated = (new LessonAssessmentContentValidator)->validate($fixture);

        $this->assertSame(['text_fields', 'diagram_text', 'long_text'], array_column($validated['questions'], 'type'));
        $this->assertSame([
            'partId' => 'p01',
            'x' => 0.25,
            'y' => 0.75,
            'labelX' => 0.65,
            'labelY' => 0.75,
            'labelSide' => 'left',
            'labelWidth' => 0.24,
        ], $validated['questions'][1]['callouts'][0]);
        $this->assertSame(
            [['p01', 'p02']],
            $validated['answerKey']['fill-01']['response']['unorderedPartGroups'],
        );
        $this->assertArrayNotHasKey('acceptedTexts', $validated['questions'][0]);
        $this->assertArrayNotHasKey('requiredFragments', $validated['questions'][2]);
    }

    #[DataProvider('invalidFixtureProvider')]
    public function test_it_rejects_invalid_authoring_contracts(string $expectedPath, array $fixture): void
    {
        try {
            (new LessonAssessmentContentValidator)->validate($fixture);
            $this->fail('Expected the fixture to be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString($expectedPath, $exception->getMessage());
        }
    }

    public function test_it_validates_sparse_suggestion_aliases_for_text_fields(): void
    {
        $fixture = $this->unorderedTextFixture();
        $fixture['answerKey']['fill-01']['response']['suggestionAliases'] = [
            'p02' => ['Двухосный'],
        ];

        $validated = (new LessonAssessmentContentValidator)->validate($fixture);

        $this->assertSame(
            ['p02' => ['Двухосный']],
            $validated['answerKey']['fill-01']['response']['suggestionAliases'],
        );
    }

    public function test_it_rejects_suggestion_aliases_for_unknown_parts(): void
    {
        $fixture = $this->unorderedTextFixture();
        $fixture['answerKey']['fill-01']['response']['suggestionAliases'] = [
            'unknown' => ['Двухосный'],
        ];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('answerKey.fill-01.response.suggestionAliases.unknown');

        (new LessonAssessmentContentValidator)->validate($fixture);
    }

    public function test_it_allows_normalized_variants_within_the_same_unordered_part(): void
    {
        $fixture = $this->unorderedTextFixture();
        $fixture['answerKey']['fill-01']['response']['acceptedTexts']['p01'] = [
            'Трёхосный',
            'Трехосный',
        ];

        $validated = (new LessonAssessmentContentValidator)->validate($fixture);

        $this->assertSame(
            ['Трёхосный', 'Трехосный'],
            $validated['answerKey']['fill-01']['response']['acceptedTexts']['p01'],
        );
    }

    public static function invalidFixtureProvider(): array
    {
        $fixture = (new self('test_it_validates_and_sanitizes_a_complete_authoring_fixture'))->validFixture();

        $duplicateQuestionId = $fixture;
        $duplicateQuestionId['questions'][] = $duplicateQuestionId['questions'][0];

        $unknownOption = $fixture;
        $unknownOption['answerKey']['fundamentals-01']['response']['selectedOptionId'] = 'option-missing';

        $missingPart = $fixture;
        $missingPart['questions'][0] = [
            'id' => 'fill-01',
            'sectionId' => 'section-01',
            'gradingMode' => 'automatic',
            'type' => 'slot_choice',
            'prompt' => 'Заполните пропуск.',
            'source' => ['page' => 1, 'item' => '1'],
            'options' => [
                ['id' => 'option-a', 'label' => 'Вариант A'],
                ['id' => 'option-b', 'label' => 'Вариант B'],
            ],
            'parts' => [
                ['id' => 'part-01', 'prompt' => 'Пропуск', 'optionIds' => ['option-a']],
            ],
        ];
        unset($missingPart['answerKey']['fundamentals-01']);
        $missingPart['answerKey']['fill-01'] = [
            'response' => ['selections' => []],
            'source' => ['page' => 1, 'item' => '1'],
        ];

        $invalidResponse = $fixture;
        $invalidResponse['answerKey']['fundamentals-01']['response'] = ['selectedOptionIds' => ['option-a']];

        $invalidAssetHash = $fixture;
        $invalidAssetHash['assets'][0]['sha256'] = 'not-a-hash';

        $correctnessInQuestion = $fixture;
        $correctnessInQuestion['questions'][0]['options'][0]['isCorrect'] = true;

        $missingSourcePage = $fixture;
        $missingSourcePage['questions'][0]['source']['page'] = 10;

        $wrongSubject = $fixture;
        $wrongSubject['lesson']['subjectSlug'] = 'drugoy-predmet';

        $wrongCourse = $fixture;
        $wrongCourse['lesson']['courseSlug'] = 'drugoy-kurs';

        $wrongLesson = $fixture;
        $wrongLesson['lesson']['lessonSlug'] = 'drugoy-urok';

        $wrongAssignmentHash = $fixture;
        $wrongAssignmentHash['sources']['assignmentSha256'] = str_repeat('a', 64);

        $wrongKeyHash = $fixture;
        $wrongKeyHash['sources']['keySha256'] = str_repeat('b', 64);

        $nonPositiveContentVersion = $fixture;
        $nonPositiveContentVersion['contentVersion'] = 0;

        $orderedMultipleChoice = $fixture;
        $orderedMultipleChoice['questions'][0]['type'] = 'multiple_choice';
        $orderedMultipleChoice['questions'][0]['orderMatters'] = true;
        $orderedMultipleChoice['answerKey']['fundamentals-01']['response'] = [
            'selectedOptionIds' => ['option-a'],
        ];

        $diagramWithoutLabelCallout = (new self('test_it_validates_and_sanitizes_a_complete_authoring_fixture'))->diagramFixture();
        $diagramWithoutLabelCallout['questions'][0]['callouts'] = [];

        $shortOrderedAnswer = (new self('test_it_validates_and_sanitizes_a_complete_authoring_fixture'))->orderedFixture();
        $shortOrderedAnswer['answerKey']['expanded-01']['response']['selectedOptionIds'] = ['option-a', 'option-b'];

        $supersetOrderedAnswer = (new self('test_it_validates_and_sanitizes_a_complete_authoring_fixture'))->orderedFixture();
        $supersetOrderedAnswer['answerKey']['expanded-01']['response']['selectedOptionIds'][] = 'option-foreign';

        $duplicateOrderedAnswer = (new self('test_it_validates_and_sanitizes_a_complete_authoring_fixture'))->orderedFixture();
        $duplicateOrderedAnswer['answerKey']['expanded-01']['response']['selectedOptionIds'] = [
            'option-a',
            'option-b',
            'option-b',
        ];

        $foreignOrderedAnswer = (new self('test_it_validates_and_sanitizes_a_complete_authoring_fixture'))->orderedFixture();
        $foreignOrderedAnswer['answerKey']['expanded-01']['response']['selectedOptionIds'] = [
            'option-a',
            'option-b',
            'option-foreign',
        ];

        $orderedDistractorSubset = (new self('test_it_validates_and_sanitizes_a_complete_authoring_fixture'))->orderedFixture();
        $orderedDistractorSubset['questions'][0]['distractorOptionIds'] = ['option-c'];

        $unknownUnorderedPart = (new self('test_it_validates_and_sanitizes_a_complete_authoring_fixture'))->unorderedTextFixture();
        $unknownUnorderedPart['answerKey']['fill-01']['response']['unorderedPartGroups'] = [
            ['p01', 'missing'],
        ];

        $repeatedUnorderedPart = (new self('test_it_validates_and_sanitizes_a_complete_authoring_fixture'))->unorderedTextFixture();
        $repeatedUnorderedPart['answerKey']['fill-01']['response']['unorderedPartGroups'] = [
            ['p01', 'p02'],
            ['p02', 'p03'],
        ];

        $ambiguousUnorderedAnswer = (new self('test_it_validates_and_sanitizes_a_complete_authoring_fixture'))->unorderedTextFixture();
        $ambiguousUnorderedAnswer['answerKey']['fill-01']['response']['acceptedTexts']['p02'] = ['Первый термин!'];

        return [
            'duplicate question IDs' => ['questions[2].id', $duplicateQuestionId],
            'unknown option ID in answer key' => ['answerKey.fundamentals-01.response.selectedOptionId', $unknownOption],
            'missing part in response' => ['answerKey.fill-01.response.selections', $missingPart],
            'wrong response shape' => ['answerKey.fundamentals-01.response', $invalidResponse],
            'asset without SHA-256' => ['assets[0].sha256', $invalidAssetHash],
            'correctness flag in student question' => ['questions[0].options[0].isCorrect', $correctnessInQuestion],
            'nonexistent source page' => ['questions[0].source.page', $missingSourcePage],
            'unexpected subject identity' => ['lesson.subjectSlug', $wrongSubject],
            'unexpected course identity' => ['lesson.courseSlug', $wrongCourse],
            'unexpected lesson identity' => ['lesson.lessonSlug', $wrongLesson],
            'unexpected assignment source hash' => ['sources.assignmentSha256', $wrongAssignmentHash],
            'unexpected key source hash' => ['sources.keySha256', $wrongKeyHash],
            'non-positive content version' => ['contentVersion', $nonPositiveContentVersion],
            'ordered multiple choice' => ['questions[0].orderMatters', $orderedMultipleChoice],
            'diagram without callout for a non-name part' => ['questions[0].callouts', $diagramWithoutLabelCallout],
            'short ordered answer' => ['answerKey.expanded-01.response.selectedOptionIds', $shortOrderedAnswer],
            'superset ordered answer' => ['answerKey.expanded-01.response.selectedOptionIds[3]', $supersetOrderedAnswer],
            'duplicate ordered answer' => ['answerKey.expanded-01.response.selectedOptionIds[2]', $duplicateOrderedAnswer],
            'foreign ordered answer' => ['answerKey.expanded-01.response.selectedOptionIds[2]', $foreignOrderedAnswer],
            'ordered distractor subset' => ['questions[0].distractorOptionIds', $orderedDistractorSubset],
            'unknown part in unordered group' => [
                'answerKey.fill-01.response.unorderedPartGroups[0][1]',
                $unknownUnorderedPart,
            ],
            'repeated part across unordered groups' => [
                'answerKey.fill-01.response.unorderedPartGroups[1][0]',
                $repeatedUnorderedPart,
            ],
            'ambiguous normalized unordered answer' => [
                'answerKey.fill-01.response.acceptedTexts.p02[0]',
                $ambiguousUnorderedAnswer,
            ],
        ];
    }

    private function unorderedTextFixture(): array
    {
        $fixture = $this->validFixture();
        $fixture['questions'][0] = [
            'id' => 'fill-01',
            'sectionId' => 'section-01',
            'gradingMode' => 'automatic',
            'type' => 'text_fields',
            'prompt' => 'Перечислите термины.',
            'source' => ['page' => 1, 'item' => '1'],
            'parts' => [
                ['id' => 'p01', 'prompt' => 'Строка 1'],
                ['id' => 'p02', 'prompt' => 'Строка 2'],
                ['id' => 'p03', 'prompt' => 'Строка 3'],
            ],
        ];
        $fixture['answerKey'] = [
            'fill-01' => [
                'response' => [
                    'acceptedTexts' => [
                        'p01' => ['Первый термин'],
                        'p02' => ['Второй термин'],
                        'p03' => ['Третий термин'],
                    ],
                    'unorderedPartGroups' => [['p01', 'p02', 'p03']],
                ],
                'source' => ['page' => 1, 'item' => '1'],
            ],
            'fill-02' => [
                'response' => ['selectedOptionId' => 'option-a'],
                'source' => ['page' => 1, 'item' => '2'],
            ],
        ];

        return $fixture;
    }

    private function orderedFixture(): array
    {
        $fixture = $this->validFixture();
        $fixture['questions'][0] = [
            'id' => 'expanded-01',
            'sectionId' => 'section-01',
            'gradingMode' => 'automatic',
            'type' => 'ordered_choice',
            'prompt' => 'Расположите варианты по порядку.',
            'source' => ['page' => 1, 'item' => '1'],
            'options' => [
                ['id' => 'option-a', 'label' => 'Вариант A'],
                ['id' => 'option-b', 'label' => 'Вариант B'],
                ['id' => 'option-c', 'label' => 'Вариант C'],
            ],
        ];
        $fixture['answerKey'] = [
            'expanded-01' => [
                'response' => ['selectedOptionIds' => ['option-c', 'option-a', 'option-b']],
                'source' => ['page' => 1, 'item' => '1'],
            ],
            'fill-02' => [
                'response' => ['selectedOptionId' => 'option-a'],
                'source' => ['page' => 1, 'item' => '2'],
            ],
        ];

        return $fixture;
    }

    private function diagramFixture(): array
    {
        $fixture = $this->validFixture();
        $fixture['questions'][0] = [
            'id' => 'diagram-01',
            'sectionId' => 'section-01',
            'gradingMode' => 'automatic',
            'type' => 'diagram_labels',
            'prompt' => 'Подпишите схему.',
            'source' => ['page' => 1, 'item' => '1'],
            'assetKey' => 'asset-01',
            'options' => [
                ['id' => 'option-a', 'label' => 'Название'],
                ['id' => 'option-b', 'label' => 'Подпись'],
                ['id' => 'option-c', 'label' => 'Дистрактор'],
            ],
            'parts' => [
                ['id' => 'name', 'prompt' => 'Название изображения', 'optionIds' => ['option-a', 'option-c']],
                ['id' => 'label-01', 'prompt' => 'Стрелка 1', 'optionIds' => ['option-b', 'option-c']],
            ],
            'callouts' => [
                ['partId' => 'label-01', 'x' => 0.25, 'y' => 0.75],
            ],
            'distractorOptionIds' => ['option-c'],
        ];
        $fixture['answerKey'] = [
            'diagram-01' => [
                'response' => [
                    'selections' => [
                        'name' => 'option-a',
                        'label-01' => 'option-b',
                    ],
                ],
                'source' => ['page' => 1, 'item' => '1'],
            ],
            'fill-02' => [
                'response' => ['selectedOptionId' => 'option-a'],
                'source' => ['page' => 1, 'item' => '2'],
            ],
        ];

        return $fixture;
    }

    private function validFixture(): array
    {
        return [
            'schemaVersion' => 2,
            'contentVersion' => 1,
            'scoringPolicy' => [
                'schemaVersion' => 2,
                'mode' => 'hybrid',
                'automaticWeightBps' => 7000,
                'manualWeightBps' => 3000,
                'automaticGateOverallRequiredBps' => 8000,
                'automaticGateSectionRequiredBps' => 6000,
                'manualRequiredBps' => 6000,
                'finalRequiredBps' => 8000,
            ],
            'lesson' => [
                'subjectSlug' => 'anatomiya',
                'courseSlug' => 'artrologiya',
                'lessonSlug' => 'soedineniya-kostej-tulovishcha-i-cherepa',
            ],
            'sources' => [
                'assignmentSha256' => '9f32f66d9d8957061a1e0738a7adc444f9a850b1e3da1235716c51317a9def87',
                'keySha256' => '9b28fdad5337337fc2edd5689bfdaec63765b82b6a726b815c23f7c0466ff6d7',
            ],
            'sections' => [
                ['id' => 'section-01', 'title' => 'Основы'],
            ],
            'questions' => [
                [
                    'id' => 'fundamentals-01',
                    'sectionId' => 'section-01',
                    'gradingMode' => 'automatic',
                    'type' => 'single_choice',
                    'prompt' => 'Выберите правильный вариант.',
                    'source' => ['page' => 1, 'item' => '1'],
                    'options' => [
                        ['id' => 'option-a', 'label' => 'Вариант A'],
                        ['id' => 'option-b', 'label' => 'Вариант B'],
                    ],
                    'distractorOptionIds' => ['option-b'],
                ],
                [
                    'id' => 'fill-02',
                    'sectionId' => 'section-01',
                    'gradingMode' => 'manual',
                    'type' => 'single_choice',
                    'prompt' => 'Опишите ответ для ручной проверки.',
                    'source' => ['page' => 1, 'item' => '2'],
                    'options' => [
                        ['id' => 'option-a', 'label' => 'Вариант A'],
                        ['id' => 'option-b', 'label' => 'Вариант B'],
                    ],
                ],
            ],
            'answerKey' => [
                'fundamentals-01' => [
                    'response' => ['selectedOptionId' => 'option-a'],
                    'source' => ['page' => 1, 'item' => '1'],
                ],
                'fill-02' => [
                    'response' => ['selectedOptionId' => 'option-a'],
                    'source' => ['page' => 1, 'item' => '2'],
                ],
            ],
            'assets' => [
                [
                    'assetKey' => 'asset-01',
                    'sourcePath' => 'diagrams/asset-01.png',
                    'sha256' => '9f32f66d9d8957061a1e0738a7adc444f9a850b1e3da1235716c51317a9def87',
                    'mimeType' => 'image/png',
                    'width' => 800,
                    'height' => 600,
                    'sourcePage' => 1,
                    'cropBox' => ['left' => 0, 'top' => 0, 'right' => 1, 'bottom' => 1],
                ],
            ],
        ];
    }
}
