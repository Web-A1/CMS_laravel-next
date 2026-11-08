<?php

namespace App\Services\Learning;

use InvalidArgumentException;

class LessonAssessmentContentValidator
{
    public const MAX_QUESTIONS = 500;

    private const ASSET_MIME_EXTENSIONS = [
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    private const QUESTION_TYPES = [
        'single_choice',
        'multiple_choice',
        'slot_choice',
        'ordered_choice',
        'diagram_labels',
        'text_fields',
        'diagram_text',
        'long_text',
    ];

    private const PROHIBITED_QUESTION_KEYS = [
        'answerKey',
        'answer',
        'correctAnswer',
        'correctOptionId',
        'correctOptionIds',
        'correctResponse',
        'isCorrect',
        'correctness',
        'solution',
    ];

    private const EXPECTED_LESSON = [
        'subjectSlug' => 'anatomiya',
        'courseSlug' => 'artrologiya',
        'lessonSlug' => 'soedineniya-kostej-tulovishcha-i-cherepa',
    ];

    private const EXPECTED_SOURCE_HASHES = [
        'assignmentSha256' => '9f32f66d9d8957061a1e0738a7adc444f9a850b1e3da1235716c51317a9def87',
        'keySha256' => '9b28fdad5337337fc2edd5689bfdaec63765b82b6a726b815c23f7c0466ff6d7',
    ];

    private const HYBRID_SCORING_POLICY = [
        'schemaVersion' => 2,
        'mode' => 'hybrid',
        'automaticWeightBps' => 7000,
        'manualWeightBps' => 3000,
        'automaticGateOverallRequiredBps' => 8000,
        'automaticGateSectionRequiredBps' => 6000,
        'manualRequiredBps' => 6000,
        'finalRequiredBps' => 8000,
    ];

    /**
     * @return array{
     *     schemaVersion: int,
     *     contentVersion: int,
     *     lesson: array{subjectSlug: string, courseSlug: string, lessonSlug: string},
     *     sources: array{assignmentSha256: string, keySha256: string},
     *     sections: list<array{id: string, title: string}>,
     *     questions: list<array<string, mixed>>,
     *     answerKey: array<string, array{response: array<string, mixed>, source: array{page: int, item: string}}>,
     *     assets: list<array<string, mixed>>
     * }
     */
    public function validate(array $fixture): array
    {
        $this->assertExactKeys($fixture, [
            'schemaVersion',
            'contentVersion',
            'scoringPolicy',
            'lesson',
            'sources',
            'sections',
            'questions',
            'answerKey',
            'assets',
        ], 'fixture');

        if ($fixture['schemaVersion'] !== 2) {
            $this->fail('schemaVersion', 'must equal 2');
        }

        $this->assertPositiveInteger($fixture['contentVersion'], 'contentVersion');
        $this->validateScoringPolicy($fixture['scoringPolicy']);

        $this->validateLesson($fixture['lesson']);
        $this->validateSources($fixture['sources']);
        $sectionIds = $this->validateSections($fixture['sections']);
        $assetKeys = $this->validateAssets($fixture['assets']);

        $this->assertList($fixture['questions'], 'questions');
        if ($fixture['questions'] === [] || count($fixture['questions']) > self::MAX_QUESTIONS) {
            $this->fail('questions', sprintf('must contain between 1 and %d items', self::MAX_QUESTIONS));
        }

        $questions = [];
        $questionMetadata = [];
        $gradingModes = [];
        foreach ($fixture['questions'] as $index => $question) {
            $path = "questions[{$index}]";
            $this->rejectProhibitedQuestionKeys($question, $path);

            [$sanitizedQuestion, $metadata] = $this->validateQuestion(
                $question,
                $path,
                $sectionIds,
                $assetKeys,
            );

            $questionId = $sanitizedQuestion['id'];
            if (isset($questionMetadata[$questionId])) {
                $this->fail("{$path}.id", 'must be globally unique');
            }

            $questions[] = $sanitizedQuestion;
            $questionMetadata[$questionId] = $metadata;
            $gradingModes[$sanitizedQuestion['gradingMode']] = true;
        }

        if (! isset($gradingModes['automatic']) || ! isset($gradingModes['manual'])) {
            $this->fail('questions', 'must contain at least one automatic and one manual question');
        }

        $this->validateAnswerKey($fixture['answerKey'], $questionMetadata);

        return [
            'schemaVersion' => $fixture['schemaVersion'],
            'contentVersion' => $fixture['contentVersion'],
            'scoringPolicy' => $fixture['scoringPolicy'],
            'lesson' => $fixture['lesson'],
            'sources' => $fixture['sources'],
            'sections' => $fixture['sections'],
            'questions' => $questions,
            'answerKey' => $fixture['answerKey'],
            'assets' => $fixture['assets'],
        ];
    }

    private function validateScoringPolicy(mixed $policy): void
    {
        $this->assertExactKeys($policy, array_keys(self::HYBRID_SCORING_POLICY), 'scoringPolicy');

        foreach (self::HYBRID_SCORING_POLICY as $key => $expected) {
            if (($policy[$key] ?? null) !== $expected) {
                $this->fail("scoringPolicy.{$key}", 'must match the approved hybrid policy');
            }
        }
    }

    private function validateLesson(mixed $lesson): void
    {
        $this->assertExactKeys($lesson, ['subjectSlug', 'courseSlug', 'lessonSlug'], 'lesson');
        foreach (['subjectSlug', 'courseSlug', 'lessonSlug'] as $key) {
            $this->assertNonEmptyString($lesson[$key], "lesson.{$key}");
            if ($lesson[$key] !== self::EXPECTED_LESSON[$key]) {
                $this->fail("lesson.{$key}", 'must match the canonical lesson identity');
            }
        }
    }

    private function validateSources(mixed $sources): void
    {
        $this->assertExactKeys($sources, ['assignmentSha256', 'keySha256'], 'sources');
        foreach (['assignmentSha256', 'keySha256'] as $key) {
            $this->assertSha256($sources[$key], "sources.{$key}");
            if ($sources[$key] !== self::EXPECTED_SOURCE_HASHES[$key]) {
                $this->fail("sources.{$key}", 'must match the canonical source hash');
            }
        }
    }

    /** @return array<string, true> */
    private function validateSections(mixed $sections): array
    {
        $this->assertList($sections, 'sections');
        $ids = [];
        foreach ($sections as $index => $section) {
            $path = "sections[{$index}]";
            $this->assertExactKeys($section, ['id', 'title'], $path);
            $this->assertIdentifier($section['id'], "{$path}.id");
            $this->assertNonEmptyString($section['title'], "{$path}.title");
            if (isset($ids[$section['id']])) {
                $this->fail("{$path}.id", 'must be unique');
            }

            $ids[$section['id']] = true;
        }

        return $ids;
    }

    /** @return array<string, true> */
    private function validateAssets(mixed $assets): array
    {
        $this->assertList($assets, 'assets');
        $keys = [];
        foreach ($assets as $index => $asset) {
            $path = "assets[{$index}]";
            $this->assertExactKeys($asset, [
                'assetKey',
                'sourcePath',
                'sha256',
                'mimeType',
                'width',
                'height',
                'sourcePage',
                'cropBox',
            ], $path);
            $this->assertIdentifier($asset['assetKey'], "{$path}.assetKey");
            if (isset($keys[$asset['assetKey']])) {
                $this->fail("{$path}.assetKey", 'must be unique');
            }

            $this->assertAssetSourcePath($asset['sourcePath'], $asset['mimeType'], "{$path}.sourcePath");
            $this->assertSha256($asset['sha256'], "{$path}.sha256");

            $this->assertPositiveInteger($asset['width'], "{$path}.width");
            $this->assertPositiveInteger($asset['height'], "{$path}.height");
            $this->assertSourcePage($asset['sourcePage'], "{$path}.sourcePage");
            $this->validateCropBox($asset['cropBox'], "{$path}.cropBox");
            $keys[$asset['assetKey']] = true;
        }

        return $keys;
    }

    /**
     * @param  array<string, true>  $sectionIds
     * @param  array<string, true>  $assetKeys
     * @return array{0: array<string, mixed>, 1: array{type: string, optionIds: array<string, true>, partOptionIds: array<string, array<string, true>>}}
     */
    private function validateQuestion(
        mixed $question,
        string $path,
        array $sectionIds,
        array $assetKeys,
    ): array {
        $this->assertArray($question, $path);
        $this->assertNonEmptyString($question['type'] ?? null, "{$path}.type");
        $type = $question['type'];
        if (! in_array($type, self::QUESTION_TYPES, true)) {
            $this->fail("{$path}.type", 'is not supported');
        }

        $expectedKeys = ['id', 'sectionId', 'gradingMode', 'type', 'prompt', 'source'];
        if (in_array($type, ['single_choice', 'multiple_choice', 'slot_choice', 'ordered_choice', 'diagram_labels'], true)) {
            $expectedKeys[] = 'options';
        }
        if ($type === 'multiple_choice') {
            $expectedKeys[] = 'orderMatters';
        }
        if (in_array($type, ['slot_choice', 'diagram_labels', 'text_fields', 'diagram_text'], true)) {
            $expectedKeys[] = 'parts';
        }
        if (in_array($type, ['diagram_labels', 'diagram_text'], true)) {
            $expectedKeys[] = 'assetKey';
            $expectedKeys[] = 'callouts';
        }
        if ($type === 'long_text') {
            $expectedKeys[] = 'rows';
        }
        $this->assertExactKeys($question, $expectedKeys, $path, ['distractorOptionIds']);

        $this->assertQuestionIdentifier($question['id'], "{$path}.id");
        $this->assertIdentifier($question['sectionId'], "{$path}.sectionId");
        if (! in_array($question['gradingMode'] ?? null, ['automatic', 'manual'], true)) {
            $this->fail("{$path}.gradingMode", 'must equal automatic or manual');
        }
        if (! isset($sectionIds[$question['sectionId']])) {
            $this->fail("{$path}.sectionId", 'must reference an existing section');
        }

        $this->assertNonEmptyString($question['prompt'], "{$path}.prompt");
        $this->validateSource($question['source'], "{$path}.source");
        $optionIds = array_key_exists('options', $question)
            ? $this->validateOptions($question['options'], "{$path}.options")
            : [];
        $partOptionIds = [];

        if ($type === 'multiple_choice' && $question['orderMatters'] !== false) {
            $this->fail("{$path}.orderMatters", 'must equal false');
        }

        if (in_array($type, ['slot_choice', 'diagram_labels'], true)) {
            $partOptionIds = $this->validateParts($question['parts'], "{$path}.parts", $optionIds);
        }

        if (in_array($type, ['text_fields', 'diagram_text'], true)) {
            $partOptionIds = $this->validateTextParts($question['parts'], "{$path}.parts");
        }

        if (in_array($type, ['diagram_labels', 'diagram_text'], true)) {
            $this->assertIdentifier($question['assetKey'], "{$path}.assetKey");
            if (! isset($assetKeys[$question['assetKey']])) {
                $this->fail("{$path}.assetKey", 'must reference an existing asset');
            }
            $this->validateCallouts($question['callouts'], "{$path}.callouts", $partOptionIds);
        }

        if ($type === 'long_text') {
            $this->assertPositiveInteger($question['rows'], "{$path}.rows");
            if ($question['rows'] > 12) {
                $this->fail("{$path}.rows", 'must not exceed 12');
            }
        }

        if (array_key_exists('distractorOptionIds', $question)) {
            $distractorOptionIds = $this->validateIdentifierList(
                $question['distractorOptionIds'],
                "{$path}.distractorOptionIds",
                $optionIds,
            );
            if ($type === 'ordered_choice' && $distractorOptionIds !== []) {
                $this->fail("{$path}.distractorOptionIds", 'must be empty for an ordered question');
            }
            unset($question['distractorOptionIds']);
        }

        return [$question, [
            'type' => $type,
            'optionIds' => $optionIds,
            'partOptionIds' => $partOptionIds,
        ]];
    }

    /** @return array<string, true> */
    private function validateOptions(mixed $options, string $path): array
    {
        $this->assertList($options, $path);
        $ids = [];
        foreach ($options as $index => $option) {
            $optionPath = "{$path}[{$index}]";
            $this->assertExactKeys($option, ['id', 'label'], $optionPath);
            $this->assertIdentifier($option['id'], "{$optionPath}.id");
            $this->assertNonEmptyString($option['label'], "{$optionPath}.label");
            if (isset($ids[$option['id']])) {
                $this->fail("{$optionPath}.id", 'must be unique within the question');
            }

            $ids[$option['id']] = true;
        }

        return $ids;
    }

    /**
     * @param  array<string, true>  $optionIds
     * @return array<string, array<string, true>>
     */
    private function validateParts(mixed $parts, string $path, array $optionIds): array
    {
        $this->assertList($parts, $path);
        if ($parts === []) {
            $this->fail($path, 'must not be empty');
        }

        $partOptionIds = [];
        foreach ($parts as $index => $part) {
            $partPath = "{$path}[{$index}]";
            $this->assertExactKeys($part, ['id', 'prompt', 'optionIds'], $partPath);
            $this->assertIdentifier($part['id'], "{$partPath}.id");
            $this->assertNonEmptyString($part['prompt'], "{$partPath}.prompt");
            if (isset($partOptionIds[$part['id']])) {
                $this->fail("{$partPath}.id", 'must be unique within the question');
            }

            $partOptionIds[$part['id']] = $this->validateIdentifierList(
                $part['optionIds'],
                "{$partPath}.optionIds",
                $optionIds,
            );
        }

        return $partOptionIds;
    }

    /** @return array<string, array<string, true>> */
    private function validateTextParts(mixed $parts, string $path): array
    {
        $this->assertList($parts, $path);
        if ($parts === []) {
            $this->fail($path, 'must not be empty');
        }

        $partIds = [];
        foreach ($parts as $index => $part) {
            $partPath = "{$path}[{$index}]";
            $this->assertExactKeys($part, ['id', 'prompt'], $partPath);
            $this->assertIdentifier($part['id'], "{$partPath}.id");
            $this->assertNonEmptyString($part['prompt'], "{$partPath}.prompt");
            if (isset($partIds[$part['id']])) {
                $this->fail("{$partPath}.id", 'must be unique within the question');
            }
            $partIds[$part['id']] = [];
        }

        return $partIds;
    }

    /** @param array<string, array<string, true>> $partOptionIds */
    private function validateCallouts(mixed $callouts, string $path, array $partOptionIds): void
    {
        $this->assertList($callouts, $path);
        $calloutPartIds = [];
        $calloutTargetIds = [];
        $calloutsByPartId = [];
        foreach ($callouts as $index => $callout) {
            $calloutPath = "{$path}[{$index}]";
            $hasTargetId = is_array($callout) && array_key_exists('targetId', $callout);
            $hasInlineLabel = is_array($callout)
                && array_key_exists('labelX', $callout)
                && array_key_exists('labelY', $callout);
            $hasCustomLabelPlacement = $hasInlineLabel
                && array_key_exists('labelSide', $callout)
                && array_key_exists('labelWidth', $callout);
            $hasLeaderStyle = is_array($callout) && array_key_exists('leaderStyle', $callout);
            $hasTargetMarker = is_array($callout) && array_key_exists('targetMarker', $callout);
            $this->assertExactKeys(
                $callout,
                $hasCustomLabelPlacement
                    ? ['partId', 'x', 'y', 'labelX', 'labelY', 'labelSide', 'labelWidth']
                    : ($hasInlineLabel ? ['partId', 'x', 'y', 'labelX', 'labelY'] : ['partId', 'x', 'y']),
                $calloutPath,
                ['targetId', 'leaderStyle', 'targetMarker'],
            );
            if ($hasTargetId) {
                $this->assertIdentifier($callout['targetId'], "{$calloutPath}.targetId");
            }
            $this->assertIdentifier($callout['partId'], "{$calloutPath}.partId");
            if (! isset($partOptionIds[$callout['partId']])) {
                $this->fail("{$calloutPath}.partId", 'must reference an existing part');
            }
            $targetId = $hasTargetId ? $callout['targetId'] : $callout['partId'];
            if (isset($calloutTargetIds[$targetId])) {
                $this->fail("{$calloutPath}.targetId", 'must be unique within the question');
            }

            $this->assertUnitCoordinate($callout['x'], "{$calloutPath}.x");
            $this->assertUnitCoordinate($callout['y'], "{$calloutPath}.y");
            if ($hasInlineLabel) {
                $this->assertUnitCoordinate($callout['labelX'], "{$calloutPath}.labelX");
                $this->assertUnitCoordinate($callout['labelY'], "{$calloutPath}.labelY");
            }
            if ($hasLeaderStyle) {
                if (! $hasInlineLabel) {
                    $this->fail("{$calloutPath}.leaderStyle", 'requires an inline label');
                }
                if (! is_string($callout['leaderStyle']) || ! in_array($callout['leaderStyle'], ['elbow', 'straight'], true)) {
                    $this->fail("{$calloutPath}.leaderStyle", 'must be elbow or straight');
                }
            }
            if (
                $hasTargetMarker
                && (! is_string($callout['targetMarker'])
                    || ! in_array($callout['targetMarker'], ['point', 'arrow'], true))
            ) {
                $this->fail("{$calloutPath}.targetMarker", 'must be point or arrow');
            }
            if ($hasCustomLabelPlacement) {
                if (! is_string($callout['labelSide']) || ! in_array($callout['labelSide'], ['left', 'right', 'top', 'bottom'], true)) {
                    $this->fail("{$calloutPath}.labelSide", 'must be left, right, top or bottom');
                }

                $this->assertUnitCoordinate($callout['labelWidth'], "{$calloutPath}.labelWidth");
                $labelX = (float) $callout['labelX'];
                $labelWidth = (float) $callout['labelWidth'];
                if ($labelWidth <= 0) {
                    $this->fail("{$calloutPath}.labelWidth", 'must be greater than zero');
                }
                if (
                    ($callout['labelSide'] === 'left' && $labelWidth > $labelX)
                    || ($callout['labelSide'] === 'right' && $labelX + $labelWidth > 1)
                    || (in_array($callout['labelSide'], ['top', 'bottom'], true) && ($labelX - $labelWidth / 2 < 0 || $labelX + $labelWidth / 2 > 1))
                ) {
                    $this->fail("{$calloutPath}.labelWidth", 'must stay inside the diagram canvas');
                }
            }
            $calloutPartIds[$callout['partId']] = true;
            $calloutTargetIds[$targetId] = true;
            $calloutsByPartId[$callout['partId']][] = $callout;
        }

        foreach ($partOptionIds as $partId => $_) {
            if ($partId === 'name') {
                continue;
            }

            if (! isset($calloutPartIds[$partId])) {
                $this->fail($path, "must include at least one callout for {$partId}");
            }
        }

        foreach ($calloutsByPartId as $partId => $partCallouts) {
            if ($partId === 'name' && count($partCallouts) > 1) {
                $this->fail($path, 'must include at most one callout for name');
            }
            if (count($partCallouts) > 1) {
                foreach ($partCallouts as $index => $partCallout) {
                    if (! array_key_exists('targetId', $partCallout)) {
                        $calloutIndex = array_search($partCallout, $callouts, true);
                        $this->fail("{$path}[{$calloutIndex}].targetId", 'is required for repeated parts');
                    }
                }
            }
            if (count(array_filter(
                $partCallouts,
                static fn (array $partCallout): bool => array_key_exists('labelX', $partCallout),
            )) > 1) {
                $this->fail($path, "must include at most one inline label for {$partId}");
            }
        }
    }

    /** @param array<string, array{type: string, optionIds: array<string, true>, partOptionIds: array<string, array<string, true>>}> $questionMetadata */
    private function validateAnswerKey(mixed $answerKey, array $questionMetadata): void
    {
        $this->assertMap($answerKey, 'answerKey');
        foreach ($questionMetadata as $questionId => $metadata) {
            if (! array_key_exists($questionId, $answerKey)) {
                $this->fail("answerKey.{$questionId}", 'is required');
            }

            $path = "answerKey.{$questionId}";
            $this->assertExactKeys($answerKey[$questionId], ['response', 'source'], $path);
            $this->validateSource($answerKey[$questionId]['source'], "{$path}.source");
            $this->validateResponse($answerKey[$questionId]['response'], "{$path}.response", $metadata);
        }

        foreach ($answerKey as $questionId => $_) {
            if (! isset($questionMetadata[$questionId])) {
                $this->fail("answerKey.{$questionId}", 'must not contain an entry for an unknown question');
            }
        }
    }

    /** @param array{type: string, optionIds: array<string, true>, partOptionIds: array<string, array<string, true>>} $metadata */
    private function validateResponse(mixed $response, string $path, array $metadata): void
    {
        $type = $metadata['type'];
        if ($type === 'single_choice') {
            $this->assertExactKeys($response, ['selectedOptionId'], $path);
            $this->assertResponseOption($response['selectedOptionId'], "{$path}.selectedOptionId", $metadata['optionIds']);

            return;
        }

        if (in_array($type, ['multiple_choice', 'ordered_choice'], true)) {
            $this->assertExactKeys($response, ['selectedOptionIds'], $path);
            $selectedOptionIds = $this->validateIdentifierList(
                $response['selectedOptionIds'],
                "{$path}.selectedOptionIds",
                $metadata['optionIds'],
                true,
            );
            if ($type === 'ordered_choice' && count($selectedOptionIds) !== count($metadata['optionIds'])) {
                $this->fail("{$path}.selectedOptionIds", 'must contain every question option exactly once');
            }

            return;
        }

        if (in_array($type, ['text_fields', 'diagram_text'], true)) {
            $optionalKeys = $type === 'text_fields' ? ['suggestionAliases', 'unorderedPartGroups'] : [];
            $this->assertExactKeys($response, ['acceptedTexts'], $path, $optionalKeys);
            $this->assertMap($response['acceptedTexts'], "{$path}.acceptedTexts");
            foreach ($metadata['partOptionIds'] as $partId => $_) {
                if (! array_key_exists($partId, $response['acceptedTexts'])) {
                    $this->fail("{$path}.acceptedTexts", "must include {$partId}");
                }
                $variants = $response['acceptedTexts'][$partId];
                $this->assertList($variants, "{$path}.acceptedTexts.{$partId}");
                if ($variants === []) {
                    $this->fail("{$path}.acceptedTexts.{$partId}", 'must not be empty');
                }
                foreach ($variants as $index => $variant) {
                    $this->assertNonEmptyString($variant, "{$path}.acceptedTexts.{$partId}[{$index}]");
                }
            }
            foreach ($response['acceptedTexts'] as $partId => $_) {
                if (! isset($metadata['partOptionIds'][$partId])) {
                    $this->fail("{$path}.acceptedTexts.{$partId}", 'must not reference an unknown part');
                }
            }
            if (array_key_exists('suggestionAliases', $response)) {
                $this->validateSuggestionAliases(
                    $response['suggestionAliases'],
                    "{$path}.suggestionAliases",
                    $metadata['partOptionIds'],
                );
            }
            if (array_key_exists('unorderedPartGroups', $response)) {
                $this->validateUnorderedPartGroups(
                    $response['unorderedPartGroups'],
                    "{$path}.unorderedPartGroups",
                    "{$path}.acceptedTexts",
                    $metadata['partOptionIds'],
                    $response['acceptedTexts'],
                );
            }

            return;
        }

        if ($type === 'long_text') {
            $this->assertExactKeys($response, ['requiredFragments', 'referenceAnswers'], $path);
            $this->assertList($response['requiredFragments'], "{$path}.requiredFragments");
            if ($response['requiredFragments'] === []) {
                $this->fail("{$path}.requiredFragments", 'must not be empty');
            }
            foreach ($response['requiredFragments'] as $groupIndex => $variants) {
                $groupPath = "{$path}.requiredFragments[{$groupIndex}]";
                $this->assertList($variants, $groupPath);
                if ($variants === []) {
                    $this->fail($groupPath, 'must not be empty');
                }
                foreach ($variants as $variantIndex => $variant) {
                    $this->assertNonEmptyString($variant, "{$groupPath}[{$variantIndex}]");
                }
            }
            $this->assertList($response['referenceAnswers'], "{$path}.referenceAnswers");
            if ($response['referenceAnswers'] === []) {
                $this->fail("{$path}.referenceAnswers", 'must not be empty');
            }
            foreach ($response['referenceAnswers'] as $index => $referenceAnswer) {
                $this->assertNonEmptyString($referenceAnswer, "{$path}.referenceAnswers[{$index}]");
            }

            return;
        }

        $this->assertExactKeys($response, ['selections'], $path);
        $this->assertMap($response['selections'], "{$path}.selections");
        foreach ($metadata['partOptionIds'] as $partId => $optionIds) {
            if (! array_key_exists($partId, $response['selections'])) {
                $this->fail("{$path}.selections", "must include {$partId}");
            }
            $this->assertResponseOption(
                $response['selections'][$partId],
                "{$path}.selections.{$partId}",
                $optionIds,
            );
        }
        foreach ($response['selections'] as $partId => $_) {
            if (! isset($metadata['partOptionIds'][$partId])) {
                $this->fail("{$path}.selections.{$partId}", 'must not reference an unknown part');
            }
        }
    }

    /** @param array<string, array<string, true>> $partOptionIds */
    private function validateSuggestionAliases(mixed $aliases, string $path, array $partOptionIds): void
    {
        $this->assertMap($aliases, $path);

        foreach ($aliases as $partId => $variants) {
            if (! is_string($partId) || ! isset($partOptionIds[$partId])) {
                $this->fail("{$path}.{$partId}", 'must not reference an unknown part');
            }
            $this->assertList($variants, "{$path}.{$partId}");
            if ($variants === []) {
                $this->fail("{$path}.{$partId}", 'must not be empty');
            }
            foreach ($variants as $index => $variant) {
                $this->assertNonEmptyString($variant, "{$path}.{$partId}[{$index}]");
            }
        }
    }

    /**
     * @param  array<string, array<string, true>>  $partOptionIds
     * @param  array<string, list<string>>  $acceptedTexts
     */
    private function validateUnorderedPartGroups(
        mixed $groups,
        string $path,
        string $acceptedTextsPath,
        array $partOptionIds,
        array $acceptedTexts,
    ): void {
        $this->assertList($groups, $path);
        if ($groups === []) {
            $this->fail($path, 'must not be empty');
        }

        $groupedPartIds = [];
        foreach ($groups as $groupIndex => $group) {
            $groupPath = "{$path}[{$groupIndex}]";
            $this->assertList($group, $groupPath);
            if (count($group) < 2) {
                $this->fail($groupPath, 'must contain at least two parts');
            }

            $normalizedVariants = [];
            foreach ($group as $partIndex => $partId) {
                $partPath = "{$groupPath}[{$partIndex}]";
                $this->assertIdentifier($partId, $partPath);
                if (! isset($partOptionIds[$partId])) {
                    $this->fail($partPath, 'must reference an existing text part');
                }
                if (isset($groupedPartIds[$partId])) {
                    $this->fail($partPath, 'must not repeat a part across groups');
                }
                $groupedPartIds[$partId] = true;

                foreach ($acceptedTexts[$partId] as $variantIndex => $variant) {
                    $normalized = LessonAssessmentTextNormalizer::normalize($variant);
                    if ($normalized === '') {
                        $this->fail("{$acceptedTextsPath}.{$partId}[{$variantIndex}]", 'must contain searchable text');
                    }
                    if (
                        isset($normalizedVariants[$normalized])
                        && $normalizedVariants[$normalized] !== $partId
                    ) {
                        $this->fail(
                            "{$acceptedTextsPath}.{$partId}[{$variantIndex}]",
                            'must not duplicate another answer in the same unordered group',
                        );
                    }
                    $normalizedVariants[$normalized] = $partId;
                }
            }
        }
    }

    private function validateSource(mixed $source, string $path): void
    {
        $this->assertExactKeys($source, ['page', 'item'], $path);
        $this->assertSourcePage($source['page'], "{$path}.page");
        $this->assertNonEmptyString($source['item'], "{$path}.item");
    }

    private function validateCropBox(mixed $cropBox, string $path): void
    {
        $this->assertExactKeys($cropBox, ['left', 'top', 'right', 'bottom'], $path);
        foreach (['left', 'top', 'right', 'bottom'] as $key) {
            $this->assertUnitCoordinate($cropBox[$key], "{$path}.{$key}");
        }
        if ($cropBox['left'] >= $cropBox['right']) {
            $this->fail($path, 'left must be less than right');
        }
        if ($cropBox['top'] >= $cropBox['bottom']) {
            $this->fail($path, 'top must be less than bottom');
        }
    }

    /** @param array<string, true> $allowedIds
     * @return array<string, true>
     */
    private function validateIdentifierList(
        mixed $values,
        string $path,
        array $allowedIds,
        bool $mustBeNonEmpty = false,
    ): array {
        $this->assertList($values, $path);
        if ($mustBeNonEmpty && $values === []) {
            $this->fail($path, 'must not be empty');
        }

        $ids = [];
        foreach ($values as $index => $value) {
            $valuePath = "{$path}[{$index}]";
            $this->assertIdentifier($value, $valuePath);
            if (! isset($allowedIds[$value])) {
                $this->fail($valuePath, 'must reference an existing option');
            }
            if (isset($ids[$value])) {
                $this->fail($valuePath, 'must not contain duplicates');
            }
            $ids[$value] = true;
        }

        return $ids;
    }

    /** @param array<string, true> $allowedOptionIds */
    private function assertResponseOption(mixed $value, string $path, array $allowedOptionIds): void
    {
        $this->assertIdentifier($value, $path);
        if (! isset($allowedOptionIds[$value])) {
            $this->fail($path, 'must reference an existing option');
        }
    }

    private function rejectProhibitedQuestionKeys(mixed $value, string $path): void
    {
        if (! is_array($value)) {
            return;
        }

        foreach ($value as $key => $nestedValue) {
            $nestedPath = is_int($key) ? "{$path}[{$key}]" : "{$path}.{$key}";
            if (is_string($key) && in_array($key, self::PROHIBITED_QUESTION_KEYS, true)) {
                $this->fail($nestedPath, 'is authoring-only correctness data');
            }
            $this->rejectProhibitedQuestionKeys($nestedValue, $nestedPath);
        }
    }

    /** @param list<string> $expectedKeys
     * @param  list<string>  $optionalKeys
     */
    private function assertExactKeys(mixed $value, array $expectedKeys, string $path, array $optionalKeys = []): void
    {
        $this->assertArray($value, $path);
        $actualKeys = array_keys($value);
        foreach ($actualKeys as $key) {
            if (! is_string($key) || (! in_array($key, $expectedKeys, true) && ! in_array($key, $optionalKeys, true))) {
                $this->fail($path, 'contains an unexpected key');
            }
        }
        foreach ($expectedKeys as $key) {
            if (! array_key_exists($key, $value)) {
                $this->fail("{$path}.{$key}", 'is required');
            }
        }
    }

    private function assertArray(mixed $value, string $path): void
    {
        if (! is_array($value)) {
            $this->fail($path, 'must be an object');
        }
    }

    private function assertList(mixed $value, string $path): void
    {
        if (! is_array($value) || ! array_is_list($value)) {
            $this->fail($path, 'must be a list');
        }
    }

    private function assertMap(mixed $value, string $path): void
    {
        if (! is_array($value) || array_is_list($value)) {
            $this->fail($path, 'must be an object');
        }
    }

    private function assertNonEmptyString(mixed $value, string $path): void
    {
        if (! is_string($value) || trim($value) === '') {
            $this->fail($path, 'must be a non-empty string');
        }
    }

    private function assertIdentifier(mixed $value, string $path): void
    {
        if (! is_string($value) || preg_match('/^[a-z0-9][a-z0-9-]{1,63}$/', $value) !== 1) {
            $this->fail($path, 'must match the ID pattern');
        }
    }

    private function assertQuestionIdentifier(mixed $value, string $path): void
    {
        if (! is_string($value) || preg_match('/^(?:fundamentals-(?:0[1-9]|1[01])|fill-(?:0[1-9]|1[0-9]|2[01])|diagram-0[1-9]|expanded-0[1-7])$/', $value) !== 1) {
            $this->fail($path, 'must be an allowed question ID');
        }
    }

    private function assertSha256(mixed $value, string $path): void
    {
        if (! is_string($value) || preg_match('/^[a-f0-9]{64}$/', $value) !== 1) {
            $this->fail($path, 'must be a lowercase SHA-256 hash');
        }
    }

    private function assertAssetSourcePath(mixed $value, mixed $mimeType, string $path): void
    {
        if (! is_string($mimeType) || ! isset(self::ASSET_MIME_EXTENSIONS[$mimeType])) {
            $this->fail(str_replace('.sourcePath', '.mimeType', $path), 'must be image/png or image/webp');
        }

        $extension = self::ASSET_MIME_EXTENSIONS[$mimeType];
        if (
            ! is_string($value)
            || $value === ''
            || str_starts_with($value, '/')
            || str_contains($value, '..')
            || preg_match('/\.'.preg_quote($extension, '/').'$/', $value) !== 1
        ) {
            $this->fail($path, "must be a relative {$extension} path matching mimeType without ..");
        }
    }

    private function assertPositiveInteger(mixed $value, string $path): void
    {
        if (! is_int($value) || $value < 1) {
            $this->fail($path, 'must be a positive integer');
        }
    }

    private function assertSourcePage(mixed $value, string $path): void
    {
        if (! is_int($value) || $value < 1 || $value > 9) {
            $this->fail($path, 'must be an existing source page from 1 to 9');
        }
    }

    private function assertUnitCoordinate(mixed $value, string $path): void
    {
        if ((! is_int($value) && ! is_float($value)) || ! is_finite((float) $value) || $value < 0 || $value > 1) {
            $this->fail($path, 'must be a number between 0 and 1');
        }
    }

    private function fail(string $path, string $reason): never
    {
        throw new InvalidArgumentException("{$path}: {$reason}");
    }
}
