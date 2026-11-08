<?php

namespace App\Services\Reviews;

use App\Models\Review;
use App\Models\ReviewSource;

class ReviewDuplicateMatcher
{
    public function __construct(
        private readonly ReviewTextNormalizer $normalizer,
    ) {}

    public function findMatch(array $record, ?int $excludeReviewId = null): ?array
    {
        $text = $this->normalizeText($record['text'] ?? null);

        if ($text === '') {
            return null;
        }

        $normalizedHash = $this->normalizer->hash($text);

        $sourceMatch = ReviewSource::query()
            ->when($excludeReviewId !== null, fn ($query) => $query->where('review_id', '!=', $excludeReviewId))
            ->with('review')
            ->where('content_hash', $normalizedHash)
            ->orderBy('id')
            ->first();

        if ($sourceMatch?->review) {
            return [
                'review' => $sourceMatch->review,
                'score' => 1.0,
                'match_type' => 'normalized_text',
                'reason' => 'Exact normalized text match',
                'signals' => [
                    'normalized_text_hash' => $normalizedHash,
                    'review_source_id' => $sourceMatch->id,
                ],
            ];
        }

        $exactReviewMatch = Review::query()
            ->when($excludeReviewId !== null, fn ($query) => $query->where('id', '!=', $excludeReviewId))
            ->whereNotNull('text')
            ->orderBy('id')
            ->get(['id', 'text'])
            ->first(function (Review $review) use ($text): bool {
                return $this->normalizer->normalize($review->text) === $text;
            });

        if ($exactReviewMatch) {
            return [
                'review' => $exactReviewMatch,
                'score' => 1.0,
                'match_type' => 'normalized_text',
                'reason' => 'Exact normalized text match',
                'signals' => [
                    'normalized_text_hash' => $normalizedHash,
                    'review_id' => $exactReviewMatch->id,
                ],
            ];
        }

        $bestMatch = null;
        $bestScore = 0.0;

        Review::query()
            ->when($excludeReviewId !== null, fn ($query) => $query->where('id', '!=', $excludeReviewId))
            ->whereNotNull('text')
            ->orderBy('id')
            ->chunkById(200, function ($reviews) use ($text, $normalizedHash, &$bestMatch, &$bestScore): bool {
                foreach ($reviews as $review) {
                    $normalizedReviewText = $this->normalizer->normalize($review->text);
                    if ($normalizedReviewText === '') {
                        continue;
                    }

                    similar_text($text, $normalizedReviewText, $similarityPercent);
                    $score = round($similarityPercent / 100, 4);

                    if ($score >= 0.92 && $score > $bestScore) {
                        $bestScore = $score;
                        $bestMatch = [
                            'review' => $review,
                            'score' => $score,
                            'match_type' => 'similar_text',
                            'reason' => 'Similar normalized text match',
                            'signals' => [
                                'normalized_text_hash' => $normalizedHash,
                                'similarity_percent' => $similarityPercent,
                                'review_id' => $review->id,
                            ],
                        ];
                    }
                }

                return true;
            });

        return $bestMatch;
    }

    private function normalizeText(mixed $text): string
    {
        return $this->normalizer->normalize(is_string($text) ? $text : null);
    }
}
