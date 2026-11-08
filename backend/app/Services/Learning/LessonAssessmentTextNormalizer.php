<?php

namespace App\Services\Learning;

final class LessonAssessmentTextNormalizer
{
    public static function normalize(string $value): string
    {
        $normalized = mb_strtolower(trim($value), 'UTF-8');
        $normalized = str_replace('ё', 'е', $normalized);
        $normalized = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $normalized) ?? '';

        return trim(preg_replace('/\s+/u', ' ', $normalized) ?? '');
    }
}
