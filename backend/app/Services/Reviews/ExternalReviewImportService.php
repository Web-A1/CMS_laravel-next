<?php

namespace App\Services\Reviews;

use App\Models\Course;
use App\Models\Review;
use App\Models\ReviewEvidence;
use App\Models\ReviewImportBatch;
use App\Models\ReviewSource;
use App\Models\Subject;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ExternalReviewImportService
{
    public function __construct(
        private readonly ReviewTextNormalizer $normalizer,
        private readonly ReviewDuplicateMatcher $duplicateMatcher,
        private readonly ReviewParagraphNormalizer $paragraphNormalizer,
    ) {}

    public function importRecord(ReviewImportBatch $batch, array $record): array
    {
        $confidence = $this->normalizeConfidence($record['confidence'] ?? null);
        $rawText = $this->rawText($record['text'] ?? null);
        $normalizedText = $this->normalizeText($rawText);
        if ($normalizedText === '') {
            throw new InvalidArgumentException('External review text is required.');
        }

        $sourceLookup = $this->sourceLookupAttributes($batch, $record);

        return DB::transaction(function () use ($batch, $record, $confidence, $rawText, $sourceLookup): array {
            DeletedReviews::lock();
            if (DeletedReviews::contains(DeletedReviews::fingerprints($batch->source_type, $record['source_ref'] ?? null, $record['external_id'] ?? null))) {
                return ['action' => 'skipped_deleted', 'review_id' => null, 'source_id' => null];
            }
            $source = ReviewSource::query()->where($sourceLookup)->first();
            $duplicateMatch = $source ? null : $this->duplicateMatcher->findMatch($record);
            $review = $source?->review ?? $duplicateMatch['review'] ?? null;
            $sourceWasNew = $source === null;

            if ($review !== null) {
                $review = Review::whereKey($review->id)->lockForUpdate()->firstOrFail();
                if ($source !== null && $review->content_edited_at !== null) {
                    return ['action' => 'preserved_admin_edit', 'review_id' => $review->id, 'source_id' => $source->id];
                }
            }

            if ($review === null) {
                $review = $this->createReview($batch, $record, $confidence);
            } elseif ($source === null && $duplicateMatch === null) {
                if ($confidence === 'high') {
                    $this->syncReview($review, $batch, $record, true);
                } else {
                    $this->syncReview($review, $batch, $record, false);
                }
            } elseif ($source !== null && $confidence === 'high') {
                $this->syncReview($review, $batch, $record, true);
            }

            $source = $review->sources()->updateOrCreate(
                $sourceLookup,
                [
                    'review_import_batch_id' => $batch->id,
                    'source_label' => $record['source_label'] ?? $batch->source_label,
                    'source_url' => $record['source_url'] ?? null,
                    'is_public' => (bool) ($record['is_public'] ?? false),
                    'external_id' => $record['external_id'] ?? null,
                    'external_author_name' => $record['external_author_name'] ?? ($record['display_name'] ?? null),
                    'external_written_at' => $this->parseTimestamp($record['external_written_at'] ?? null),
                    'captured_at' => $this->parseTimestamp($record['external_written_at'] ?? null) ?? now(),
                    'raw_text' => $rawText,
                    'raw_payload' => $record['raw_payload'] ?? $record,
                    'content_hash' => $this->normalizer->hash($rawText),
                ],
            );

            $evidenceRows = $this->evidenceRows($record);
            foreach ($evidenceRows as $evidenceRow) {
                ReviewEvidence::query()->updateOrCreate(
                    [
                        'review_source_id' => $source->id,
                        'type' => $evidenceRow['type'],
                        'sha256' => $evidenceRow['sha256'],
                    ],
                    [
                        'review_id' => $review->id,
                        'review_import_batch_id' => $batch->id,
                        'file_path' => $evidenceRow['file_path'] ?? null,
                        'storage_url' => $evidenceRow['storage_url'] ?? null,
                        'mime_type' => $evidenceRow['mime_type'] ?? null,
                        'is_public' => (bool) ($evidenceRow['is_public'] ?? false),
                        'caption' => $evidenceRow['caption'] ?? null,
                        'provenance' => $evidenceRow['provenance'] ?? ($sourceLookup['source_type'] ?? null),
                        'captured_at' => $this->parseTimestamp($evidenceRow['captured_at'] ?? null)
                            ?? $this->parseTimestamp($record['external_written_at'] ?? null)
                            ?? now(),
                        'metadata' => $evidenceRow['metadata'] ?? [],
                    ],
                );
            }

            $action = $sourceWasNew
                ? ($duplicateMatch ? 'linked_duplicate' : ($confidence === 'high' ? 'created_review' : 'pending_review'))
                : 'updated_source';

            return [
                'action' => $action,
                'review_id' => $review->id,
                'source_id' => $source->id,
            ];
        });
    }

    private function createReview(ReviewImportBatch $batch, array $record, string $confidence): Review
    {
        return Review::query()->create($this->reviewAttributes($batch, $record, $confidence));
    }

    private function syncReview(Review $review, ReviewImportBatch $batch, array $record, bool $published): void
    {
        $attributes = $this->reviewAttributes($batch, $record, $published ? 'high' : 'low');

        if ($this->shouldPreserveExistingText($review->text, $attributes['text'], $record)) {
            $attributes['text'] = $review->text;
        }

        $review->fill($attributes);
        $review->save();
    }

    private function shouldPreserveExistingText(?string $currentText, string $incomingText, array $record): bool
    {
        if (($record['raw_payload']['manual_text_reviewed'] ?? false) === true) {
            return false;
        }

        $currentText = trim((string) $currentText);
        $incomingText = trim($incomingText);

        if ($currentText === '' || $incomingText === '') {
            return false;
        }

        $incomingScore = $this->textArtifactScore($incomingText);
        $currentScore = $this->textArtifactScore($currentText);

        if ($incomingScore >= $currentScore + 3) {
            return true;
        }

        return $incomingScore > $currentScore && $this->textSimilarity($currentText, $incomingText) >= 0.95;
    }

    private function textArtifactScore(string $text): int
    {
        $score = 0;
        $patterns = [
            '/\bдля\s+углубление\b/ui',
            '/\bна слышала\b/ui',
            '/\bэт было\b/ui',
            '/\bкотор[а-яё]*тсоазу\b/ui',
            '/\bприходиться\b/ui',
            '/\bподготовится\b/ui',
            '/\bсессий\b/ui',
            '/\bэто был так\b/ui',
            '/\bвиде-материалы\b/ui',
            '/\bPdf\b/u',
            '/&\s*#039\s*;/u',
            '/\s-\s/u',
            '/\s\+\s/u',
            '/[а-яё]{18,}/ui',
        ];

        foreach ($patterns as $pattern) {
            $score += preg_match_all($pattern, $text);
        }

        $score += min(3, preg_match_all('/[А-Яа-яЁё][,.;:!?][А-Яа-яЁё]/u', $text));

        return $score;
    }

    private function textSimilarity(string $firstText, string $secondText): float
    {
        $firstText = $this->normalizer->normalize($firstText);
        $secondText = $this->normalizer->normalize($secondText);

        if ($firstText === '' || $secondText === '') {
            return 0.0;
        }

        similar_text($firstText, $secondText, $percent);

        return $percent / 100;
    }

    private function reviewAttributes(ReviewImportBatch $batch, array $record, string $confidence): array
    {
        $writtenAt = $this->parseTimestamp($record['external_written_at'] ?? null);
        $isPublished = $confidence === 'high';
        $rawText = $this->rawText($record['text'] ?? null);
        $displayText = $this->paragraphNormalizer->normalize($rawText);
        $subject = $this->resolveSubject($record['subject_slug'] ?? null);
        $course = $this->resolveCourse($subject, $record['course_slug'] ?? null);

        return [
            'course_id' => $course?->id,
            'subject_id' => $course ? null : $subject?->id,
            'text' => $displayText,
            'display_name' => $record['display_name'] ?? ($record['external_author_name'] ?? 'Анонимный автор'),
            'is_anonymous' => ! filled($record['display_name'] ?? $record['external_author_name'] ?? null),
            'status' => $isPublished ? Review::STATUS_PUBLISHED : Review::STATUS_DRAFT,
            'moderation_status' => $isPublished ? Review::MODERATION_APPROVED : Review::MODERATION_NEEDS_REVIEW,
            'publication_status' => $isPublished ? Review::PUBLICATION_PUBLISHED : Review::PUBLICATION_DRAFT,
            'dedupe_status' => $isPublished ? Review::DEDUPE_UNIQUE : Review::DEDUPE_UNCHECKED,
            'origin_type' => Review::ORIGIN_LEGACY_IMPORT,
            'created_from_import_batch_id' => $batch->id,
            'source' => 'legacy_ocr',
            'source_ref' => $record['source_ref'],
            'review_written_at' => $writtenAt,
            'published_at' => $isPublished ? ($writtenAt ?? now()) : null,
            'reviewed_at' => $isPublished ? now() : null,
            'reviewed_by' => null,
        ];
    }

    private function sourceLookupAttributes(ReviewImportBatch $batch, array $record): array
    {
        $sourceType = $batch->source_type;
        $sourceRef = $record['source_ref'] ?? null;
        $externalId = $record['external_id'] ?? null;

        if (filled($sourceRef)) {
            return [
                'source_type' => $sourceType,
                'source_ref' => $sourceRef,
            ];
        }

        if (filled($externalId)) {
            return [
                'source_type' => $sourceType,
                'external_id' => $externalId,
            ];
        }

        throw new InvalidArgumentException('External review source_ref or external_id is required.');
    }

    private function resolveSubject(mixed $slug): ?Subject
    {
        if (! is_string($slug) || trim($slug) === '') {
            return null;
        }

        return Subject::query()->where('slug', trim($slug))->first();
    }

    private function resolveCourse(?Subject $subject, mixed $slug): ?Course
    {
        if (! is_string($slug) || trim($slug) === '') {
            return null;
        }

        $courseSlug = trim($slug);
        $query = Course::query()->where('slug', $courseSlug);

        if ($subject) {
            return $query->where('subject_id', $subject->id)->first();
        }

        return $query->first();
    }

    private function evidenceRows(array $record): array
    {
        $evidence = $record['evidence'] ?? [];
        if (! is_array($evidence) || $evidence === []) {
            return [];
        }

        if (array_is_list($evidence)) {
            return array_values(array_filter(array_map([$this, 'normalizeEvidenceRow'], $evidence)));
        }

        return array_values(array_filter([$this->normalizeEvidenceRow($evidence)]));
    }

    private function normalizeEvidenceRow(mixed $evidence): ?array
    {
        if (! is_array($evidence)) {
            return null;
        }

        if (! isset($evidence['type'], $evidence['sha256'])) {
            return null;
        }

        return [
            'type' => (string) $evidence['type'],
            'sha256' => (string) $evidence['sha256'],
            'file_path' => $evidence['file_path'] ?? null,
            'storage_url' => $evidence['storage_url'] ?? null,
            'mime_type' => $evidence['mime_type'] ?? null,
            'is_public' => (bool) ($evidence['is_public'] ?? false),
            'caption' => $evidence['caption'] ?? null,
            'provenance' => $evidence['provenance'] ?? null,
            'captured_at' => $evidence['captured_at'] ?? null,
            'metadata' => $evidence['metadata'] ?? [],
        ];
    }

    private function normalizeConfidence(mixed $confidence): string
    {
        $value = is_string($confidence) ? strtolower(trim($confidence)) : '';

        if (! in_array($value, ['high', 'medium', 'low'], true)) {
            throw new InvalidArgumentException('External review confidence must be high, medium, or low.');
        }

        return $value;
    }

    private function normalizeText(mixed $text): string
    {
        return $this->normalizer->normalize(is_string($text) ? $text : null);
    }

    private function rawText(mixed $text): string
    {
        return is_string($text) ? trim($text) : '';
    }

    private function parseTimestamp(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return Carbon::parse($value)->utc();
    }
}
