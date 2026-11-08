<?php

namespace App\Support;

use Carbon\CarbonInterface;

final class PublicContentSearch
{
    public static function text(string $value, bool $html = false): string
    {
        if ($html) {
            // Block boundaries introduce whitespace; inline emphasis must not split words.
            $value = preg_replace('~<(script|style)\b[^>]*>.*?</\1>~is', '', $value);
            $value = preg_replace('~</?(?:p|div|h[1-6]|li|ul|ol|blockquote|table|tr|td|th|br|hr|section|figure|figcaption)\b[^>]*>~i', ' ', $value);
            $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    public static function date(?CarbonInterface $date, string $timezone): string
    {
        return $date?->copy()->setTimezone($timezone)->locale('ru')->isoFormat('D MMMM YYYY [г.]') ?? '';
    }
}
