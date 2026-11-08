<?php

namespace App\Services\Learning;

class LessonAssessmentAnswerChecker
{
    private const LONG_TEXT_MINIMUM_CONTEXT_TOKENS = 2;

    private const NEGATION_TOKENS = [
        'без',
        'не',
        'нет',
        'ни',
        'никогда',
        'отсутствует',
        'отсутствуют',
    ];

    /**
     * @param  array<string, mixed>  $question
     * @param  array<string, mixed>  $answer
     * @param  array<string, mixed>  $response
     * @return array{correct: bool, earnedParts: int, maxParts: int, incorrectPartIds: list<string>}
     */
    public function check(array $question, array $answer, array $response): array
    {
        $type = $question['type'] ?? null;

        if ($type === 'single_choice') {
            return $this->result(
                $this->validOption($question, $response['selectedOptionId'] ?? null)
                && ($response['selectedOptionId'] ?? null) === ($answer['selectedOptionId'] ?? null),
            );
        }

        if (in_array($type, ['multiple_choice', 'ordered_choice'], true)) {
            $selected = $response['selectedOptionIds'] ?? null;
            $expected = $answer['selectedOptionIds'] ?? null;
            if (! $this->validOptionList($question, $selected) || ! is_array($expected) || ! array_is_list($expected)) {
                return $this->result(false);
            }

            if ($type === 'ordered_choice') {
                return $this->result($selected === $expected);
            }

            $actualSet = $selected;
            $expectedSet = $expected;
            sort($actualSet, SORT_STRING);
            sort($expectedSet, SORT_STRING);

            return $this->result($actualSet === $expectedSet);
        }

        if (in_array($type, ['slot_choice', 'diagram_labels'], true)) {
            return $this->checkParts($question, $answer, $response);
        }

        if (in_array($type, ['text_fields', 'diagram_text'], true)) {
            return $this->checkTextParts($question, $answer, $response);
        }

        if ($type === 'long_text') {
            return $this->checkLongText($answer, $response);
        }

        return $this->result(false);
    }

    /**
     * @param  array<string, mixed>  $question
     * @param  array<string, mixed>  $answer
     * @param  array<string, mixed>  $response
     * @return array{correct: bool, earnedParts: int, maxParts: int, incorrectPartIds: list<string>}
     */
    private function checkTextParts(array $question, array $answer, array $response): array
    {
        $parts = $question['parts'] ?? null;
        $acceptedTexts = $answer['acceptedTexts'] ?? null;
        $texts = $response['texts'] ?? null;
        if (! is_array($parts) || ! array_is_list($parts) || ! is_array($acceptedTexts) || ! is_array($texts)) {
            return $this->result(false);
        }

        $canonicalPartIds = [];
        foreach ($parts as $part) {
            $partId = is_array($part) ? ($part['id'] ?? null) : null;
            if (! is_string($partId)) {
                return $this->result(false);
            }
            $canonicalPartIds[$partId] = true;
        }
        $maxParts = count($canonicalPartIds);
        if ($maxParts === 0) {
            return $this->result(false, [], 0, 0);
        }

        $unorderedGroups = $this->unorderedTextGroups($answer, $canonicalPartIds);
        if ($unorderedGroups === null) {
            return $this->result(false, array_keys($canonicalPartIds), 0, $maxParts);
        }

        $unorderedPartIds = [];
        foreach ($unorderedGroups as $group) {
            foreach ($group as $partId) {
                $unorderedPartIds[$partId] = true;
            }
        }

        $incorrectPartIds = [];
        foreach (array_keys($canonicalPartIds) as $partId) {
            if (isset($unorderedPartIds[$partId])) {
                continue;
            }
            $actual = $texts[$partId] ?? null;
            $variants = $acceptedTexts[$partId] ?? null;
            if (! is_string($actual) || ! $this->textMatches($actual, $variants)) {
                $incorrectPartIds[] = $partId;
            }
        }

        foreach ($unorderedGroups as $group) {
            $availableExpectedPartIds = array_fill_keys($group, true);
            foreach ($group as $actualPartId) {
                $actual = $texts[$actualPartId] ?? null;
                $matchedExpectedPartId = is_string($actual)
                    ? $this->matchingExpectedPartId($actual, $acceptedTexts, $availableExpectedPartIds)
                    : null;
                if ($matchedExpectedPartId === null) {
                    $incorrectPartIds[] = $actualPartId;

                    continue;
                }
                unset($availableExpectedPartIds[$matchedExpectedPartId]);
            }
        }

        $hasUnknownPart = array_any(
            array_keys($texts),
            fn (mixed $partId): bool => ! is_string($partId) || ! isset($canonicalPartIds[$partId]),
        );

        $incorrectPartLookup = array_fill_keys($incorrectPartIds, true);
        $incorrectPartIds = array_values(array_filter(
            array_keys($canonicalPartIds),
            fn (string $partId): bool => isset($incorrectPartLookup[$partId]),
        ));

        $earnedParts = $hasUnknownPart ? 0 : $maxParts - count($incorrectPartIds);

        return $this->result(
            $earnedParts === $maxParts,
            $incorrectPartIds,
            $earnedParts,
            $maxParts,
        );
    }

    /**
     * @param  array<string, mixed>  $answer
     * @param  array<string, true>  $canonicalPartIds
     * @return list<list<string>>|null
     */
    private function unorderedTextGroups(array $answer, array $canonicalPartIds): ?array
    {
        if (! array_key_exists('unorderedPartGroups', $answer)) {
            return [];
        }

        $groups = $answer['unorderedPartGroups'];
        if (! is_array($groups) || ! array_is_list($groups)) {
            return null;
        }

        $seen = [];
        foreach ($groups as $group) {
            if (! is_array($group) || ! array_is_list($group) || count($group) < 2) {
                return null;
            }
            foreach ($group as $partId) {
                if (! is_string($partId) || ! isset($canonicalPartIds[$partId]) || isset($seen[$partId])) {
                    return null;
                }
                $seen[$partId] = true;
            }
        }

        return $groups;
    }

    private function textMatches(string $actual, mixed $variants): bool
    {
        if (! is_array($variants) || ! array_is_list($variants)) {
            return false;
        }

        $normalizedActual = LessonAssessmentTextNormalizer::normalize($actual);

        return $normalizedActual !== '' && array_any(
            $variants,
            fn (mixed $variant): bool => is_string($variant)
                && LessonAssessmentTextNormalizer::normalize($variant) === $normalizedActual,
        );
    }

    /**
     * @param  array<string, mixed>  $acceptedTexts
     * @param  array<string, true>  $availableExpectedPartIds
     */
    private function matchingExpectedPartId(
        string $actual,
        array $acceptedTexts,
        array $availableExpectedPartIds,
    ): ?string {
        foreach (array_keys($availableExpectedPartIds) as $expectedPartId) {
            if ($this->textMatches($actual, $acceptedTexts[$expectedPartId] ?? null)) {
                return $expectedPartId;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $answer
     * @param  array<string, mixed>  $response
     * @return array{correct: bool, earnedParts: int, maxParts: int, incorrectPartIds: list<string>}
     */
    private function checkLongText(array $answer, array $response): array
    {
        $text = $response['text'] ?? null;
        $requiredFragments = $answer['requiredFragments'] ?? null;
        if (! is_string($text) || ! is_array($requiredFragments) || ! array_is_list($requiredFragments)) {
            return $this->result(false, ['answer']);
        }

        $tokens = $this->normalizedTokens($text);
        $matchedTokenIndexes = [];
        foreach ($requiredFragments as $variants) {
            $span = $this->affirmedFragmentSpan($tokens, $variants);
            if ($span === null) {
                return $this->result(false, ['answer']);
            }
            foreach ($span as $tokenIndex) {
                $matchedTokenIndexes[$tokenIndex] = true;
            }
        }

        $correct = count($tokens) - count($matchedTokenIndexes) >= self::LONG_TEXT_MINIMUM_CONTEXT_TOKENS;

        return $this->result($correct, $correct ? [] : ['answer']);
    }

    /** @return list<string> */
    private function normalizedTokens(string $text): array
    {
        $normalized = LessonAssessmentTextNormalizer::normalize($text);

        return $normalized === '' ? [] : explode(' ', $normalized);
    }

    /** @return list<int>|null */
    private function affirmedFragmentSpan(array $tokens, mixed $variants): ?array
    {
        if (! is_array($variants) || ! array_is_list($variants)) {
            return null;
        }

        foreach ($variants as $variant) {
            if (! is_string($variant)) {
                continue;
            }
            $variantTokens = $this->normalizedTokens($variant);
            if ($variantTokens === [] || count($variantTokens) > count($tokens)) {
                continue;
            }

            $lastStart = count($tokens) - count($variantTokens);
            for ($start = 0; $start <= $lastStart; $start++) {
                $matches = true;
                foreach ($variantTokens as $offset => $variantToken) {
                    if (! str_starts_with($tokens[$start + $offset], $variantToken)) {
                        $matches = false;
                        break;
                    }
                }
                if (! $matches) {
                    continue;
                }

                $end = $start + count($variantTokens) - 1;
                if (! $this->hasNearbyNegation($tokens, $start, $end)) {
                    return range($start, $end);
                }
            }
        }

        return null;
    }

    /** @param list<string> $tokens */
    private function hasNearbyNegation(array $tokens, int $start, int $end): bool
    {
        $windowStart = max(0, $start - 2);
        $windowEnd = min(count($tokens) - 1, $end + 2);
        for ($index = $windowStart; $index <= $windowEnd; $index++) {
            if (in_array($tokens[$index], self::NEGATION_TOKENS, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $question
     * @param  array<string, mixed>  $answer
     * @param  array<string, mixed>  $response
     * @return array{correct: bool, earnedParts: int, maxParts: int, incorrectPartIds: list<string>}
     */
    private function checkParts(array $question, array $answer, array $response): array
    {
        $parts = $question['parts'] ?? null;
        $expected = $answer['selections'] ?? null;
        $selected = $response['selections'] ?? null;
        if (! is_array($parts) || ! array_is_list($parts) || ! is_array($expected) || ! is_array($selected)) {
            return $this->result(false);
        }

        $incorrectPartIds = [];
        $canonicalPartIds = [];
        foreach ($parts as $part) {
            if (! is_array($part) || ! is_string($part['id'] ?? null)) {
                return $this->result(false);
            }

            $partId = $part['id'];
            $canonicalPartIds[$partId] = true;
            $allowedOptionIds = is_array($part['optionIds'] ?? null)
                ? $part['optionIds']
                : [];
            $selectedOptionId = $selected[$partId] ?? null;
            if (
                ! is_string($selectedOptionId)
                || ! in_array($selectedOptionId, $allowedOptionIds, true)
                || ! array_key_exists($partId, $expected)
                || $selectedOptionId !== $expected[$partId]
            ) {
                $incorrectPartIds[] = $partId;
            }
        }
        $maxParts = count($canonicalPartIds);
        if ($maxParts === 0) {
            return $this->result(false, [], 0, 0);
        }

        $hasUnknownPart = false;
        foreach (array_keys($selected) as $partId) {
            if (! is_string($partId) || ! isset($canonicalPartIds[$partId])) {
                $hasUnknownPart = true;
                break;
            }
        }

        $earnedParts = $hasUnknownPart ? 0 : $maxParts - count($incorrectPartIds);

        return $this->result(
            $earnedParts === $maxParts,
            $incorrectPartIds,
            $earnedParts,
            $maxParts,
        );
    }

    /** @param array<string, mixed> $question */
    private function validOption(array $question, mixed $optionId): bool
    {
        if (! is_string($optionId) || ! is_array($question['options'] ?? null)) {
            return false;
        }

        foreach ($question['options'] as $option) {
            if (is_array($option) && ($option['id'] ?? null) === $optionId) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $question */
    private function validOptionList(array $question, mixed $optionIds): bool
    {
        if (! is_array($optionIds) || ! array_is_list($optionIds) || $optionIds === []) {
            return false;
        }

        $seen = [];
        foreach ($optionIds as $optionId) {
            if (! $this->validOption($question, $optionId) || isset($seen[$optionId])) {
                return false;
            }
            $seen[$optionId] = true;
        }

        return true;
    }

    /** @return array{correct: bool, earnedParts: int, maxParts: int, incorrectPartIds: list<string>} */
    private function result(
        bool $correct,
        array $incorrectPartIds = [],
        ?int $earnedParts = null,
        int $maxParts = 1,
    ): array {
        return [
            'correct' => $correct,
            'earnedParts' => $earnedParts ?? ($correct ? $maxParts : 0),
            'maxParts' => $maxParts,
            'incorrectPartIds' => $incorrectPartIds,
        ];
    }
}
