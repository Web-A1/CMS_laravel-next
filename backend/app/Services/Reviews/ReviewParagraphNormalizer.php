<?php

namespace App\Services\Reviews;

class ReviewParagraphNormalizer
{
    private const MIN_PARAGRAPH_TEXT_LENGTH = 300;

    /** @var array<int, array{0: string, 1: string}> */
    private const ABBREVIATIONS = [
        ['/и\s*т\.\s*д\./ui', '__CMS_REVIEW_ABBR_ITD__'],
        ['/т\.\s*д\./ui', '__CMS_REVIEW_ABBR_TD__'],
        ['/т\.\s*п\./ui', '__CMS_REVIEW_ABBR_TP__'],
        ['/т\.\s*к\./ui', '__CMS_REVIEW_ABBR_TK__'],
        ['/т\.\s*е\./ui', '__CMS_REVIEW_ABBR_TE__'],
        ['/доп\./ui', '__CMS_REVIEW_ABBR_DOP__'],
        ['/и\s*др\./ui', '__CMS_REVIEW_ABBR_IDR__'],
        ['/\bг\.\s+/ui', '__CMS_REVIEW_ABBR_CITY__ '],
    ];

    /** @var array<string, string> */
    private const ABBREVIATION_RESTORE_MAP = [
        '__CMS_REVIEW_ABBR_ITD__' => 'и т. д.',
        '__CMS_REVIEW_ABBR_TD__' => 'т. д.',
        '__CMS_REVIEW_ABBR_TP__' => 'т. п.',
        '__CMS_REVIEW_ABBR_TK__' => 'т. к.',
        '__CMS_REVIEW_ABBR_TE__' => 'т. е.',
        '__CMS_REVIEW_ABBR_DOP__' => 'доп.',
        '__CMS_REVIEW_ABBR_IDR__' => 'и др.',
        '__CMS_REVIEW_ABBR_CITY__' => 'г.',
    ];

    public function normalize(?string $text): string
    {
        $baseText = $this->baseText($text);

        if ($baseText === '') {
            return '';
        }

        $existingParagraphs = $this->existingParagraphs($baseText);
        if (count($existingParagraphs) > 1) {
            return implode("\n\n", $existingParagraphs);
        }

        $singleLineText = $this->singleLine($baseText);
        if (mb_strlen($singleLineText) < self::MIN_PARAGRAPH_TEXT_LENGTH) {
            return $singleLineText;
        }

        $sentences = $this->sentences($singleLineText);
        if (count($sentences) < 2) {
            return $singleLineText;
        }

        $paragraphs = $this->groupSentences($sentences, mb_strlen($singleLineText));

        if (count($paragraphs) < 2) {
            return $singleLineText;
        }

        return implode("\n\n", $paragraphs);
    }

    private function baseText(?string $text): string
    {
        $value = str_replace(["\r\n", "\r"], "\n", trim((string) $text));
        $value = preg_replace('/[\t ]+/u', ' ', $value) ?? '';
        $value = preg_replace('/([.!?…])(?=\p{Lu})/u', '$1 ', $value) ?? '';
        $value = preg_replace('/ *\n+ */u', "\n", $value) ?? '';

        return trim($value);
    }

    /**
     * @return list<string>
     */
    private function existingParagraphs(string $text): array
    {
        $paragraphs = preg_split('/\n{2,}/u', $text) ?: [];

        return array_values(array_filter(array_map(
            fn (string $paragraph): string => $this->singleLine($paragraph),
            $paragraphs,
        )));
    }

    private function singleLine(string $text): string
    {
        $value = preg_replace('/\s+/u', ' ', $text) ?? '';

        return $this->cleanupSentence($value);
    }

    /**
     * @return list<string>
     */
    private function sentences(string $text): array
    {
        $protectedText = $this->protectAbbreviations($text);
        preg_match_all('/[^.!?…]+[.!?…]+(?:[»")\]]+)?|[^.!?…]+$/u', $protectedText, $matches);

        $sentences = [];
        foreach ($matches[0] ?? [] as $sentence) {
            $sentence = $this->cleanupSentence($this->restoreAbbreviations($sentence));

            foreach ($this->splitLongSentence($sentence) as $part) {
                if ($part !== '') {
                    $sentences[] = $part;
                }
            }
        }

        return $sentences;
    }

    /**
     * Some imported reviews are written as two or three very long sentences.
     * Split only on explicit new-thought markers and keep the original wording.
     *
     * @return list<string>
     */
    private function splitLongSentence(string $sentence): array
    {
        if (mb_strlen($sentence) < 260) {
            return [$sentence];
        }

        $parts = preg_split(
            '/\s+(?=(?:Больше всего понравилось|А результат|Результат|В итоге|По итогу|Из минусов|Отдельно|Также|Еще|Ещё|Всем советую|Рекомендую|В общем|Спасибо вам)(?:\s|$))/ui',
            $sentence,
        ) ?: [$sentence];

        if (count($parts) < 2) {
            return [$sentence];
        }

        $segments = [];
        foreach ($parts as $part) {
            $part = $this->cleanupSentence($part);
            if ($part === '') {
                continue;
            }

            $lastIndex = count($segments) - 1;
            if ($lastIndex >= 0 && preg_match('/^\p{Ll}/u', $part) === 1) {
                $segments[$lastIndex] .= ' '.$part;

                continue;
            }

            if ($lastIndex >= 0 && mb_strlen($part) < 90) {
                $segments[$lastIndex] .= ' '.$part;

                continue;
            }

            $segments[] = $part;
        }

        return count($segments) > 1 ? $segments : [$sentence];
    }

    private function protectAbbreviations(string $text): string
    {
        foreach (self::ABBREVIATIONS as [$pattern, $replacement]) {
            $text = preg_replace($pattern, $replacement, $text) ?? $text;
        }

        return $text;
    }

    private function restoreAbbreviations(string $text): string
    {
        return strtr($text, self::ABBREVIATION_RESTORE_MAP);
    }

    private function cleanupSentence(string $sentence): string
    {
        $sentence = $this->restoreAbbreviations($sentence);
        $sentence = preg_replace('/\s+([,.;:!?])/u', '$1', $sentence) ?? '';
        $sentence = preg_replace('/([«(])\s+/u', '$1', $sentence) ?? '';
        $sentence = preg_replace('/\s+([»)\]])/u', '$1', $sentence) ?? '';

        return trim($sentence);
    }

    /**
     * @param  list<string>  $sentences
     * @return list<string>
     */
    private function groupSentences(array $sentences, int $textLength): array
    {
        $targetLength = match (true) {
            $textLength > 900 => 340,
            $textLength > 600 => 310,
            default => 270,
        };

        $paragraphs = [];
        $current = [];
        $currentLength = 0;
        $sentenceCount = count($sentences);

        foreach ($sentences as $index => $sentence) {
            $current[] = $sentence;
            $currentLength += mb_strlen($sentence) + 1;

            $nextSentence = $sentences[$index + 1] ?? null;
            if ($nextSentence === null) {
                continue;
            }

            $remainingLength = mb_strlen(implode(' ', array_slice($sentences, $index + 1)));
            $nextStartsNewThought = $this->startsNewThought($nextSentence);
            $nextStartsSummary = $this->startsSummaryThought($nextSentence);
            $balancedLengthBreak = $currentLength >= $targetLength && $remainingLength >= 90;
            $canBreak = ($remainingLength >= 150 && $currentLength >= 170)
                || ($nextStartsSummary && $remainingLength >= 35 && $currentLength >= 150)
                || ($nextStartsNewThought && $remainingLength >= 60 && $currentLength >= 190)
                || $balancedLengthBreak;
            $hasEnoughSentences = $index + 1 < $sentenceCount - 1;
            $nextIsSubstantialFinal = ! $hasEnoughSentences && $remainingLength >= 170;
            $nextIsCompactMarkedFinal = ! $hasEnoughSentences && $nextStartsNewThought && $remainingLength >= 60;
            $shouldBreak = $canBreak && ($hasEnoughSentences || $nextStartsSummary || $nextIsSubstantialFinal || $nextIsCompactMarkedFinal) && (
                $currentLength >= $targetLength
                || $nextStartsSummary
                || $balancedLengthBreak
                || ($currentLength >= 190 && $nextStartsNewThought)
                || ($currentLength >= 190 && preg_match('/[!?]$/u', $sentence) === 1 && $nextStartsNewThought)
            );

            if ($shouldBreak) {
                $paragraphs[] = implode(' ', $current);
                $current = [];
                $currentLength = 0;
            }
        }

        if ($current !== []) {
            $paragraphs[] = implode(' ', $current);
        }

        return $paragraphs;
    }

    private function startsNewThought(string $sentence): bool
    {
        return preg_match(
            '/^(Также|Ещё|Еще|Отдельно|Плюс|При этом|В итоге|По итогу|Больше всего понравилось|А результат|Результат|Спасибо|Благодарю|Рекомендую|Советую|Хочу|Очень понрав|Понравилось|Не понравилось|Данный курс|Этот курс|Сам курс|Курс|Материал|Лекции|Преподавател|Занятия|Экзамен)(?:\s|$)/ui',
            trim($sentence),
        ) === 1;
    }

    private function startsSummaryThought(string $sentence): bool
    {
        return preg_match(
            '/^(А результат|Результат|В итоге|По итогу|Вывод|В общем|Спасибо|Спасибо вам|Благодарю)(?:\s|,|:|$)/ui',
            trim($sentence),
        ) === 1;
    }
}
