<?php

namespace Tests\Unit;

use App\Models\LessonAssessmentVersion;
use App\Services\Learning\LessonAssessmentSuggestionService;
use PHPUnit\Framework\TestCase;

class LessonAssessmentSuggestionServiceTest extends TestCase
{
    public function test_it_suggests_canonical_answers_only_from_the_current_text_question(): void
    {
        $version = new LessonAssessmentVersion;
        $version->questions = [
            [
                'id' => 'fill-01',
                'type' => 'text_fields',
                'parts' => [
                    ['id' => 'p01', 'prompt' => 'Строка 1'],
                    ['id' => 'p02', 'prompt' => 'Строка 2'],
                ],
            ],
            [
                'id' => 'fill-02',
                'type' => 'text_fields',
                'parts' => [['id' => 'p01', 'prompt' => 'Строка']],
            ],
            [
                'id' => 'fill-03',
                'type' => 'text_fields',
                'parts' => [['id' => 'p01', 'prompt' => 'Строка']],
            ],
        ];
        $version->answer_key = [
            'fill-01' => [
                'response' => [
                    'acceptedTexts' => [
                        'p01' => ['Жёлтая связка', 'Желтые связки', 'Другая связка'],
                        'p02' => ['Связка жёлтая'],
                    ],
                ],
            ],
            'fill-02' => [
                'response' => [
                    'acceptedTexts' => ['p01' => ['Железистая ткань']],
                ],
            ],
            'fill-03' => ['response' => ['acceptedTexts' => ['p01' => ['Жёлтая линия']]]],
        ];
        $service = new LessonAssessmentSuggestionService;

        $this->assertSame([], $service->suggest($version, 'fill-01', 'p01', 'же'));
        $this->assertSame([], $service->suggest($version, 'fill-01', 'p01', ' жел '));
        $this->assertSame(
            ['Жёлтая связка', 'Связка жёлтая'],
            $service->suggest($version, 'fill-01', 'p01', ' ЖЕлта '),
        );
        $this->assertSame(
            ['Железистая ткань'],
            $service->suggest($version, 'fill-02', 'p01', 'желез'),
        );
        $this->assertSame([], $service->suggest($version, 'fill-01', 'unknown', 'жел'));
        $this->assertSame([], $service->suggest($version, 'unknown', 'p01', 'жел'));
    }

    public function test_it_keeps_diagram_names_and_labels_in_separate_global_dictionaries(): void
    {
        $version = new LessonAssessmentVersion;
        $version->questions = [
            [
                'id' => 'diagram-01',
                'type' => 'diagram_text',
                'parts' => [
                    ['id' => 'name', 'prompt' => 'Название'],
                    ['id' => 'p01', 'prompt' => 'Подпись 1'],
                    ['id' => 'p02', 'prompt' => 'Подпись 2'],
                ],
            ],
            [
                'id' => 'diagram-02',
                'type' => 'diagram_text',
                'parts' => [
                    ['id' => 'name', 'prompt' => 'Название'],
                    ['id' => 'p01', 'prompt' => 'Подпись 1'],
                ],
            ],
        ];
        $version->answer_key = [
            'diagram-01' => ['response' => ['acceptedTexts' => [
                'name' => ['Строение сустава'],
                'p01' => ['ligamentum flavum'],
                'p02' => ['ligamentum alare'],
            ]]],
            'diagram-02' => ['response' => ['acceptedTexts' => [
                'name' => ['Сочленение крестца и копчика'],
                'p01' => ['ligamentum sacrococcygeum anterior'],
            ]]],
        ];
        $service = new LessonAssessmentSuggestionService;

        $this->assertSame(
            ['ligamentum alare', 'ligamentum flavum', 'ligamentum sacrococcygeum anterior'],
            $service->suggest($version, 'diagram-01', 'p01', 'ligam'),
        );
        $this->assertSame(
            ['ligamentum flavum'],
            $service->suggest($version, 'diagram-02', 'p01', 'flavu'),
        );
        $this->assertSame(
            ['Сочленение крестца и копчика'],
            $service->suggest($version, 'diagram-01', 'name', 'сочле'),
        );
        $this->assertSame([], $service->suggest($version, 'diagram-01', 'p01', 'соч'));
        $this->assertSame([], $service->suggest($version, 'diagram-01', 'name', 'liga'));
    }

    public function test_it_uses_correction_aliases_but_returns_only_the_canonical_answer(): void
    {
        $version = new LessonAssessmentVersion;
        $version->questions = [[
            'id' => 'fundamentals-03',
            'type' => 'text_fields',
            'parts' => [
                ['id' => 'p06', 'prompt' => 'По количеству осей вращения, 2'],
                ['id' => 'p07', 'prompt' => 'По количеству осей вращения, 3'],
            ],
        ]];
        $version->answer_key = [
            'fundamentals-03' => ['response' => [
                'acceptedTexts' => [
                    'p06' => ['двуосный'],
                    'p07' => ['трёхосный', 'трехосный'],
                ],
                'suggestionAliases' => [
                    'p06' => ['двухосный'],
                ],
            ]],
        ];
        $service = new LessonAssessmentSuggestionService;

        $this->assertSame([], $service->suggest($version, 'fundamentals-03', 'p06', 'двух'));
        $this->assertSame(
            ['двуосный'],
            $service->suggest($version, 'fundamentals-03', 'p06', 'двухо'),
        );
        $this->assertSame(
            ['трёхосный'],
            $service->suggest($version, 'fundamentals-03', 'p07', 'трехо'),
        );
    }

    public function test_it_uses_accepted_variants_as_search_aliases_but_returns_the_canonical_answer(): void
    {
        $version = new LessonAssessmentVersion;
        $version->questions = [[
            'id' => 'fundamentals-01',
            'type' => 'text_fields',
            'parts' => [
                ['id' => 'p01', 'prompt' => 'Строка 1'],
                ['id' => 'p02', 'prompt' => 'Строка 2'],
            ],
        ]];
        $version->answer_key = [
            'fundamentals-01' => ['response' => [
                'acceptedTexts' => [
                    'p01' => ['Связки', 'Связка'],
                    'p02' => ['Швы', 'Шов'],
                ],
                'unorderedPartGroups' => [['p01', 'p02']],
            ]],
        ];
        $service = new LessonAssessmentSuggestionService;

        $this->assertSame(
            ['Связки'],
            $service->suggest($version, 'fundamentals-01', 'p01', 'связк'),
        );
        $this->assertSame(
            ['Швы'],
            $service->suggest($version, 'fundamentals-01', 'p02', 'шов'),
        );
    }

    public function test_it_does_not_offer_suggestions_for_long_text(): void
    {
        $version = new LessonAssessmentVersion;
        $version->questions = [[
            'id' => 'expanded-01',
            'type' => 'long_text',
            'rows' => 3,
        ]];
        $version->answer_key = [
            'expanded-01' => [
                'response' => [
                    'requiredFragments' => [['Шейный отдел']],
                ],
            ],
        ];

        $this->assertSame([], (new LessonAssessmentSuggestionService)->suggest(
            $version,
            'expanded-01',
            'answer',
            'шей',
        ));
    }

    public function test_it_finds_a_canonical_answer_from_the_start_of_any_word(): void
    {
        $version = new LessonAssessmentVersion;
        $version->questions = [[
            'id' => 'fundamentals-01',
            'type' => 'text_fields',
            'parts' => [
                ['id' => 'p01', 'prompt' => 'Строка 1'],
                ['id' => 'p02', 'prompt' => 'Строка 2'],
                ['id' => 'p03', 'prompt' => 'Строка 3'],
            ],
        ]];
        $version->answer_key = [
            'fundamentals-01' => [
                'response' => [
                    'acceptedTexts' => [
                        'p01' => ['Связки'],
                        'p02' => ['Межкостная мембрана'],
                        'p03' => ['Роднички'],
                    ],
                    'unorderedPartGroups' => [['p01', 'p02', 'p03']],
                ],
            ],
        ];
        $service = new LessonAssessmentSuggestionService;

        $this->assertSame(
            ['Межкостная мембрана'],
            $service->suggest($version, 'fundamentals-01', 'p01', 'мембр'),
        );
    }

    public function test_it_applies_the_threshold_to_the_word_being_matched(): void
    {
        $version = new LessonAssessmentVersion;
        $version->questions = [[
            'id' => 'fundamentals-01',
            'type' => 'text_fields',
            'parts' => [
                ['id' => 'p01', 'prompt' => 'Строка 1'],
                ['id' => 'p02', 'prompt' => 'Строка 2'],
                ['id' => 'p03', 'prompt' => 'Строка 3'],
                ['id' => 'p04', 'prompt' => 'Строка 4'],
            ],
        ]];
        $version->answer_key = [
            'fundamentals-01' => ['response' => ['acceptedTexts' => [
                'p01' => ['Шов'],
                'p02' => ['Типы'],
                'p03' => ['Сустав'],
                'p04' => ['Межкостная мембрана'],
            ]]],
        ];
        $service = new LessonAssessmentSuggestionService;

        $this->assertSame(['Шов'], $service->suggest($version, 'fundamentals-01', 'p01', 'шов'));
        $this->assertSame(['Типы'], $service->suggest($version, 'fundamentals-01', 'p01', 'тип'));
        $this->assertSame([], $service->suggest($version, 'fundamentals-01', 'p01', 'сус'));
        $this->assertSame([], $service->suggest($version, 'fundamentals-01', 'p01', 'суст'));
        $this->assertSame(['Сустав'], $service->suggest($version, 'fundamentals-01', 'p01', 'суста'));
        $this->assertSame([], $service->suggest($version, 'fundamentals-01', 'p01', 'мем'));
        $this->assertSame([], $service->suggest($version, 'fundamentals-01', 'p01', 'мемб'));
        $this->assertSame(
            ['Межкостная мембрана'],
            $service->suggest($version, 'fundamentals-01', 'p01', 'мембр'),
        );
        $this->assertSame([], $service->suggest(
            $version,
            'fundamentals-01',
            'p01',
            'межкостная мем',
        ));
        $this->assertSame(
            ['Межкостная мембрана'],
            $service->suggest($version, 'fundamentals-01', 'p01', 'межкостная мембр'),
        );
    }

    public function test_it_caps_the_suggestion_dictionary_to_the_frontend_contract_limit(): void
    {
        $parts = [];
        $acceptedTexts = [];
        for ($index = 0; $index <= 500; $index++) {
            $partId = sprintf('p%03d', $index);
            $parts[] = ['id' => $partId, 'prompt' => 'Строка'];
            $acceptedTexts[$partId] = [sprintf('Термин %03d', $index)];
        }

        $version = new LessonAssessmentVersion;
        $version->questions = [[
            'id' => 'fundamentals-01',
            'type' => 'text_fields',
            'parts' => $parts,
        ]];
        $version->answer_key = [
            'fundamentals-01' => [
                'response' => ['acceptedTexts' => $acceptedTexts],
            ],
        ];

        $suggestions = (new LessonAssessmentSuggestionService)->suggest(
            $version,
            'fundamentals-01',
            'p000',
            'терми',
        );

        $this->assertCount(500, $suggestions);
        $this->assertSame('Термин 000', $suggestions[0]);
        $this->assertSame('Термин 499', $suggestions[499]);
    }
}
