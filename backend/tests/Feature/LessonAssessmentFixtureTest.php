<?php

namespace Tests\Feature;

use App\Models\LessonAssessmentVersion;
use App\Services\Learning\LessonAssessmentAnswerChecker;
use App\Services\Learning\LessonAssessmentContentValidator;
use App\Services\Learning\LessonAssessmentSuggestionService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class LessonAssessmentFixtureTest extends TestCase
{
    private const FIXTURE = 'seeders/data/lesson_assessments/soedineniya-kostej-tulovishcha-i-cherepa-v38.json';

    private const PROHIBITED_STUDENT_KEYS = [
        'answerKey',
        'answer',
        'correctAnswer',
        'correctOptionId',
        'correctOptionIds',
        'correctResponse',
        'distractorOptionIds',
        'isCorrect',
        'correctness',
        'solution',
    ];

    private const EXPECTED_IDS = [
        'fundamentals-01',
        'fundamentals-02',
        'fundamentals-03',
        'fundamentals-04',
        'fundamentals-05',
        'fundamentals-06',
        'fundamentals-07',
        'fundamentals-08',
        'fundamentals-09',
        'fundamentals-10',
        'fundamentals-11',
        'fill-01',
        'fill-02',
        'fill-03',
        'fill-04',
        'fill-05',
        'fill-06',
        'fill-07',
        'fill-08',
        'fill-09',
        'fill-10',
        'fill-11',
        'fill-12',
        'fill-13',
        'fill-14',
        'fill-15',
        'fill-16',
        'fill-17',
        'fill-18',
        'fill-19',
        'fill-20',
        'fill-21',
        'diagram-01',
        'diagram-02',
        'diagram-03',
        'diagram-04',
        'diagram-05',
        'diagram-06',
        'diagram-07',
        'diagram-08',
        'diagram-09',
        'expanded-01',
        'expanded-02',
        'expanded-03',
        'expanded-04',
        'expanded-05',
        'expanded-06',
        'expanded-07',
    ];

    private const EXPECTED_ASSETS = [
        [
            'assetKey' => 'diagram-01',
            'sourcePath' => 'lesson_assessments/soedineniya-kostej-tulovishcha-i-cherepa/v1/diagram-01-interactive.webp',
            'sha256' => 'eb2a31758e58ae7037798dbbfbe787e3b40c078b9501f0ef66f41ded56b407a7',
            'mimeType' => 'image/webp',
            'width' => 1900,
            'height' => 1323,
            'sourcePage' => 4,
            'cropBox' => ['left' => 0.302, 'top' => 0.114, 'right' => 0.706, 'bottom' => 0.370],
        ],
        [
            'assetKey' => 'diagram-02',
            'sourcePath' => 'lesson_assessments/soedineniya-kostej-tulovishcha-i-cherepa/v1/diagram-02-interactive.webp',
            'sha256' => '74a4505c4f96b4e91cc1676a190911089c41025cae6de95d75b2ea2aff1e9500',
            'mimeType' => 'image/webp',
            'width' => 1503,
            'height' => 1047,
            'sourcePage' => 4,
            'cropBox' => ['left' => 0.302, 'top' => 0.385, 'right' => 0.726, 'bottom' => 0.776],
        ],
        [
            'assetKey' => 'diagram-03',
            'sourcePath' => 'lesson_assessments/soedineniya-kostej-tulovishcha-i-cherepa/v1/diagram-03-interactive.webp',
            'sha256' => 'c9ed62d1f6dcf460169a4ffcbc0adc83dccfe268f1124e8b7c846bf0c71c5b5c',
            'mimeType' => 'image/webp',
            'width' => 1800,
            'height' => 1500,
            'sourcePage' => 5,
            'cropBox' => ['left' => 0.292, 'top' => 0.057, 'right' => 0.716, 'bottom' => 0.449],
        ],
        [
            'assetKey' => 'diagram-04',
            'sourcePath' => 'lesson_assessments/soedineniya-kostej-tulovishcha-i-cherepa/v1/diagram-04-interactive.webp',
            'sha256' => '4ab291979b54fc33aa756317b6e0f95202d7f391c0b862e982528414a9250cca',
            'mimeType' => 'image/webp',
            'width' => 1700,
            'height' => 1400,
            'sourcePage' => 5,
            'cropBox' => ['left' => 0.272, 'top' => 0.456, 'right' => 0.786, 'bottom' => 0.900],
        ],
        [
            'assetKey' => 'diagram-05',
            'sourcePath' => 'lesson_assessments/soedineniya-kostej-tulovishcha-i-cherepa/v1/diagram-05-interactive.webp',
            'sha256' => 'a9ac397630b021065654c4f17c356e9bded236034b8809f0d649205fa2e9f37e',
            'mimeType' => 'image/webp',
            'width' => 1800,
            'height' => 1100,
            'sourcePage' => 6,
            'cropBox' => ['left' => 0.161, 'top' => 0.064, 'right' => 0.847, 'bottom' => 0.420],
        ],
        [
            'assetKey' => 'diagram-06',
            'sourcePath' => 'lesson_assessments/soedineniya-kostej-tulovishcha-i-cherepa/v1/diagram-06-interactive.webp',
            'sha256' => '208ff88b3c61ec450854d4393a4e510bf2a19d4a6ea8b950ad2d65d557cea6a8',
            'mimeType' => 'image/webp',
            'width' => 1700,
            'height' => 1050,
            'sourcePage' => 6,
            'cropBox' => ['left' => 0.222, 'top' => 0.413, 'right' => 0.796, 'bottom' => 0.755],
        ],
        [
            'assetKey' => 'diagram-07',
            'sourcePath' => 'lesson_assessments/soedineniya-kostej-tulovishcha-i-cherepa/v1/diagram-07-interactive.webp',
            'sha256' => '1d08d5a1ba1078b3b0998a04665e9695d3694908252574d896dd844d7b954dc5',
            'mimeType' => 'image/webp',
            'width' => 2048,
            'height' => 819,
            'sourcePage' => 7,
            'cropBox' => ['left' => 0.101, 'top' => 0.093, 'right' => 0.907, 'bottom' => 0.377],
        ],
        [
            'assetKey' => 'diagram-08',
            'sourcePath' => 'lesson_assessments/soedineniya-kostej-tulovishcha-i-cherepa/v1/diagram-08-interactive.webp',
            'sha256' => '8e3ca72045929367c552032219a4d573b11a39f41445773a9c8535583d131508',
            'mimeType' => 'image/webp',
            'width' => 1800,
            'height' => 1350,
            'sourcePage' => 7,
            'cropBox' => ['left' => 0.161, 'top' => 0.370, 'right' => 0.827, 'bottom' => 0.900],
        ],
        [
            'assetKey' => 'diagram-09',
            'sourcePath' => 'lesson_assessments/soedineniya-kostej-tulovishcha-i-cherepa/v1/diagram-09-interactive.webp',
            'sha256' => 'c9bf50b85fd1311c68c2a65315e6436afde55c467d051ce9ffbbe005dd04ec52',
            'mimeType' => 'image/webp',
            'width' => 1800,
            'height' => 1300,
            'sourcePage' => 8,
            'cropBox' => ['left' => 0.192, 'top' => 0.071, 'right' => 0.837, 'bottom' => 0.456],
        ],
    ];

    public function test_fixture_has_canonical_sources_ids_and_section_counts(): void
    {
        $validated = $this->validatedFixture();

        $this->assertSame([
            'assignmentSha256' => '9f32f66d9d8957061a1e0738a7adc444f9a850b1e3da1235716c51317a9def87',
            'keySha256' => '9b28fdad5337337fc2edd5689bfdaec63765b82b6a726b815c23f7c0466ff6d7',
        ], $validated['sources']);
        $this->assertSame(self::EXPECTED_IDS, array_column($validated['questions'], 'id'));
        $this->assertCount(48, $validated['questions']);
        $this->assertSame(
            ['fundamentals' => 11, 'fill-blanks' => 21, 'diagrams' => 9, 'expanded' => 7],
            collect($validated['questions'])->countBy('sectionId')->all(),
        );
        $this->assertSame(self::EXPECTED_IDS, array_keys($validated['answerKey']));
    }

    public function test_fixture_declares_the_approved_hybrid_policy_and_explicit_modes(): void
    {
        $fixture = $this->validatedFixture();

        $this->assertSame(2, $fixture['schemaVersion']);
        $this->assertSame(38, $fixture['contentVersion']);
        $this->assertSame(
            'Что такое роднички и каково их значение?',
            collect($fixture['questions'])->firstWhere('id', 'expanded-07')['prompt'],
        );
        $this->assertSame([
            'schemaVersion' => 2,
            'mode' => 'hybrid',
            'automaticWeightBps' => 7000,
            'manualWeightBps' => 3000,
            'automaticGateOverallRequiredBps' => 8000,
            'automaticGateSectionRequiredBps' => 6000,
            'manualRequiredBps' => 6000,
            'finalRequiredBps' => 8000,
        ], $fixture['scoringPolicy']);
        $this->assertSame(7, collect($fixture['questions'])->where('gradingMode', 'manual')->count());
        $this->assertSame(41, collect($fixture['questions'])->where('gradingMode', 'automatic')->count());

        $questions = collect($fixture['questions']);
        $this->assertSame(
            [
                'expanded-01',
                'expanded-02',
                'expanded-03',
                'expanded-04',
                'expanded-05',
                'expanded-06',
                'expanded-07',
            ],
            $questions->where('gradingMode', 'manual')->pluck('id')->values()->all(),
        );
        $this->assertSame(
            [],
            $questions
                ->where('sectionId', 'fill-blanks')
                ->where('gradingMode', 'manual')
                ->pluck('id')
                ->values()
                ->all(),
        );
    }

    public function test_fixture_matches_the_pdf_answer_shapes_exactly(): void
    {
        $questions = collect($this->validatedFixture()['questions']);

        $this->assertSame(2, $questions->where('type', 'single_choice')->count());
        $this->assertSame(30, $questions->where('type', 'text_fields')->count());
        $this->assertSame(9, $questions->where('type', 'diagram_text')->count());
        $this->assertSame(7, $questions->where('type', 'long_text')->count());
        $this->assertSame(0, $questions->whereIn('type', [
            'multiple_choice',
            'slot_choice',
            'ordered_choice',
            'diagram_labels',
        ])->count());

        $typedFieldCount = $questions
            ->whereIn('type', ['text_fields', 'diagram_text'])
            ->sum(fn (array $question): int => count($question['parts']));
        $this->assertSame(143, $typedFieldCount);
        $this->assertCount(5, $questions->firstWhere('id', 'fundamentals-01')['parts']);
        $this->assertCount(15, $questions->firstWhere('id', 'fundamentals-03')['parts']);
        $this->assertSame(
            [3, 3, 5, 3, 3, 6, 3],
            $questions->where('type', 'long_text')->pluck('rows')->values()->all(),
        );
    }

    public function test_fundamentals_seven_preserves_the_source_option_order(): void
    {
        $fixture = $this->validatedFixture();
        $question = collect($fixture['questions'])->firstWhere('id', 'fundamentals-07');

        $this->assertSame(
            ['Венечный', 'Лямбдовидный', 'Чешуйчатый'],
            array_column($question['options'], 'label'),
        );
        $this->assertSame(
            'o1',
            $fixture['answerKey']['fundamentals-07']['response']['selectedOptionId'],
        );
    }

    public function test_fixture_marks_every_multi_field_enumeration_with_exact_unordered_groups(): void
    {
        $answerKey = $this->validatedFixture()['answerKey'];
        $groups = [];
        foreach ($answerKey as $questionId => $entry) {
            if (array_key_exists('unorderedPartGroups', $entry['response'])) {
                $groups[$questionId] = $entry['response']['unorderedPartGroups'];
            }
        }

        $this->assertSame([
            'fundamentals-01' => [['p01', 'p02', 'p03', 'p04', 'p05']],
            'fundamentals-03' => [
                ['p01', 'p02', 'p03', 'p04'],
                ['p05', 'p06', 'p07'],
                ['p08', 'p09', 'p10', 'p11', 'p12', 'p13', 'p14', 'p15'],
            ],
            'fundamentals-04' => [['p01', 'p02', 'p03']],
            'fundamentals-05' => [['p01', 'p02', 'p03', 'p04', 'p05']],
            'fundamentals-06' => [['p01', 'p02', 'p03']],
            'fundamentals-08' => [['p01', 'p02', 'p03']],
            'fundamentals-09' => [['p01', 'p02', 'p03', 'p04']],
            'fundamentals-10' => [['p01', 'p02', 'p03']],
            'fundamentals-11' => [['p01', 'p02', 'p03']],
            'fill-04' => [['p1', 'p2']],
            'fill-05' => [['p1', 'p2']],
            'fill-06' => [['p1', 'p2']],
            'fill-07' => [['p1', 'p2']],
            'fill-11' => [['p1', 'p2']],
            'fill-12' => [['p1', 'p2']],
            'fill-15' => [['p1', 'p2', 'p3']],
            'fill-20' => [['p1', 'p2', 'p3']],
        ], $groups);
    }

    public function test_fixture_declares_nine_optimized_webp_assets_with_exact_metadata(): void
    {
        $validated = $this->validatedFixture();

        $this->assertCount(9, $validated['assets']);
        $this->assertSame(self::EXPECTED_ASSETS, $validated['assets']);

        foreach ($validated['assets'] as $asset) {
            $path = database_path('seeders/assets/'.$asset['sourcePath']);
            $this->assertFileExists($path);
            $this->assertSame(hash_file('sha256', $path), $asset['sha256']);
            $this->assertSame('image/webp', File::mimeType($path));

            $dimensions = getimagesize($path);
            $this->assertIsArray($dimensions);
            $this->assertSame($asset['width'], $dimensions[0]);
            $this->assertSame($asset['height'], $dimensions[1]);
        }
    }

    public function test_validated_student_questions_do_not_expose_correctness_metadata(): void
    {
        $questions = $this->validatedFixture()['questions'];
        $studentKeys = $this->recursiveKeys($questions);

        foreach (self::PROHIBITED_STUDENT_KEYS as $prohibitedKey) {
            $this->assertNotContains($prohibitedKey, $studentKeys);
        }
    }

    public function test_every_question_matches_its_answer_source_and_keeps_distractors_disjoint(): void
    {
        $fixture = $this->fixture();
        $validated = app(LessonAssessmentContentValidator::class)->validate($fixture);
        $sourceParityCount = 0;
        $distractorDisjointCount = 0;

        foreach ($fixture['questions'] as $question) {
            $questionId = $question['id'];
            $answer = $validated['answerKey'][$questionId];
            $this->assertSame(
                $question['source'],
                $answer['source'],
                "Question {$questionId} must use the exact answer-key source page and item.",
            );
            $sourceParityCount++;

            $canonicalResponseIds = $this->recursiveStringValues($answer['response']);
            $distractorOptionIds = $question['distractorOptionIds'] ?? [];
            $this->assertSame(
                [],
                array_values(array_intersect($canonicalResponseIds, $distractorOptionIds)),
                "Question {$questionId} must not mark a canonical response ID as a distractor.",
            );
            $distractorDisjointCount++;
        }

        $this->assertSame(48, $sourceParityCount, 'Source parity must cover all 48 questions.');
        $this->assertSame(48, $distractorDisjointCount, 'Distractor disjointness must cover all 48 questions.');
    }

    public function test_diagram_name_callouts_match_the_source_arrows(): void
    {
        $diagrams = collect($this->validatedFixture()['questions'])
            ->where('type', 'diagram_text')
            ->keyBy('id');
        $expectedNameCallouts = [
            'diagram-01' => 0,
            'diagram-02' => 0,
            'diagram-03' => 0,
            'diagram-04' => 0,
            'diagram-05' => 0,
            'diagram-06' => 0,
            'diagram-07' => 0,
            'diagram-08' => 0,
            'diagram-09' => 0,
        ];
        $expectedRepeatedTargets = [
            'diagram-04' => [
                'p03' => ['p03-1', 'p03-2', 'p03-3'],
                'p06' => ['p06-1', 'p06-2'],
            ],
            'diagram-08' => [
                'p01' => ['p01-1', 'p01-2'],
                'p04' => ['p04-1', 'p04-2'],
            ],
        ];

        foreach ($expectedNameCallouts as $questionId => $expectedCount) {
            $question = $diagrams->get($questionId);
            $hasNamePart = collect($question['parts'])->contains(
                fn (array $part): bool => $part['id'] === 'name',
            );
            $this->assertSame(
                ! in_array($questionId, ['diagram-07', 'diagram-08', 'diagram-09'], true),
                $hasNamePart,
            );
            $this->assertCount(
                $expectedCount,
                array_filter(
                    $question['callouts'],
                    fn (array $callout): bool => $callout['partId'] === 'name',
                ),
            );

            foreach (array_values(array_filter(
                $question['parts'],
                fn (array $part): bool => $part['id'] !== 'name',
            )) as $part) {
                $expectedTargetIds = $expectedRepeatedTargets[$questionId][$part['id']] ?? null;
                $partCallouts = array_values(array_filter(
                    $question['callouts'],
                    fn (array $callout): bool => $callout['partId'] === $part['id'],
                ));
                $this->assertCount(
                    $expectedTargetIds === null ? 1 : count($expectedTargetIds),
                    $partCallouts,
                );
                if ($expectedTargetIds !== null) {
                    $this->assertSame($expectedTargetIds, array_column($partCallouts, 'targetId'));
                }
            }

            foreach ($question['callouts'] as $callout) {
                if (array_key_exists('labelX', $callout)) {
                    $this->assertContains($callout['leaderStyle'] ?? null, ['elbow', 'straight']);
                }
            }
        }

        $this->assertCount(67, $diagrams->flatMap(
            fn (array $question): array => $question['callouts'],
        ));
        $this->assertSame([
            'diagram-02/p06',
            'diagram-03/p03',
            'diagram-03/p04',
            'diagram-03/p05',
            'diagram-03/p07',
            'diagram-03/p08',
            'diagram-03/p10',
            'diagram-05/p01',
            'diagram-05/p02',
            'diagram-05/p03',
            'diagram-05/p04',
            'diagram-05/p07',
            'diagram-07/p02',
            'diagram-07/p04',
        ], $diagrams->flatMap(fn (array $question) => collect($question['callouts'])
            ->filter(fn (array $callout): bool => ($callout['targetMarker'] ?? 'point') === 'arrow')
            ->map(fn (array $callout): string => "{$question['id']}/{$callout['partId']}"))
            ->values()
            ->all());
    }

    public function test_diagram_four_shared_right_label_uses_the_middle_target_horizontal(): void
    {
        $diagram = collect($this->validatedFixture()['questions'])
            ->firstWhere('id', 'diagram-04');

        $this->assertIsArray($diagram);
        $partCallouts = collect($diagram['callouts'])
            ->where('partId', 'p03');
        $labelCallout = $partCallouts->first(
            fn (array $callout): bool => array_key_exists('labelY', $callout),
        );
        $middleTarget = $partCallouts->firstWhere('targetId', 'p03-2');

        $this->assertIsArray($labelCallout);
        $this->assertIsArray($middleTarget);
        $this->assertSame($middleTarget['y'], $labelCallout['labelY']);
    }

    public function test_diagram_four_xiphosternal_label_is_below_the_target_with_a_vertical_leader(): void
    {
        $diagram = collect($this->validatedFixture()['questions'])
            ->firstWhere('id', 'diagram-04');

        $this->assertIsArray($diagram);
        $callout = collect($diagram['callouts'])->firstWhere('partId', 'p05');

        $this->assertIsArray($callout);
        $this->assertSame(0.609285, $callout['y']);
        $this->assertSame('bottom', $callout['labelSide']);
        $this->assertSame($callout['x'], $callout['labelX']);
        $this->assertGreaterThan($callout['y'], $callout['labelY']);
        $this->assertSame('straight', $callout['leaderStyle']);
    }

    public function test_diagram_one_callouts_match_the_reviewed_anatomical_targets(): void
    {
        $diagram = collect($this->validatedFixture()['questions'])
            ->firstWhere('id', 'diagram-01');

        $this->assertIsArray($diagram);
        $this->assertSame([
            'p01' => [0.306468, 0.114, 0.55474, 0.114, 'straight'],
            'p02' => [0.312368, 0.463779, 0.55474, 0.221907, 'straight'],
            'p03' => [0.385, 0.465464, 0.55474, 0.332435, 'straight'],
            'p04' => [0.443264, 0.458939, 0.55474, 0.458939, 'straight'],
            'p05' => [0.458318, 0.561663, 0.55474, 0.561663, 'straight'],
            'p06' => [0.463, 0.658, 0.55474, 0.658, 'straight'],
            'p07' => [0.431528, 0.697324, 0.55474, 0.757792, 'straight'],
            'p08' => [0.31016, 0.587512, 0.438954, 0.858106, 'straight'],
        ], collect($diagram['callouts'])->mapWithKeys(fn (array $callout): array => [
            $callout['partId'] => [
                $callout['x'],
                $callout['y'],
                $callout['labelX'],
                $callout['labelY'],
                $callout['leaderStyle'],
            ],
        ])->all());
    }

    public function test_diagram_two_callouts_match_the_current_calibration_and_field_sides(): void
    {
        $diagram = collect($this->validatedFixture()['questions'])
            ->firstWhere('id', 'diagram-02');

        $this->assertIsArray($diagram);
        $this->assertSame([
            'p02' => [0.498, 0.295, 0.410474, 0.12, 'left', 'elbow', 'point'],
            'p01' => [0.520246, 0.244694, 0.552912, 0.12, 'right', 'elbow', 'point'],
            'p04' => [0.473, 0.322, 0.303307, 0.223592, 'left', 'elbow', 'point'],
            'p03' => [0.579019, 0.314552, 0.693365, 0.314552, 'right', 'straight', 'point'],
            'p05' => [0.39, 0.357552, 0.29, 0.357552, 'left', 'straight', 'point'],
            'p06' => [0.35299, 0.48, 0.29, 0.48, 'left', 'straight', 'arrow'],
            'p07' => [0.454346, 0.669344, 0.29, 0.669344, 'left', 'straight', 'point'],
            'p08' => [0.630019, 0.705448, 0.700019, 0.705448, 'right', 'straight', 'point'],
        ], collect($diagram['callouts'])->mapWithKeys(fn (array $callout): array => [
            $callout['partId'] => [
                $callout['x'],
                $callout['y'],
                $callout['labelX'],
                $callout['labelY'],
                $callout['labelSide'],
                $callout['leaderStyle'],
                $callout['targetMarker'] ?? 'point',
            ],
        ])->all());
    }

    public function test_diagram_three_callouts_match_the_current_calibration(): void
    {
        $diagram = collect($this->validatedFixture()['questions'])
            ->firstWhere('id', 'diagram-03');

        $this->assertIsArray($diagram);
        $this->assertSame([
            'p01' => [0.512, 0.229, 0.45, 0.12, 'left', 'elbow', 'point'],
            'p02' => [0.587, 0.229, 0.631111, 0.12, 'right', 'elbow', 'point'],
            'p03' => [0.539444, 0.359332, 0.70611, 0.359332, 'right', 'straight', 'arrow'],
            'p04' => [0.343668, 0.299335, 0.28, 0.24267, 'left', 'straight', 'arrow'],
            'p05' => [0.465218, 0.328334, 0.28, 0.328334, 'left', 'straight', 'arrow'],
            'p06' => [0.459, 0.534, 0.28, 0.534, 'left', 'straight', 'point'],
            'p07' => [0.576444, 0.469665, 0.717222, 0.469665, 'right', 'straight', 'arrow'],
            'p08' => [0.677222, 0.544334, 0.72, 0.544334, 'right', 'straight', 'arrow'],
            'p09' => [0.660666, 0.786, 0.72, 0.786, 'right', 'straight', 'point'],
            'p10' => [0.640666, 0.836334, 0.6, 0.880005, 'left', 'straight', 'arrow'],
        ], collect($diagram['callouts'])->mapWithKeys(fn (array $callout): array => [
            $callout['partId'] => [
                $callout['x'],
                $callout['y'],
                $callout['labelX'],
                $callout['labelY'],
                $callout['labelSide'],
                $callout['leaderStyle'],
                $callout['targetMarker'] ?? 'point',
            ],
        ])->all());
    }

    public function test_diagram_five_callouts_preserve_the_reviewed_targets_and_balanced_label_columns(): void
    {
        $diagram = collect($this->validatedFixture()['questions'])
            ->firstWhere('id', 'diagram-05');

        $this->assertIsArray($diagram);
        $this->assertSame([
            'p01' => [0.342, 0.356545, 0.342, 0.17, 'left', 'straight', 'arrow'],
            'p02' => [0.644, 0.356545, 0.644, 0.17, 'right', 'straight', 'arrow'],
            'p03' => [0.354226, 0.435545, 0.27, 0.435545, 'left', 'straight', 'arrow'],
            'p04' => [0.416556, 0.593365, 0.27, 0.593365, 'left', 'straight', 'arrow'],
            'p05' => [0.597776, 0.499, 0.73, 0.499, 'right', 'straight', 'point'],
            'p06' => [0.55533, 0.622726, 0.73, 0.622726, 'right', 'straight', 'point'],
            'p07' => [0.570888, 0.67409, 0.73, 0.746452, 'right', 'straight', 'arrow'],
        ], collect($diagram['callouts'])->mapWithKeys(fn (array $callout): array => [
            $callout['partId'] => [
                $callout['x'],
                $callout['y'],
                $callout['labelX'],
                $callout['labelY'],
                $callout['labelSide'],
                $callout['leaderStyle'],
                $callout['targetMarker'] ?? 'point',
            ],
        ])->all());
    }

    public function test_diagram_seven_callouts_use_the_reviewed_symmetric_composition(): void
    {
        $diagram = collect($this->validatedFixture()['questions'])
            ->firstWhere('id', 'diagram-07');

        $this->assertIsArray($diagram);
        $this->assertSame([
            'p05' => [0.295472, 0.370525, 0.295472, 0.1, 'top', 0.22, 'straight', 'point'],
            'p01' => [0.316957, 0.420182, 0.22, 0.636699, 'left', 0.22, 'straight', 'point'],
            'p02' => [0.311234, 0.655843, 0.311234, 0.9, 'bottom', 0.22, 'straight', 'arrow'],
            'p03' => [0.665956, 0.450707, 0.78, 0.642804, 'right', 0.22, 'straight', 'point'],
            'p04' => [0.681443, 0.613108, 0.78, 0.783434, 'right', 0.22, 'straight', 'arrow'],
        ], collect($diagram['callouts'])->mapWithKeys(fn (array $callout): array => [
            $callout['partId'] => [
                $callout['x'],
                $callout['y'],
                $callout['labelX'],
                $callout['labelY'],
                $callout['labelSide'],
                $callout['labelWidth'],
                $callout['leaderStyle'],
                $callout['targetMarker'] ?? 'point',
            ],
        ])->all());
    }

    public function test_diagram_eight_labels_use_the_approved_composition_without_moving_targets(): void
    {
        $diagram = collect($this->validatedFixture()['questions'])
            ->firstWhere('id', 'diagram-08');

        $this->assertIsArray($diagram);
        $callouts = collect($diagram['callouts']);

        $this->assertSame([
            'p01-1' => [0.534889, 0.526296, 0.5, 0.2, 'top', 0.22, 'straight'],
            'p01-2' => [0.465444, 0.526296, null, null, null, null, null],
            'p02' => [0.548112, 0.484408, 0.74, 0.3, 'right', 0.22, 'straight'],
            'p03' => [0.377, 0.469296, 0.26, 0.469296, 'left', 0.22, 'straight'],
            'p04-1' => [0.50189, 0.451665, 0.74, 0.518333, 'right', 0.22, 'elbow'],
            'p04-2' => [0.503, 0.585, null, null, null, null, null],
            'p05' => [0.499333, 0.529444, 0.26, 0.7, 'left', 0.22, 'elbow'],
            'p06' => [0.59389, 0.578776, 0.74, 0.7, 'right', 0.22, 'elbow'],
        ], $callouts
            ->whereIn('partId', ['p01', 'p02', 'p03', 'p04', 'p05', 'p06'])
            ->mapWithKeys(fn (array $callout): array => [
                $callout['targetId'] ?? $callout['partId'] => [
                    $callout['x'],
                    $callout['y'],
                    $callout['labelX'] ?? null,
                    $callout['labelY'] ?? null,
                    $callout['labelSide'] ?? null,
                    $callout['labelWidth'] ?? null,
                    $callout['leaderStyle'] ?? null,
                ],
            ])
            ->all());
    }

    public function test_all_diagram_label_fields_use_one_canonical_width(): void
    {
        $diagrams = collect($this->validatedFixture()['questions'])
            ->where('type', 'diagram_text');

        $widths = $diagrams
            ->flatMap(fn (array $question) => collect($question['callouts'])
                ->filter(fn (array $callout): bool => array_key_exists('labelX', $callout))
                ->pluck('labelWidth'))
            ->unique()
            ->values()
            ->all();

        $this->assertSame([0.22], $widths);
    }

    public function test_diagram_nine_uses_the_reviewed_staggered_upper_callout_composition(): void
    {
        $diagram = collect($this->validatedFixture()['questions'])
            ->firstWhere('id', 'diagram-09');

        $this->assertIsArray($diagram);
        $this->assertSame([
            'p01' => [0.443556, 0.561614, 0.48, 0.35, 'top', 0.22, 'straight'],
            'p02' => [0.486, 0.573, 0.57, 0.394839, 'right', 0.22, 'straight'],
            'p03' => [0.549, 0.6, 0.68, 0.475, 'right', 0.22, 'straight'],
            'p04' => [0.383336, 0.531155, 0.32, 0.496308, 'left', 0.22, 'straight'],
            'p05' => [0.42189, 0.574616, 0.32, 0.574616, 'left', 0.22, 'straight'],
            'p06' => [0.387222, 0.652924, 0.32, 0.652924, 'left', 0.22, 'straight'],
            'p07' => [0.468888, 0.664, 0.68, 0.64, 'right', 0.22, 'straight'],
            'p08' => [0.453666, 0.725, 0.68, 0.725, 'right', 0.22, 'straight'],
        ], collect($diagram['callouts'])->mapWithKeys(fn (array $callout): array => [
            $callout['partId'] => [
                $callout['x'],
                $callout['y'],
                $callout['labelX'],
                $callout['labelY'],
                $callout['labelSide'],
                $callout['labelWidth'],
                $callout['leaderStyle'],
            ],
        ])->all());
    }

    public function test_correct_answers_keep_source_variants_after_canonical_terms(): void
    {
        $questions = collect($this->validatedFixture()['questions'])->keyBy('id');
        $expectedLabels = [
            'diagram-05' => [
                'o5' => 'ligamentum costotransversarium',
                'o6' => 'ligamentum capitis costae intraarticulare',
            ],
            'expanded-01' => [
                'o1' => 'Шейный, грудной и поясничный отделы – наиболее подвижные отделы позвоночника.',
                'o2' => 'В верхнем шейном отделе суставные поверхности преимущественно ориентированы в горизонтальной плоскости – хорошо обеспечивают вращательные движения.',
                'o3' => 'В грудном отделе суставные поверхности ориентированы во фронтальной плоскости – хорошо обеспечивают наклоны в стороны.',
                'o4' => 'В поясничном отделе суставные щели ориентированы в сагиттальной плоскости – обеспечивают сгибание/разгибание туловища.',
            ],
            'expanded-02' => [
                'o1' => 'Наименее подвижны – крестец и копчик, т.к. их позвонки соединены синостозом.',
            ],
            'expanded-03' => [
                'o1' => 'Шейный и поясничный лордозы – изгибы вперёд, грудной и крестцовый кифозы – изгибы назад.',
            ],
            'expanded-05' => [
                'o1' => 'Астеники – узкая грудная клетка, широкие межреберья, острый подгрудинный угол, «висячее» сердце',
                'o2' => 'Гиперстеники – широкая грудная клетка, узкие межреберья, тупой подгрудинный угол, «лежащее» сердце',
                'o3' => 'Нормостеники – средней ширины грудная клетка, подгрудинный угол равен 90 градусам, косое положение сердца',
            ],
            'expanded-06' => [
                'o2' => 'I этап – незначительное опускание челюсти – головка вместе с диском смещается кпереди и вниз, соскальзывая на суставной бугорок; это движение преимущественно в верхней камере, при этом головка и диск образуют единое целое',
                'o3' => 'II этап – значительное опускание челюсти – движение в верхнем этаже (скольжение)',
                'o4' => 'III этап – максимальное опускание челюсти – движение продолжается в нижней камере сустава, между диском и головкой',
                'o6' => 'Вокруг вертикальной оси (движение челюсти вправо-влево): если повернём челюсть вправо, то в одноимённом суставе – вращение в ямке, а в левом – смещение головки и суставного диска на бугорок и наоборот',
            ],
            'expanded-07' => [
                'o1' => 'Роднички – остатки фиброзной капсулы, из которой развиваются кости крыши черепа',
            ],
        ];

        $answers = $this->validatedFixture()['answerKey'];
        $this->assertSame($expectedLabels['diagram-05']['o5'], $answers['diagram-05']['response']['acceptedTexts']['p05'][0]);
        $this->assertContains('ligamentum costotransversariu', $answers['diagram-05']['response']['acceptedTexts']['p05']);
        $this->assertSame($expectedLabels['diagram-05']['o6'], $answers['diagram-05']['response']['acceptedTexts']['p06'][0]);
        $this->assertContains('ligamentumcapitis costae intraarticulare', $answers['diagram-05']['response']['acceptedTexts']['p06']);
        $this->assertSame(
            'articulatio atlantoaxialis mediana',
            $answers['diagram-08']['response']['acceptedTexts']['p05'][0],
        );
        $this->assertContains(
            'articulatio atlantoaxillaris mediana',
            $answers['diagram-08']['response']['acceptedTexts']['p05'],
        );
        $this->assertSame(
            'membrana atlantoaxialis posterior',
            $answers['diagram-09']['response']['acceptedTexts']['p03'][0],
        );
        $this->assertContains(
            'membrana atlantoaxillaris posterior',
            $answers['diagram-09']['response']['acceptedTexts']['p03'],
        );
        $this->assertSame(
            $expectedLabels['expanded-01']['o1'],
            $answers['expanded-01']['response']['referenceAnswers'][0],
        );
        $this->assertSame(
            $expectedLabels['expanded-07']['o1'],
            $answers['expanded-07']['response']['referenceAnswers'][0],
        );
    }

    public function test_reused_terms_accept_their_known_case_forms_in_every_context(): void
    {
        $fixture = $this->validatedFixture();
        $questions = collect($fixture['questions'])->keyBy('id');
        $answers = $fixture['answerKey'];
        $expectedVariants = [
            'fundamentals-03' => [
                'p01' => ['простой', 'простым'],
                'p03' => ['комплексный', 'комплексным'],
                'p04' => ['комбинированный', 'комбинированным'],
                'p08' => ['цилиндрический', 'цилиндрическом'],
                'p09' => ['блоковидный', 'блоковидном'],
            ],
            'fill-04' => [
                'p1' => ['цилиндрическом', 'цилиндрический'],
                'p2' => ['блоковидном', 'блоковидный'],
            ],
            'fill-15' => [
                'p1' => ['простым', 'простой'],
                'p2' => ['комбинированным', 'комбинированный'],
                'p3' => ['комплексным', 'комплексный'],
            ],
        ];
        $checker = new LessonAssessmentAnswerChecker;

        foreach ($expectedVariants as $questionId => $parts) {
            $question = $questions->get($questionId);
            $answer = $answers[$questionId]['response'];
            $canonicalTexts = collect($answer['acceptedTexts'])
                ->map(fn (array $variants): string => $variants[0])
                ->all();

            foreach ($parts as $partId => $variants) {
                $this->assertSame($variants, $answer['acceptedTexts'][$partId]);

                foreach ($variants as $variant) {
                    $texts = [...$canonicalTexts, $partId => $variant];
                    $this->assertTrue(
                        $checker->check($question, $answer, ['texts' => $texts])['correct'],
                        "{$questionId}.{$partId} must accept {$variant}.",
                    );
                }
            }
        }
    }

    public function test_typed_answers_with_yo_explicitly_accept_the_ye_variant(): void
    {
        $fixture = $this->validatedFixture();
        $questions = collect($fixture['questions'])->keyBy('id');
        $checker = new LessonAssessmentAnswerChecker;
        $checkedVariants = 0;

        foreach ($fixture['answerKey'] as $questionId => $entry) {
            $acceptedTexts = $entry['response']['acceptedTexts'] ?? null;
            $question = $questions->get($questionId);
            if (! is_array($acceptedTexts) || ! is_array($question)) {
                continue;
            }

            $canonicalTexts = collect($acceptedTexts)
                ->map(fn (array $variants): string => $variants[0])
                ->all();

            foreach ($acceptedTexts as $partId => $variants) {
                foreach ($variants as $variant) {
                    if (! str_contains(mb_strtolower($variant, 'UTF-8'), 'ё')) {
                        continue;
                    }

                    $variantWithYe = str_replace(['ё', 'Ё'], ['е', 'Е'], $variant);
                    $this->assertContains($variantWithYe, $variants);
                    $this->assertTrue(
                        $checker->check(
                            $question,
                            $entry['response'],
                            ['texts' => [...$canonicalTexts, $partId => $variantWithYe]],
                        )['correct'],
                        "{$questionId}.{$partId} must accept {$variantWithYe}.",
                    );
                    $checkedVariants++;
                }
            }
        }

        $this->assertGreaterThan(0, $checkedVariants);
    }

    public function test_spelling_correction_aliases_are_suggested_but_not_accepted_as_answers(): void
    {
        $fixture = $this->validatedFixture();
        $question = collect($fixture['questions'])->firstWhere('id', 'fundamentals-03');
        $answer = $fixture['answerKey']['fundamentals-03']['response'];
        $texts = collect($answer['acceptedTexts'])
            ->map(fn (array $variants): string => $variants[0])
            ->all();

        foreach (['p06' => 'двухосный', 'p10' => 'элипсовидный'] as $partId => $alias) {
            $this->assertSame([$alias], $answer['suggestionAliases'][$partId]);
            $this->assertSame(
                [$partId],
                (new LessonAssessmentAnswerChecker)->check(
                    $question,
                    $answer,
                    ['texts' => [...$texts, $partId => $alias]],
                )['incorrectPartIds'],
            );
        }
    }

    public function test_fundamentals_term_forms_are_accepted_and_suggest_the_canonical_answer(): void
    {
        $fixture = $this->validatedFixture();
        $question = collect($fixture['questions'])->firstWhere('id', 'fundamentals-01');
        $answer = $fixture['answerKey']['fundamentals-01']['response'];

        $this->assertContains('связка', $answer['acceptedTexts']['p01']);
        $this->assertContains('шов', $answer['acceptedTexts']['p04']);
        $this->assertTrue(
            (new LessonAssessmentAnswerChecker)->check($question, $answer, [
                'texts' => [
                    'p01' => 'шов',
                    'p02' => 'связка',
                    'p03' => 'родничок',
                    'p04' => 'вколачивание',
                    'p05' => 'межкостная мембрана',
                ],
            ])['correct'],
        );

        $version = new LessonAssessmentVersion;
        $version->questions = $fixture['questions'];
        $version->answer_key = $fixture['answerKey'];

        $this->assertSame(
            ['связки'],
            (new LessonAssessmentSuggestionService)->suggest($version, 'fundamentals-01', 'p01', 'связк'),
        );
        $this->assertSame(
            ['швы'],
            (new LessonAssessmentSuggestionService)->suggest($version, 'fundamentals-01', 'p04', 'шов'),
        );
    }

    public function test_expanded_reference_answers_pass_without_accepting_keyword_soup(): void
    {
        $fixture = $this->validatedFixture();
        $questions = collect($fixture['questions'])->keyBy('id');
        $checker = new LessonAssessmentAnswerChecker;

        foreach ($fixture['answerKey'] as $questionId => $entry) {
            $question = $questions->get($questionId);
            if (! is_array($question) || $question['type'] !== 'long_text') {
                continue;
            }

            $answer = $entry['response'];
            $referenceText = implode('. ', $answer['referenceAnswers']);
            $keywordSoup = implode(' ', array_map(
                fn (array $variants): string => $variants[0],
                $answer['requiredFragments'],
            ));

            $this->assertTrue(
                $checker->check($question, $answer, ['text' => $referenceText])['correct'],
                "Reference answer {$questionId} must remain accepted.",
            );
            $this->assertFalse(
                $checker->check($question, $answer, ['text' => $keywordSoup])['correct'],
                "Keyword soup {$questionId} must not be accepted.",
            );
        }
    }

    public function test_recursive_key_guard_includes_array_valued_parent_keys(): void
    {
        $this->assertSame(
            ['question', 'correctOptionIds', 'distractorOptionIds'],
            $this->recursiveKeys([
                'question' => [
                    'correctOptionIds' => ['option-a'],
                    'distractorOptionIds' => ['option-b'],
                ],
            ]),
        );
    }

    private function validatedFixture(): array
    {
        return app(LessonAssessmentContentValidator::class)->validate($this->fixture());
    }

    private function fixture(): array
    {
        $path = database_path(self::FIXTURE);

        return json_decode(File::get($path), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @return list<string> */
    private function recursiveStringValues(mixed $value): array
    {
        if (is_string($value)) {
            return [$value];
        }
        if (! is_array($value)) {
            return [];
        }

        $strings = [];
        foreach ($value as $nestedValue) {
            $strings = [...$strings, ...$this->recursiveStringValues($nestedValue)];
        }

        return $strings;
    }

    /** @return list<string> */
    private function recursiveKeys(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $keys = [];
        foreach ($value as $key => $nestedValue) {
            if (is_string($key)) {
                $keys[] = $key;
            }

            $keys = [...$keys, ...$this->recursiveKeys($nestedValue)];
        }

        return $keys;
    }
}
