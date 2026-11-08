<?php

namespace App\Services\Learning;

use App\Exceptions\LessonAssessmentException;
use App\Models\LessonAssessmentVersion;
use Symfony\Component\HttpFoundation\Response;

class LessonAssessmentSuggestionService
{
    private const LONG_WORD_LENGTH = 5;

    private const LONG_WORD_MINIMUM_PREFIX_LENGTH = 5;

    private const MAX_RESULTS = 500;

    private const MINIMUM_PREFIX_LENGTH = 3;

    private const CATEGORY_DIAGRAM_LABEL = 'diagram_label';

    private const CATEGORY_DIAGRAM_NAME = 'diagram_name';

    private const CATEGORY_TEXT_FIELD = 'text_field';

    /** @return list<string> */
    public function suggest(
        LessonAssessmentVersion $version,
        string $questionId,
        string $partId,
        string $prefix,
    ): array {
        $normalizedPrefix = LessonAssessmentTextNormalizer::normalize($prefix);
        if (mb_strlen($normalizedPrefix, 'UTF-8') < self::MINIMUM_PREFIX_LENGTH) {
            return [];
        }

        $questions = $version->questions;
        $answerKey = $version->answer_key;
        if (! is_array($questions) || ! array_is_list($questions) || ! is_array($answerKey)) {
            return [];
        }

        $question = $this->findQuestion($questions, $questionId);
        if (is_array($question) && ($question['gradingMode'] ?? null) === 'manual') {
            throw new LessonAssessmentException(
                LessonAssessmentException::SUGGESTION_FORBIDDEN,
                'Suggestions are unavailable for manually graded questions.',
                Response::HTTP_FORBIDDEN,
            );
        }
        if (! is_array($question) || ! in_array($question['type'] ?? null, ['text_fields', 'diagram_text'], true)) {
            return [];
        }

        $partExists = array_any(
            is_array($question['parts'] ?? null) ? $question['parts'] : [],
            fn (mixed $part): bool => is_array($part) && ($part['id'] ?? null) === $partId,
        );
        if (! $partExists) {
            return [];
        }

        $currentVariants = $answerKey[$questionId]['response']['acceptedTexts'][$partId] ?? null;
        if (! is_array($currentVariants) || ! array_is_list($currentVariants) || ! is_string($currentVariants[0] ?? null)) {
            return [];
        }

        $category = $this->category($question, $partId);
        if ($category === null) {
            return [];
        }

        $matches = [];
        $seen = [];
        $candidateQuestions = $category === self::CATEGORY_TEXT_FIELD ? [$question] : $questions;

        foreach ($candidateQuestions as $candidateQuestion) {
            if (! is_array($candidateQuestion) || ! $this->questionBelongsToCategory($candidateQuestion, $category)) {
                continue;
            }

            $candidateQuestionId = $candidateQuestion['id'] ?? null;
            $candidateResponse = is_string($candidateQuestionId)
                ? ($answerKey[$candidateQuestionId]['response'] ?? null)
                : null;
            $acceptedTexts = is_array($candidateResponse) ? ($candidateResponse['acceptedTexts'] ?? null) : null;
            $suggestionAliases = is_array($candidateResponse) ? ($candidateResponse['suggestionAliases'] ?? []) : [];
            if (! is_array($acceptedTexts)) {
                continue;
            }

            foreach ($this->categoryPartIds($candidateQuestion, $category) as $candidatePartId) {
                $variants = $acceptedTexts[$candidatePartId] ?? null;
                $canonical = is_array($variants) && array_is_list($variants) ? ($variants[0] ?? null) : null;
                if (! is_string($canonical) || trim($canonical) === '') {
                    continue;
                }

                $normalizedCanonical = LessonAssessmentTextNormalizer::normalize($canonical);
                $rank = $this->matchVariantsRank($variants, $normalizedPrefix);
                $aliases = is_array($suggestionAliases) ? ($suggestionAliases[$candidatePartId] ?? []) : [];
                if ($rank === null) {
                    $rank = $this->matchVariantsRank($aliases, $normalizedPrefix);
                }
                if ($rank === null || isset($seen[$normalizedCanonical])) {
                    continue;
                }

                $seen[$normalizedCanonical] = true;
                $matches[] = [
                    'rank' => $rank,
                    'normalized' => $normalizedCanonical,
                    'value' => $canonical,
                ];
            }
        }

        usort($matches, static function (array $left, array $right): int {
            $rankComparison = $left['rank'] <=> $right['rank'];

            return $rankComparison !== 0
                ? $rankComparison
                : strnatcasecmp($left['normalized'], $right['normalized']);
        });

        return array_values(array_map(
            static fn (array $match): string => $match['value'],
            array_slice($matches, 0, self::MAX_RESULTS),
        ));
    }

    /** @param list<mixed> $questions */
    private function findQuestion(array $questions, string $questionId): ?array
    {
        foreach ($questions as $candidate) {
            if (is_array($candidate) && ($candidate['id'] ?? null) === $questionId) {
                return $candidate;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $question */
    private function category(array $question, string $partId): ?string
    {
        if (($question['type'] ?? null) === 'text_fields') {
            return self::CATEGORY_TEXT_FIELD;
        }

        if (($question['type'] ?? null) !== 'diagram_text') {
            return null;
        }

        return $partId === 'name' ? self::CATEGORY_DIAGRAM_NAME : self::CATEGORY_DIAGRAM_LABEL;
    }

    /** @param array<string, mixed> $question */
    private function questionBelongsToCategory(array $question, string $category): bool
    {
        $type = $question['type'] ?? null;

        return $category === self::CATEGORY_TEXT_FIELD
            ? $type === 'text_fields'
            : $type === 'diagram_text';
    }

    /**
     * @param  array<string, mixed>  $question
     * @return list<string>
     */
    private function categoryPartIds(array $question, string $category): array
    {
        $parts = is_array($question['parts'] ?? null) ? $question['parts'] : [];
        $partIds = [];

        foreach ($parts as $part) {
            $partId = is_array($part) ? ($part['id'] ?? null) : null;
            if (! is_string($partId)) {
                continue;
            }
            if ($category === self::CATEGORY_DIAGRAM_NAME && $partId !== 'name') {
                continue;
            }
            if ($category === self::CATEGORY_DIAGRAM_LABEL && $partId === 'name') {
                continue;
            }
            $partIds[] = $partId;
        }

        return $partIds;
    }

    private function matchRank(string $normalizedCandidate, string $normalizedPrefix): ?int
    {
        $candidateWords = explode(' ', $normalizedCandidate);
        $prefixWords = explode(' ', $normalizedPrefix);
        $prefixWordCount = count($prefixWords);
        $typedWord = $prefixWords[$prefixWordCount - 1] ?? '';

        foreach (array_keys($candidateWords) as $startIndex) {
            $candidateSuffix = implode(' ', array_slice($candidateWords, $startIndex));
            if (! str_starts_with($candidateSuffix, $normalizedPrefix)) {
                continue;
            }

            $matchedWord = $candidateWords[$startIndex + $prefixWordCount - 1] ?? null;
            if (! is_string($matchedWord)) {
                continue;
            }

            $minimumPrefixLength = mb_strlen($matchedWord, 'UTF-8') >= self::LONG_WORD_LENGTH
                ? self::LONG_WORD_MINIMUM_PREFIX_LENGTH
                : self::MINIMUM_PREFIX_LENGTH;
            if (mb_strlen($typedWord, 'UTF-8') < $minimumPrefixLength) {
                continue;
            }

            return $startIndex === 0 ? 0 : 1;
        }

        return null;
    }

    private function matchVariantsRank(mixed $variants, string $normalizedPrefix): ?int
    {
        if (! is_array($variants) || ! array_is_list($variants)) {
            return null;
        }

        $bestRank = null;
        foreach ($variants as $variant) {
            if (! is_string($variant)) {
                continue;
            }

            $rank = $this->matchRank(
                LessonAssessmentTextNormalizer::normalize($variant),
                $normalizedPrefix,
            );
            if ($rank === null) {
                continue;
            }

            $bestRank = $bestRank === null ? $rank : min($bestRank, $rank);
        }

        return $bestRank;
    }
}
