<?php

namespace App\Services\Reviews;

class ReviewTextNormalizer
{
    public function normalize(?string $text): string
    {
        $value = mb_strtolower(trim((string) $text));
        $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value) ?? '';

        return trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    }

    public function hash(?string $text): string
    {
        return hash('sha256', $this->normalize($text));
    }
}
