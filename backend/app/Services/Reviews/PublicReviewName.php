<?php

namespace App\Services\Reviews;

use App\Models\Review;

final class PublicReviewName
{
    public function resolve(Review $review): string
    {
        if ($review->is_anonymous) {
            return 'Анонимный автор';
        }

        $name = trim((string) preg_replace('/\s+/u', ' ', (string) $review->display_name));

        if ($name === '') {
            return 'Анонимный автор';
        }

        if ($review->content_edited_at !== null) {
            return $name;
        }

        if ($this->shouldUseAnonymousFallback($name)) {
            return 'Анонимный автор';
        }

        if (! $this->shouldFormatHumanName($name)) {
            return $name;
        }

        return (string) preg_replace_callback(
            '/(^|[\s-])(\p{L})(\p{L}*)/u',
            static fn (array $matches): string => $matches[1].mb_strtoupper($matches[2], 'UTF-8').mb_strtolower($matches[3], 'UTF-8'),
            $name,
        );
    }

    private function shouldFormatHumanName(string $name): bool
    {
        return (bool) preg_match('/\p{Cyrillic}/u', $name)
            && ! preg_match('/[@_0-9]/u', $name)
            && ! preg_match('/[A-Za-z]/u', $name)
            && ! preg_match('/(^|\s)автор$/iu', $name);
    }

    private function shouldUseAnonymousFallback(string $name): bool
    {
        if (preg_match('/(^|\s)автор$/iu', $name)) {
            return false;
        }

        if (preg_match('/[@_]/u', $name)) {
            return true;
        }

        if (preg_match('/[0-9]/u', $name) && preg_match('/[A-Za-z]/u', $name)) {
            return true;
        }

        return (bool) preg_match('/[A-Za-z]/u', $name)
            && (bool) preg_match('/\p{Cyrillic}/u', $name)
            && str_word_count(str_replace(['.', '-'], ' ', $name)) > 1;
    }
}
