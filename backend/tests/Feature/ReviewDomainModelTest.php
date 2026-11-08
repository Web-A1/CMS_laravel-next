<?php

namespace Tests\Feature;

use App\Models\Review;
use App\Models\ReviewDuplicateCandidate;
use App\Models\ReviewEvidence;
use App\Models\ReviewImportBatch;
use App\Models\ReviewModerationEvent;
use App\Models\ReviewSource;
use App\Services\Reviews\ReviewParagraphNormalizer;
use Database\Seeders\TelegramReviewsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReviewDomainModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_review_tracks_sources_evidence_batches_duplicates_and_moderation_events(): void
    {
        $batch = ReviewImportBatch::query()->create([
            'source_type' => ReviewSource::TYPE_TELEGRAM,
            'batch_ref' => 'telegram:test-batch',
            'status' => ReviewImportBatch::STATUS_COMPLETED,
            'records_total' => 1,
            'records_updated' => 1,
            'stats' => ['reviews' => 1],
        ]);

        $review = Review::query()->create([
            'text' => 'Канонический текст отзыва.',
            'display_name' => 'Анна',
            'status' => Review::STATUS_PUBLISHED,
            'source' => 'legacy_ocr',
            'source_ref' => 'telegram:test-review',
            'published_at' => now()->subDay(),
            'created_from_import_batch_id' => $batch->id,
        ]);

        $source = $review->sources()->create([
            'review_import_batch_id' => $batch->id,
            'source_type' => ReviewSource::TYPE_TELEGRAM,
            'source_ref' => 'telegram:test-review',
            'source_label' => 'Telegram export',
            'is_public' => false,
            'external_author_name' => 'raw anna',
            'raw_text' => 'Сырой текст отзыва.',
            'raw_payload' => ['message_id' => 123],
            'content_hash' => hash('sha256', 'Сырой текст отзыва.'),
        ]);

        $evidence = $source->evidence()->create([
            'review_id' => $review->id,
            'review_import_batch_id' => $batch->id,
            'type' => 'screenshot',
            'file_path' => 'photos/review.jpg',
            'caption' => 'Скриншот отзыва',
            'provenance' => 'telegram_export_media',
            'metadata' => ['width' => 600],
        ]);

        $candidateReview = Review::query()->create([
            'text' => 'Похожий текст отзыва.',
            'display_name' => 'Анна',
            'status' => Review::STATUS_DRAFT,
            'source' => 'legacy_manual',
        ]);

        $candidate = ReviewDuplicateCandidate::query()->create([
            'review_id' => $review->id,
            'candidate_review_id' => $candidateReview->id,
            'similarity_score' => 0.9200,
            'match_type' => 'normalized_text',
            'status' => ReviewDuplicateCandidate::STATUS_PENDING,
            'signals' => ['author' => 'similar'],
        ]);

        $event = ReviewModerationEvent::query()->create([
            'review_id' => $review->id,
            'event_type' => 'approved_for_publication',
            'from_moderation_status' => Review::MODERATION_PENDING,
            'to_moderation_status' => Review::MODERATION_APPROVED,
            'from_publication_status' => Review::PUBLICATION_DRAFT,
            'to_publication_status' => Review::PUBLICATION_PUBLISHED,
        ]);

        $review->refresh();

        $this->assertSame(Review::MODERATION_APPROVED, $review->moderation_status);
        $this->assertSame(Review::PUBLICATION_PUBLISHED, $review->publication_status);
        $this->assertSame(Review::DEDUPE_UNCHECKED, $review->dedupe_status);
        $this->assertSame(Review::ORIGIN_LEGACY_IMPORT, $review->origin_type);
        $this->assertTrue($review->importBatch->is($batch));
        $this->assertTrue($review->sources()->first()->is($source));
        $this->assertTrue($review->evidence()->first()->is($evidence));
        $this->assertTrue($review->duplicateCandidates()->first()->is($candidate));
        $this->assertTrue($review->moderationEvents()->first()->is($event));
        $this->assertFalse($source->hasPublicUrl());

        $privateUrlSource = $review->sources()->create([
            'source_type' => ReviewSource::TYPE_MANUAL,
            'source_ref' => 'manual:private-url',
            'source_url' => 'https://example.com/private-evidence',
            'is_public' => false,
        ]);

        $publicUrlSource = $review->sources()->create([
            'source_type' => ReviewSource::TYPE_LEGACY_SITE,
            'source_ref' => 'legacy:public-url',
            'source_url' => 'https://example.com/reviews',
            'is_public' => true,
        ]);

        $this->assertFalse($privateUrlSource->hasPublicUrl());
        $this->assertTrue($publicUrlSource->hasPublicUrl());
    }

    public function test_public_reviews_respect_workflow_and_dedupe_statuses(): void
    {
        Review::query()->create([
            'text' => 'Публичный отзыв.',
            'display_name' => 'Публичный автор',
            'status' => Review::STATUS_PUBLISHED,
            'source' => 'dev_seed',
            'published_at' => now()->subDay(),
        ]);

        Review::query()->create([
            'text' => 'Ожидает модерации.',
            'display_name' => 'Pending',
            'status' => Review::STATUS_PUBLISHED,
            'moderation_status' => Review::MODERATION_PENDING,
            'publication_status' => Review::PUBLICATION_PUBLISHED,
            'source' => 'legacy_ocr',
            'published_at' => now()->subDay(),
        ]);

        Review::query()->create([
            'text' => 'Подтверждённый дубль.',
            'display_name' => 'Duplicate',
            'status' => Review::STATUS_PUBLISHED,
            'moderation_status' => Review::MODERATION_APPROVED,
            'publication_status' => Review::PUBLICATION_PUBLISHED,
            'dedupe_status' => Review::DEDUPE_DUPLICATE,
            'source' => 'legacy_ocr',
            'published_at' => now()->subDay(),
        ]);

        $this->getJson('/api/v1/reviews')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.displayName', 'Публичный автор');
    }

    public function test_telegram_reviews_seeder_records_import_sources_and_private_evidence(): void
    {
        $this->seed(TelegramReviewsSeeder::class);

        $this->assertDatabaseHas('review_import_batches', [
            'batch_ref' => 'telegram:ChatExport_2026-06-17',
            'status' => ReviewImportBatch::STATUS_COMPLETED,
        ]);

        $review = Review::query()
            ->where('source_ref', 'telegram:tr-001')
            ->firstOrFail();

        $source = $review->sources()->firstOrFail();

        $this->assertSame(ReviewSource::TYPE_TELEGRAM, $source->source_type);
        $this->assertFalse($source->is_public);
        $this->assertNull($source->source_url);
        $this->assertSame(
            app(ReviewParagraphNormalizer::class)->normalize($source->raw_text),
            $review->text,
        );
        $this->assertStringNotContainsString("\n\n", $source->raw_text);

        $this->assertDatabaseHas('review_evidence', [
            'review_id' => $review->id,
            'review_source_id' => $source->id,
            'type' => 'screenshot',
            'storage_url' => null,
        ]);

        $this->assertGreaterThan(0, ReviewEvidence::query()->count());
    }
}
