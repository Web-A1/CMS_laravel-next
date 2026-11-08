<?php

namespace Tests\Feature;

use App\Exceptions\AdminReviewException;
use App\Jobs\DeleteReviewImage;
use App\Models\Review;
use App\Models\ReviewEvidence;
use App\Models\ReviewImageAsset;
use App\Models\ReviewImageDeletion;
use App\Models\ReviewImageMigration;
use App\Models\ReviewImportBatch;
use App\Models\ReviewMedia;
use App\Models\ReviewModerationEvent;
use App\Models\User;
use App\Services\Reviews\AdminReviewService;
use App\Services\Reviews\ExternalReviewImportService;
use App\Services\Reviews\ReviewImageRemoval;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReviewEditingTest extends TestCase
{
    use RefreshDatabase;

    private function input(array $overrides = []): array
    {
        return array_replace(['displayName' => 'Мария', 'isAnonymous' => false, 'text' => 'Исправленный отзыв.',
            'reviewWrittenAt' => '2026-01-15', 'scope' => 'all', 'subjectId' => null, 'courseId' => null, 'revision' => 0], $overrides);
    }

    public function test_imported_published_review_can_be_edited_without_unpublishing_or_changing_its_origin(): void
    {
        $actor = User::factory()->create(['role' => 'admin_full']);
        Sanctum::actingAs($actor);
        $review = Review::create(['source' => 'legacy_ocr', 'source_ref' => 'archive-001', 'origin_type' => 'legacy_import',
            'status' => 'published', 'publication_status' => 'published', 'moderation_status' => 'approved',
            'dedupe_status' => 'unique', 'published_at' => now()->subDay(), 'text' => 'Исходный отзыв.', 'display_name' => 'Автор']);
        $evidence = ReviewEvidence::create(['review_id' => $review->id, 'type' => 'webarchive', 'is_public' => false,
            'file_path' => 'private/original.webarchive', 'metadata' => ['original' => true]]);
        $beforeEvidence = $evidence->fresh()->getRawOriginal();
        $publishedAt = $review->published_at;
        $this->getJson('/api/v1/admin/reviews/'.$review->id)->assertOk()->assertJsonPath('data.canEdit', true);
        $data = $this->patchJson('/api/v1/admin/reviews/'.$review->id, $this->input(), ['Idempotency-Key' => 'edit-import'])
            ->assertOk()->assertJsonPath('data.publicationStatus', 'published')->assertJsonPath('data.canPublish', false)->json('data');
        $this->getJson('/api/v1/reviews/'.$review->id)->assertOk()->assertJsonPath('data.text', 'Исправленный отзыв.');
        $review->refresh();
        $this->assertSame(['legacy_ocr', 'archive-001', 'legacy_import', 'approved', 'unique'],
            [$review->source, $review->source_ref, $review->origin_type, $review->moderation_status, $review->dedupe_status]);
        $this->assertTrue($publishedAt->equalTo($review->published_at));
        $this->assertSame($beforeEvidence, $evidence->fresh()->getRawOriginal());
        $changes = ReviewModerationEvent::sole()->metadata['changes'];
        $this->assertSame(['before' => 'Исходный отзыв.', 'after' => 'Исправленный отзыв.'], $changes['text']);
        $this->assertSame($actor->id, ReviewModerationEvent::sole()->actor_user_id);
        $this->patchJson('/api/v1/admin/reviews/'.$review->id, $this->input(), ['Idempotency-Key' => 'edit-import'])
            ->assertOk()->assertJsonPath('data.revision', $data['revision']);
        $this->assertDatabaseCount('review_moderation_events', 1);
    }

    private function asset(User $owner, ?Review $review = null, string $kind = 'screenshot'): ReviewImageAsset
    {
        $id = (string) Str::uuid();
        $prefix = $kind === 'avatar' ? 'review-authors/' : 'review-screenshots/';
        $paths = [$prefix.$id.'.webp', $prefix.$id.'/96.avif', $prefix.$id.'/96.webp'];
        $variants = [];
        foreach ($paths as $path) {
            Storage::disk('review-test')->put($path, 'synthetic-image');
            $variants[] = ['path' => $path, 'width' => 96, 'height' => 192,
                'format' => str_ends_with($path, '.avif') ? 'avif' : 'webp', 'bytes' => 15];
        }

        return ReviewImageAsset::create(['id' => $id, 'owner_id' => $owner->id, 'review_id' => $review?->id,
            'kind' => $kind, 'status' => 'ready', 'disk' => 'review-test', 'primary_path' => $paths[0],
            'source_path' => null, 'source_mime' => 'image/png', 'width' => 96, 'height' => 192, 'variants' => $variants]);
    }

    private function mediaSetup(): array
    {
        Queue::fake([DeleteReviewImage::class]);
        Storage::fake('local');
        Storage::fake('public');
        Storage::fake('review-test', ['url' => 'https://media.example.test']);
        $owner = User::factory()->create(['role' => 'admin_full']);
        Sanctum::actingAs($owner);
        $review = Review::create(['source' => 'legacy_ocr', 'origin_type' => 'legacy_import', 'display_name' => 'Автор',
            'text' => 'Исходный текст.', 'status' => 'published', 'publication_status' => 'published',
            'published_at' => now()->subDay(), 'review_written_at' => '2026-01-10', 'dedupe_status' => 'unique']);
        $asset = $this->asset($owner, $review);

        return [$owner, $review, $asset];
    }

    public function test_parameter_patch_preserves_manual_content_and_replays_only_the_same_intent(): void
    {
        [$owner, $review] = $this->mediaSetup();
        $before = $review->getAttributes();
        $service = app(AdminReviewService::class);
        $input = ['showOnHome' => true, 'revision' => 0];
        $saved = $service->execute($owner, 'parameters', $review->id, $input, 'review-parameter-intent');
        $revision = (int) $saved->moderationEvents()->max('id');
        $this->assertGreaterThan(0, $revision);
        $this->assertTrue($saved->show_on_home);
        foreach (['text', 'display_name', 'content_edited_at', 'publication_status', 'origin_type', 'reviewed_at'] as $field) {
            $this->assertSame($before[$field] ?? null, $saved->getAttributes()[$field] ?? null);
        }
        $service->execute($owner, 'parameters', $review->id, $input, 'review-parameter-intent');
        $service->execute($owner, 'parameters', $review->id, ['showOnHome' => true, 'revision' => $revision], 'review-parameter-noop');
        $this->assertDatabaseCount('review_moderation_events', 1);
        $this->assertDatabaseCount('review_image_deletions', 0);
        try {
            $service->execute($owner, 'parameters', $review->id, ['showOnHome' => false, 'revision' => 0], 'review-stale');
            $this->fail('A stale revision must be refused.');
        } catch (AdminReviewException $exception) {
            $this->assertSame('ADMIN_REVIEW_REVISION_CONFLICT', $exception->errorCode);
        }
    }

    public function test_parameter_patch_rejects_manual_fields_and_incomplete_binding_without_writes(): void
    {
        [$owner, $review] = $this->mediaSetup();
        $before = $review->fresh()->getAttributes();
        foreach ([['text' => 'Чужой ручной draft'], ['scope' => 'subject']] as $patch) {
            try {
                app(AdminReviewService::class)->execute($owner, 'parameters', $review->id, $patch + ['revision' => 0], Str::uuid()->toString());
                $this->fail('An invalid patch must be refused.');
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
            }
        }
        $this->assertSame($before, $review->fresh()->getAttributes());
        $this->assertDatabaseCount('review_moderation_events', 0);
    }

    public function test_photo_parameter_attaches_ready_owned_asset_without_using_manual_draft(): void
    {
        [$owner, $review] = $this->mediaSetup();
        $photo = $this->asset($owner, null, 'avatar');
        $saved = app(AdminReviewService::class)->execute($owner, 'parameters', $review->id,
            ['authorPhotoPath' => $photo->primary_path, 'revision' => 0], 'review-ready-photo');
        $this->assertSame($photo->primary_path, $saved->author_photo_path);
        $this->assertSame($review->id, $photo->fresh()->review_id);
        $this->assertSame($review->text, $saved->text);
        $this->assertSame($review->display_name, $saved->display_name);
        $this->assertSame($review->publication_status, $saved->publication_status);
        $this->assertDatabaseCount('review_moderation_events', 1);
    }

    public function test_normalized_update_no_op_preserves_content_media_source_and_revision(): void
    {
        [$owner, $review, $asset] = $this->mediaSetup();
        ReviewMedia::create(['review_id' => $review->id, 'type' => 'screenshot', 'url' => '/storage/'.$asset->primary_path,
            'width' => $asset->width, 'height' => $asset->height, 'sort_order' => 0]);
        $beforeReview = $review->fresh()->getAttributes();
        $beforeAsset = $asset->fresh()->getAttributes();
        $beforeMedia = ReviewMedia::sole()->getAttributes();
        $this->travel(10)->seconds();
        app(AdminReviewService::class)->execute($owner, 'update', $review->id, $this->input([
            'displayName' => ' Автор ', 'text' => ' Исходный текст. ', 'reviewWrittenAt' => '2026-01-10',
            'authorPhotoPath' => null, 'sourceUrl' => null, 'showOnHome' => false, 'screenshotPaths' => [$asset->primary_path],
        ]), 'normalized-review-noop');
        $this->assertSame($beforeReview, $review->fresh()->getAttributes());
        $this->assertSame($beforeAsset, $asset->fresh()->getAttributes());
        $this->assertSame($beforeMedia, ReviewMedia::sole()->getAttributes());
        $this->assertDatabaseCount('review_moderation_events', 0);
        $this->assertDatabaseCount('review_sources', 0);
        $this->assertDatabaseCount('review_image_deletions', 0);
        Queue::assertNothingPushed();
    }

    public function test_removing_imported_evidence_removes_all_versions_and_never_restores_legacy_fallback(): void
    {
        [$owner, $review, $asset] = $this->mediaSetup();
        ReviewMedia::create(['review_id' => $review->id, 'type' => 'legacy_screenshot', 'url' => '/storage/'.$asset->primary_path]);
        $evidence = ReviewEvidence::create(['review_id' => $review->id, 'type' => 'image', 'is_public' => true,
            'storage_url' => '/storage/'.$asset->primary_path, 'sha256' => str_repeat('a', 64), 'provenance' => 'legacy_site']);
        $original = $evidence->fresh()->getRawOriginal();
        Storage::disk('public')->put('reviews/old.webp', 'old-working-file');
        $migration = ReviewImageMigration::create(['review_id' => $review->id, 'asset_id' => $asset->id,
            'source_key' => hash('sha256', 'old-working-file'), 'source_url' => '/storage/reviews/old.webp',
            'source_sha256' => hash('sha256', 'old-working-file'), 'references' => [], 'switched_at' => now()]);
        $this->getJson('/api/v1/admin/reviews/'.$review->id)->assertOk()->assertJsonPath('data.screenshotPaths.0', $asset->primary_path);
        $this->patchJson('/api/v1/admin/reviews/'.$review->id, $this->input(['screenshotPaths' => []]), ['Idempotency-Key' => 'remove-import'])
            ->assertOk()->assertJsonCount(0, 'data.screenshotPaths')->assertJsonPath('data.hasPhoto', false);
        $this->assertTrue($review->fresh()->media_managed);
        $this->assertSame($original, $evidence->fresh()->getRawOriginal());
        $this->getJson('/api/v1/reviews/'.$review->id.'?image_variants=1')->assertOk()->assertJsonCount(0, 'data.media');
        $this->assertSame('deleting', $asset->fresh()->status);
        $this->assertSame(1, app(ReviewImageRemoval::class)->cleanup());
        $this->assertSame([], Storage::disk('review-test')->allFiles());
        $this->assertDatabaseCount('review_image_assets', 0);
        $this->assertDatabaseCount('review_image_deletions', 0);
        Storage::disk('public')->assertMissing('reviews/old.webp');
        $this->assertNotNull($migration->fresh()->retired_at);
        $this->artisan('reviews:migrate-images', ['--verify' => true])->assertSuccessful();
        $this->getJson('/api/v1/admin/reviews/'.$review->id)->assertOk()->assertJsonCount(0, 'data.screenshotPaths');
        $this->getJson('/api/v1/reviews/'.$review->id)->assertOk()->assertJsonCount(0, 'data.media');
        $this->assertSame(0, app(ReviewImageRemoval::class)->cleanup());
    }

    public function test_replacement_and_avatar_removal_keep_only_the_saved_images_and_preserve_source(): void
    {
        [$owner, $review, $old] = $this->mediaSetup();
        ReviewMedia::create(['review_id' => $review->id, 'type' => 'legacy_screenshot', 'url' => '/storage/'.$old->primary_path]);
        $avatar = $this->asset($owner, $review, 'avatar');
        $review->update(['author_photo_path' => $avatar->primary_path]);
        $new = $this->asset($owner);
        $this->patchJson('/api/v1/admin/reviews/'.$review->id,
            $this->input(['authorPhotoPath' => null, 'screenshotPaths' => [$new->primary_path]]), ['Idempotency-Key' => 'replace'])
            ->assertOk()->assertJsonPath('data.authorPhotoPath', null)->assertJsonPath('data.screenshotPaths', [$new->primary_path]);
        $this->assertSame(2, app(ReviewImageRemoval::class)->cleanup());
        foreach ([$old, $avatar] as $asset) {
            foreach ($asset->variants as $variant) {
                Storage::disk('review-test')->assertMissing($variant['path']);
            }
        }
        foreach ($new->variants as $variant) {
            Storage::disk('review-test')->assertExists($variant['path']);
        }
        $this->getJson('/api/v1/reviews/'.$review->id.'?image_variants=1')->assertOk()
            ->assertJsonCount(1, 'data.media')->assertJsonPath('data.media.0.url', $new->url())->assertJsonPath('data.authorPhotoUrl', null);
        $this->assertSame('legacy_ocr', $review->fresh()->source);
    }

    public function test_rollback_stale_revision_and_foreign_paths_cannot_remove_existing_files(): void
    {
        [$owner, $review, $asset] = $this->mediaSetup();
        ReviewMedia::create(['review_id' => $review->id, 'type' => 'legacy_screenshot', 'url' => '/storage/'.$asset->primary_path]);
        $foreignReview = Review::create(['source' => 'admin', 'display_name' => 'Другой автор', 'text' => 'Другой отзыв.']);
        $foreign = ReviewMedia::create(['review_id' => $foreignReview->id, 'type' => 'screenshot', 'url' => '/storage/reviews/foreign.webp']);
        $this->patchJson('/api/v1/admin/reviews/'.$review->id, $this->input(['screenshotPaths' => ['existing:review_media:'.$foreign->id]]),
            ['Idempotency-Key' => 'foreign'])->assertUnprocessable();
        $this->patchJson('/api/v1/admin/reviews/'.$review->id, $this->input(['revision' => 100, 'screenshotPaths' => []]),
            ['Idempotency-Key' => 'stale'])->assertConflict();
        ReviewModerationEvent::creating(static function (): void {
            throw new \RuntimeException('Synthetic audit failure');
        });
        try {
            $this->patchJson('/api/v1/admin/reviews/'.$review->id, $this->input(['screenshotPaths' => []]),
                ['Idempotency-Key' => 'rollback'])->assertStatus(503);
        } finally {
            ReviewModerationEvent::flushEventListeners();
        }
        $this->assertDatabaseCount('review_image_deletions', 0);
        $this->assertSame('ready', $asset->fresh()->status);
        $this->assertFalse($review->fresh()->media_managed);
        foreach ($asset->variants as $variant) {
            Storage::disk('review-test')->assertExists($variant['path']);
        }
    }

    public function test_storage_failure_is_durable_and_retries_without_restoring_removed_media(): void
    {
        [$owner, $review, $asset] = $this->mediaSetup();
        ReviewMedia::create(['review_id' => $review->id, 'type' => 'legacy_screenshot', 'url' => '/storage/'.$asset->primary_path]);
        $this->patchJson('/api/v1/admin/reviews/'.$review->id, $this->input(['screenshotPaths' => []]),
            ['Idempotency-Key' => 'remove'])->assertOk();
        $entry = ReviewImageDeletion::sole();
        $disk = Storage::disk('review-test');
        $failing = \Mockery::mock($disk);
        $failing->shouldReceive('deleteDirectory')->andReturn(false);
        Storage::set('review-test', $failing);
        try {
            app(ReviewImageRemoval::class)->run($entry->id);
            $this->fail('A failed deletion must remain retryable.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('не подтвердило', $exception->getMessage());
        } finally {
            Storage::set('review-test', $disk);
        }
        $this->assertNotNull($entry->fresh()->error);
        $this->getJson('/api/v1/reviews/'.$review->id)->assertOk()->assertJsonCount(0, 'data.media');
        $this->assertTrue(app(ReviewImageRemoval::class)->run($entry->id));
        $this->assertFalse(app(ReviewImageRemoval::class)->run($entry->id));
        $this->assertSame([], $disk->allFiles());
    }

    public function test_shared_image_and_private_original_are_not_deleted(): void
    {
        [$owner, $review, $asset] = $this->mediaSetup();
        ReviewMedia::create(['review_id' => $review->id, 'type' => 'legacy_screenshot', 'url' => '/storage/'.$asset->primary_path]);
        $other = Review::create(['source' => 'admin', 'display_name' => 'Другой автор', 'text' => 'Другой отзыв.']);
        ReviewMedia::create(['review_id' => $other->id, 'type' => 'screenshot', 'url' => $asset->url()]);
        $legacyPhoto = 'review-authors/legacy.png';
        Storage::disk('public')->put($legacyPhoto, 'original');
        $review->update(['author_photo_path' => $legacyPhoto]);
        ReviewEvidence::create(['review_id' => $review->id, 'type' => 'image', 'is_public' => false, 'file_path' => $legacyPhoto]);
        $this->patchJson('/api/v1/admin/reviews/'.$review->id, $this->input(['authorPhotoPath' => null, 'screenshotPaths' => []]),
            ['Idempotency-Key' => 'remove-shared'])->assertOk();
        $this->assertDatabaseCount('review_image_deletions', 0);
        Storage::disk('public')->assertExists($legacyPhoto);
        foreach ($asset->variants as $variant) {
            Storage::disk('review-test')->assertExists($variant['path']);
        }
    }

    public function test_last_shared_reference_removal_also_deletes_the_originally_other_review_owned_asset(): void
    {
        [$owner, $review, $asset] = $this->mediaSetup();
        ReviewMedia::create(['review_id' => $review->id, 'type' => 'legacy_screenshot', 'url' => '/storage/'.$asset->primary_path]);
        $other = Review::create(['source' => 'admin', 'display_name' => 'Другой автор', 'text' => 'Другой отзыв.']);
        ReviewMedia::create(['review_id' => $other->id, 'type' => 'screenshot', 'url' => $asset->url()]);
        $this->patchJson('/api/v1/admin/reviews/'.$review->id, $this->input(['screenshotPaths' => []]),
            ['Idempotency-Key' => 'remove-first-reference'])->assertOk();
        $this->assertDatabaseCount('review_image_deletions', 0);
        $this->patchJson('/api/v1/admin/reviews/'.$other->id, $this->input(['screenshotPaths' => []]),
            ['Idempotency-Key' => 'remove-last-shared-reference'])->assertOk();
        $this->assertDatabaseCount('review_image_deletions', 1);
        app(ReviewImageRemoval::class)->cleanup();
        $this->assertDatabaseMissing('review_image_assets', ['id' => $asset->id]);
        $this->assertSame([], Storage::disk('review-test')->allFiles());
    }

    public function test_existing_public_file_roundtrips_without_reencoding_and_is_deleted_when_removed(): void
    {
        [$owner, $review] = $this->mediaSetup();
        Storage::disk('public')->put('reviews/existing.webp', 'legacy-working-image');
        $evidence = ReviewEvidence::create(['review_id' => $review->id, 'type' => 'image', 'is_public' => true,
            'storage_url' => '/storage/reviews/existing.webp']);
        $ref = 'existing:review_evidence:'.$evidence->id;
        $data = $this->patchJson('/api/v1/admin/reviews/'.$review->id, $this->input(['screenshotPaths' => [$ref]]),
            ['Idempotency-Key' => 'keep-existing'])->assertOk()->assertJsonCount(1, 'data.screenshotPaths')->json('data');
        $this->assertSame('legacy-working-image', Storage::disk('public')->get('reviews/existing.webp'));
        $this->getJson('/api/v1/reviews/'.$review->id)->assertOk()->assertJsonCount(1, 'data.media')
            ->assertJsonPath('data.media.0.url', '/storage/reviews/existing.webp');
        $this->patchJson('/api/v1/admin/reviews/'.$review->id,
            $this->input(['revision' => $data['revision'], 'screenshotPaths' => []]), ['Idempotency-Key' => 'remove-existing'])->assertOk();
        app(ReviewImageRemoval::class)->cleanup();
        Storage::disk('public')->assertMissing('reviews/existing.webp');
        $this->assertDatabaseHas('review_evidence', ['id' => $evidence->id, 'storage_url' => '/storage/reviews/existing.webp']);
        $this->getJson('/api/v1/admin/reviews?photo=yes')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/admin/reviews?photo=no')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_reimport_preserves_admin_content_and_original_source_payload(): void
    {
        $owner = User::factory()->create(['role' => 'admin_full']);
        Sanctum::actingAs($owner);
        $batch = ReviewImportBatch::create(['source_type' => 'legacy_site', 'batch_ref' => 'test-admin-edit',
            'source_label' => 'Исторический источник', 'source_path' => 'source/test', 'status' => 'pending']);
        $record = ['source_ref' => 'legacy-review-edit', 'text' => 'Исходный текст из импорта.', 'display_name' => 'Исторический автор',
            'confidence' => 'high', 'external_written_at' => '2026-01-10'];
        $service = app(ExternalReviewImportService::class);
        $first = $service->importRecord($batch, $record);
        $review = Review::findOrFail($first['review_id']);
        $raw = $review->sources()->sole()->raw_payload;
        $this->patchJson('/api/v1/admin/reviews/'.$review->id,
            $this->input(['screenshotPaths' => [], 'displayName' => 'eXact_имя']), ['Idempotency-Key' => 'admin-edit'])->assertOk();
        $result = $service->importRecord($batch, array_replace($record, ['text' => 'Другой текст из старого импорта.']));
        $this->assertSame('preserved_admin_edit', $result['action']);
        $this->assertSame('Исправленный отзыв.', $review->fresh()->text);
        $this->assertSame($raw, $review->sources()->sole()->raw_payload);
        $this->getJson('/api/v1/reviews/'.$review->id)->assertOk()->assertJsonPath('data.displayName', 'eXact_имя');
        $this->assertDatabaseCount('review_moderation_events', 1);
    }
}
