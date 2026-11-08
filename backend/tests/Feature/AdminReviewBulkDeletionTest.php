<?php

namespace Tests\Feature;

use App\Exceptions\AdminReviewException;
use App\Jobs\DeleteReviewImage;
use App\Models\AdminOperationEvent;
use App\Models\Review;
use App\Models\ReviewImageAsset;
use App\Models\ReviewImageDeletion;
use App\Models\User;
use App\Services\Reviews\AdminReviewDeletion;
use App\Services\Reviews\AdminReviewService;
use App\Services\Reviews\ReviewImageRemoval;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AdminReviewBulkDeletionTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        Storage::fake('review-media-s3');
        Storage::fake('local');
        Storage::fake('public');
        $actor = User::factory()->create(['role' => 'admin_full']);
        $reviews = [];
        foreach (range(1, 3) as $n) {
            $review = Review::create(['source' => 'legacy_ocr', 'source_ref' => 'bulk-review-'.$n,
                'origin_type' => 'legacy_import', 'display_name' => 'Автор '.$n, 'text' => 'Текст отзыва '.$n,
                'status' => 'published', 'published_at' => now(), 'review_written_at' => '2026-01-15']);
            $source = $review->sources()->create(['source_type' => 'legacy_site', 'source_ref' => 'bulk-review-'.$n, 'raw_text' => $review->text]);
            $path = 'review-evidence/bulk-'.$n.'.HEIC';
            Storage::disk('local')->put($path, 'private original');
            $source->evidence()->create(['review_id' => $review->id, 'type' => 'image', 'is_public' => false, 'file_path' => $path]);
            $review->moderationEvents()->create(['event_type' => 'admin_update', 'actor_user_id' => $actor->id]);
            $reviews[] = $review;
        }

        return [$actor, $reviews];
    }

    private function image(Review $review): ReviewImageAsset
    {
        $id = (string) Str::uuid();
        $paths = ['review-screenshots/'.$id.'.webp', 'review-screenshots/'.$id.'/96.avif'];
        foreach ($paths as $path) {
            Storage::disk('review-media-s3')->put($path, 'image');
        }
        $asset = ReviewImageAsset::create(['id' => $id, 'review_id' => $review->id, 'kind' => 'screenshot', 'status' => 'ready',
            'disk' => 'review-media-s3', 'source_mime' => 'image/heic', 'primary_path' => $paths[0], 'width' => 96, 'height' => 192,
            'variants' => array_map(fn ($path) => ['path' => $path, 'width' => 96, 'height' => 192, 'format' => pathinfo($path, PATHINFO_EXTENSION)], $paths)]);
        $review->media()->create(['type' => 'legacy_screenshot', 'url' => $asset->url()]);

        return $asset;
    }

    private function items(array $reviews): array
    {
        return array_map(fn (Review $r) => ['id' => $r->id, 'revision' => (int) $r->moderationEvents()->max('id')], $reviews);
    }

    public function test_bulk_delete_cleans_selected_images_and_content_preserves_other_reviews_and_replays_exactly(): void
    {
        [$actor, [$a, $b, $other]] = $this->fixture();
        $this->image($a);
        $this->image($b);
        $retained = $this->image($other);
        $items = $this->items([$a, $b]);
        $service = app(AdminReviewDeletion::class);
        $result = $service->deleteMany($actor, $items, '2', 'bulk-delete');
        $this->assertSame(['deleted' => true, 'ids' => [$a->id, $b->id], 'count' => 2], $result);
        foreach (['reviews', 'review_sources', 'review_evidence', 'review_media', 'review_moderation_events'] as $table) {
            $this->assertDatabaseCount($table, 1);
        }
        $this->assertDatabaseHas('reviews', ['id' => $other->id]);
        $this->assertSame(array_column($retained->variants, 'path'), Storage::disk('review-media-s3')->allFiles());
        $this->assertSame(['review-evidence/bulk-3.HEIC'], Storage::disk('local')->allFiles());
        $this->assertDatabaseCount('review_image_deletions', 0);
        $this->assertDatabaseCount('admin_operation_events', 3);
        $this->assertSame($result, $service->deleteMany($actor, array_reverse($items), '2', 'bulk-delete'));
        $this->assertDatabaseCount('admin_operation_events', 3);
        $this->assertStringNotContainsString($a->text, AdminOperationEvent::all()->toJson());
        $this->getJson('/api/v1/reviews/'.$a->id)->assertNotFound();
    }

    public function test_conflict_on_later_review_rolls_back_the_entire_group_and_its_file_jobs(): void
    {
        [$actor, [$a, $b]] = $this->fixture();
        $asset = $this->image($a);
        $items = $this->items([$a, $b]);
        $b->moderationEvents()->create(['event_type' => 'admin_update', 'actor_user_id' => $actor->id]);
        try {
            app(AdminReviewDeletion::class)->deleteMany($actor, $items, '2', 'stale-bulk');
            $this->fail('A stale selection must not be partly deleted.');
        } catch (AdminReviewException $exception) {
            $this->assertSame('ADMIN_REVIEW_REVISION_CONFLICT', $exception->errorCode);
        }
        $this->assertDatabaseCount('reviews', 3);
        $this->assertDatabaseCount('review_sources', 3);
        $this->assertDatabaseCount('review_evidence', 3);
        $this->assertDatabaseCount('admin_operation_events', 0);
        $this->assertDatabaseCount('review_image_deletions', 0);
        Storage::disk('review-media-s3')->assertExists(array_column($asset->variants, 'path'));
        $this->assertSame('ready', $asset->fresh()->status);
        $this->assertCount(3, Storage::disk('local')->allFiles());
    }

    public function test_audit_failure_during_the_second_deletion_rolls_back_previous_deletions(): void
    {
        [$actor, [$a, $b]] = $this->fixture();
        $asset = $this->image($a);
        AdminOperationEvent::creating(function (AdminOperationEvent $event) use ($b): void {
            if ($event->resource_id === $b->id) {
                throw new \RuntimeException('Synthetic second-review failure');
            }
        });
        try {
            app(AdminReviewDeletion::class)->deleteMany($actor, $this->items([$a, $b]), '2', 'audit-bulk');
            $this->fail('Audit failure must roll back the whole selection.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Synthetic second-review failure', $exception->getMessage());
        } finally {
            AdminOperationEvent::flushEventListeners();
        }
        $this->assertDatabaseCount('reviews', 3);
        $this->assertDatabaseCount('review_image_deletions', 0);
        $this->assertDatabaseCount('admin_operation_events', 0);
        Storage::disk('review-media-s3')->assertExists(array_column($asset->variants, 'path'));
        $this->assertSame('ready', $asset->fresh()->status);
    }

    public function test_validation_and_revoked_role_prevent_bulk_deletion(): void
    {
        [$actor, [$a, $b]] = $this->fixture();
        $items = $this->items([$a, $b]);
        foreach ([[$items, '1'], [[], '0'], [[$items[0], $items[0]], '2'], [array_fill(0, 101, $items[0]), '101']] as [$invalid, $confirmation]) {
            try {
                app(AdminReviewDeletion::class)->deleteMany($actor, $invalid, $confirmation, 'invalid-bulk');
                $this->fail('Invalid selection must be rejected.');
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
            }
        }
        $actor->update(['role' => 'admin_limited']);
        try {
            app(AdminReviewDeletion::class)->deleteMany($actor, $items, '2', 'revoked-bulk');
            $this->fail('Revoked permission must be rechecked.');
        } catch (AdminReviewException $exception) {
            $this->assertSame(403, $exception->httpStatus);
        }
        $this->assertDatabaseCount('reviews', 3);
        $this->assertDatabaseCount('admin_operation_events', 0);
    }

    public function test_same_bulk_key_with_different_selection_and_single_delete_cannot_delete_other_reviews(): void
    {
        [$actor, [$a, $b, $other]] = $this->fixture();
        $service = app(AdminReviewDeletion::class);
        $service->deleteMany($actor, $this->items([$a, $b]), '2', 'same-key');
        foreach ([fn () => $service->deleteMany($actor, $this->items([$other]), '1', 'same-key'),
            fn () => $service->delete($actor, $other->id, ['revision' => (int) $other->moderationEvents()->max('id'), 'confirmation' => (string) $other->id], 'same-key'),
            fn () => app(AdminReviewService::class)->execute($actor, 'create', null, ['displayName' => 'Новый автор', 'isAnonymous' => false,
                'text' => 'Новый текст', 'reviewWrittenAt' => '2026-01-15', 'scope' => 'all', 'subjectId' => null, 'courseId' => null], 'same-key')] as $attempt) {
            try {
                $attempt();
                $this->fail('A used bulk key must not authorize another deletion.');
            } catch (AdminReviewException $exception) {
                $this->assertSame('ADMIN_REVIEW_IDEMPOTENCY_CONFLICT', $exception->errorCode);
            }
        }
        $this->assertDatabaseHas('reviews', ['id' => $other->id]);
    }

    public function test_missing_review_prevents_deletion_of_the_rest_of_the_selection(): void
    {
        [$actor, [$a, $b]] = $this->fixture();
        $asset = $this->image($a);
        $items = $this->items([$a, $b]);
        $b->delete();
        try {
            app(AdminReviewDeletion::class)->deleteMany($actor, $items, '2', 'missing-bulk');
            $this->fail('A missing selected review must cancel the operation.');
        } catch (AdminReviewException $exception) {
            $this->assertSame(404, $exception->httpStatus);
        }
        $this->assertDatabaseHas('reviews', ['id' => $a->id]);
        $this->assertDatabaseCount('admin_operation_events', 0);
        $this->assertDatabaseCount('review_image_deletions', 0);
        Storage::disk('review-media-s3')->assertExists(array_column($asset->variants, 'path'));
    }

    public function test_processing_image_on_later_review_rolls_back_all_preceding_deletions(): void
    {
        [$actor, [$a, $b]] = $this->fixture();
        $first = $this->image($a);
        $this->image($b)->update(['status' => 'processing']);
        try {
            app(AdminReviewDeletion::class)->deleteMany($actor, $this->items([$a, $b]), '2', 'processing-bulk');
            $this->fail('An active encoder must cancel the whole selection.');
        } catch (AdminReviewException $exception) {
            $this->assertSame('ADMIN_REVIEW_STATE_CONFLICT', $exception->errorCode);
        }
        $this->assertDatabaseCount('reviews', 3);
        $this->assertDatabaseCount('admin_operation_events', 0);
        $this->assertDatabaseCount('review_image_deletions', 0);
        $this->assertSame('ready', $first->fresh()->status);
        Storage::disk('review-media-s3')->assertExists(array_column($first->variants, 'path'));
    }

    public function test_shared_selected_image_is_removed_only_after_its_last_selected_reference(): void
    {
        [$actor, [$a, $b]] = $this->fixture();
        $asset = $this->image($a);
        $b->media()->create(['type' => 'screenshot', 'url' => $asset->url()]);
        app(AdminReviewDeletion::class)->deleteMany($actor, $this->items([$a, $b]), '2', 'shared-selected');
        $this->assertDatabaseMissing('review_image_assets', ['id' => $asset->id]);
        $this->assertSame([], Storage::disk('review-media-s3')->allFiles());
    }

    public function test_shared_unselected_image_survives_and_storage_failure_can_retry_after_bulk_delete(): void
    {
        [$actor, [$a, $b, $other]] = $this->fixture();
        $shared = $this->image($a);
        $other->media()->create(['type' => 'screenshot', 'url' => $shared->url()]);
        $owned = $this->image($b);
        Queue::fake([DeleteReviewImage::class]);
        app(AdminReviewDeletion::class)->deleteMany($actor, $this->items([$a, $b]), '2', 'retry-bulk');
        $entry = ReviewImageDeletion::where('asset_id', $owned->id)->sole();
        $this->assertNull($entry->review_id);
        $disk = Storage::disk('review-media-s3');
        $failing = \Mockery::mock($disk);
        $failing->shouldReceive('deleteDirectory')->andReturn(false);
        Storage::set('review-media-s3', $failing);
        try {
            app(ReviewImageRemoval::class)->run($entry->id);
            $this->fail('Storage failure must leave a retryable task.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('не подтвердило', $exception->getMessage());
        } finally {
            Storage::set('review-media-s3', $disk);
        }
        $this->assertNotNull($entry->fresh()->error);
        $this->assertTrue(app(ReviewImageRemoval::class)->run($entry->id));
        Storage::disk('review-media-s3')->assertExists(array_column($shared->variants, 'path'));
        Storage::disk('review-media-s3')->assertMissing(array_column($owned->variants, 'path'));
        $this->assertDatabaseMissing('reviews', ['id' => $a->id]);
        $this->assertDatabaseMissing('reviews', ['id' => $b->id]);
        $this->assertDatabaseHas('reviews', ['id' => $other->id]);
    }
}
