<?php

namespace Tests\Feature;

use App\Jobs\DeleteReviewImage;
use App\Models\AdminOperationEvent;
use App\Models\Review;
use App\Models\ReviewImageAsset;
use App\Models\ReviewImageDeletion;
use App\Models\ReviewImportBatch;
use App\Models\User;
use App\Services\Reviews\ExternalReviewImportService;
use App\Services\Reviews\ReviewImageRemoval;
use Database\Seeders\TelegramReviewsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminReviewDeletionTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        Storage::fake('public');
        Storage::fake('local');
        $actor = User::factory()->create(['role' => 'admin_full']);
        Sanctum::actingAs($actor);
        $review = Review::create(['source' => 'legacy_ocr', 'source_ref' => 'delete-legacy', 'origin_type' => 'legacy_import',
            'text' => 'Удаляемый текст отзыва.', 'display_name' => 'Автор', 'status' => 'published', 'published_at' => now(),
            'review_written_at' => '2026-01-15']);
        $source = $review->sources()->create(['source_type' => 'legacy_site', 'source_ref' => 'delete-legacy',
            'raw_text' => 'Удаляемый текст отзыва.', 'raw_payload' => ['original' => true]]);
        $source->evidence()->create(['review_id' => $review->id, 'type' => 'webarchive', 'is_public' => false,
            'file_path' => 'reviews/original.webp']);
        Storage::disk('public')->put('reviews/original.webp', 'original');
        $id = (string) Str::uuid();
        $paths = ['review-screenshots/'.$id.'.webp', 'review-screenshots/'.$id.'/96.avif', 'review-screenshots/'.$id.'/96.webp'];
        foreach ($paths as $path) {
            Storage::disk('public')->put($path, 'image');
        }
        $asset = ReviewImageAsset::create(['id' => $id, 'review_id' => $review->id, 'owner_id' => $actor->id, 'kind' => 'screenshot',
            'status' => 'ready', 'disk' => 'public', 'source_mime' => 'image/png', 'primary_path' => $paths[0], 'width' => 96, 'height' => 192,
            'variants' => array_map(fn ($p) => ['path' => $p, 'width' => 96, 'height' => 192, 'format' => pathinfo($p, PATHINFO_EXTENSION)], $paths)]);
        $review->media()->create(['type' => 'legacy_screenshot', 'url' => '/storage/'.$paths[0]]);
        $review->moderationEvents()->create(['event_type' => 'admin_update', 'actor_user_id' => $actor->id]);

        return [$actor, $review, $asset];
    }

    private function remove(Review $review, array $overrides = [], string $key = 'delete-review')
    {
        return $this->deleteJson('/api/v1/admin/reviews/'.$review->id, array_replace([
            'revision' => (int) $review->moderationEvents()->max('id'), 'confirmation' => (string) $review->id], $overrides), ['Idempotency-Key' => $key]);
    }

    public function test_hard_delete_cascades_content_and_cleans_all_working_images_with_a_content_free_receipt(): void
    {
        [$actor,$review,$asset] = $this->fixture();
        $input = ['revision' => (int) $review->moderationEvents()->max('id'), 'confirmation' => (string) $review->id];
        $this->remove($review)->assertOk()->assertJsonPath('data.deleted', true);
        $this->assertDatabaseMissing('reviews', ['id' => $review->id]);
        foreach (['review_sources', 'review_evidence', 'review_media', 'review_moderation_events'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertDatabaseMissing('review_image_assets', ['id' => $asset->id]);
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->getJson('/api/v1/reviews/'.$review->id)->assertNotFound();
        $receipt = AdminOperationEvent::sole();
        $this->assertSame('delete_review', $receipt->operation);
        $this->assertStringNotContainsString($review->text, json_encode($receipt->payload, JSON_UNESCAPED_UNICODE));
        $this->deleteJson('/api/v1/admin/reviews/'.$review->id, $input, ['Idempotency-Key' => 'delete-review'])->assertOk()->assertJsonPath('data.deleted', true);
        $this->assertDatabaseCount('admin_operation_events', 1);
    }

    public function test_confirmation_revision_rbac_and_key_target_are_enforced(): void
    {
        [$actor,$review] = $this->fixture();
        $this->remove($review, ['confirmation' => 'other'])->assertUnprocessable();
        $this->remove($review, ['revision' => 0])->assertConflict();
        $actor->update(['role' => 'admin_limited']);
        $this->remove($review)->assertForbidden();
        $this->assertDatabaseHas('reviews', ['id' => $review->id]);
        $actor->update(['role' => 'admin_full']);
        $this->remove($review)->assertOk();
        $other = Review::create(['source' => 'admin', 'display_name' => 'Другой автор', 'text' => 'Другой отзыв.']);
        $this->remove($other)->assertConflict();
        $this->assertDatabaseHas('reviews', ['id' => $other->id]);
    }

    public function test_rollback_keeps_review_relations_and_files(): void
    {
        [$actor,$review,$asset] = $this->fixture();
        AdminOperationEvent::creating(fn () => throw new \RuntimeException('audit unavailable'));
        try {
            $this->remove($review)->assertServerError();
        } finally {
            AdminOperationEvent::flushEventListeners();
        }
        $this->assertDatabaseHas('reviews', ['id' => $review->id]);
        $this->assertDatabaseCount('review_sources', 1);
        $this->assertDatabaseCount('review_image_deletions', 0);
        Storage::disk('public')->assertExists($asset->primary_path);
        $this->assertSame('ready', $asset->fresh()->status);
    }

    public function test_shared_images_survive_and_deletion_outbox_survives_the_review_foreign_key(): void
    {
        [$actor,$review,$asset] = $this->fixture();
        Queue::fake([DeleteReviewImage::class]);
        $other = Review::create(['source' => 'admin', 'display_name' => 'Другой автор', 'text' => 'Другой отзыв.']);
        $other->evidence()->create(['type' => 'image', 'is_public' => false, 'file_path' => 'reviews/original.webp']);
        $this->remove($review)->assertOk();
        $entry = ReviewImageDeletion::sole();
        $this->assertNull($entry->review_id);
        Storage::disk('public')->assertExists($asset->primary_path);
        app(ReviewImageRemoval::class)->run($entry->id);
        Storage::disk('public')->assertMissing($asset->primary_path);
        Storage::disk('public')->assertExists('reviews/original.webp');
    }

    public function test_deleted_import_is_not_recreated(): void
    {
        [$actor,$review] = $this->fixture();
        $this->remove($review)->assertOk();
        $batch = ReviewImportBatch::create(['source_type' => 'legacy_site', 'batch_ref' => 'deleted-reimport', 'status' => 'pending']);
        $result = app(ExternalReviewImportService::class)->importRecord($batch, ['source_ref' => 'delete-legacy',
            'text' => 'Удаляемый текст отзыва.', 'confidence' => 'high']);
        $this->assertSame('skipped_deleted', $result['action']);
        $this->assertDatabaseCount('reviews', 0);
        $this->assertDatabaseCount('review_sources', 0);
    }

    public function test_owned_heic_evidence_is_removed_without_touching_shared_import_archives(): void
    {
        [$actor, $review] = $this->fixture();
        Storage::disk('local')->put('review-evidence/original.HEIC', 'private original');
        Storage::disk('local')->put('imports/shared.webarchive', 'shared archive');
        foreach (['review-evidence/original.HEIC', 'imports/shared.webarchive'] as $path) {
            $review->evidence()->create(['type' => 'image', 'is_public' => false, 'file_path' => $path]);
        }
        $this->remove($review)->assertOk();
        Storage::disk('local')->assertMissing('review-evidence/original.HEIC');
        Storage::disk('local')->assertExists('imports/shared.webarchive');
        $this->assertDatabaseCount('review_evidence', 0);
    }

    public function test_in_progress_encoder_blocks_delete_without_losing_its_files(): void
    {
        [$actor,$review,$asset] = $this->fixture();
        $asset->update(['status' => 'processing']);
        $this->remove($review)->assertConflict()->assertJsonPath('error.code', 'ADMIN_REVIEW_STATE_CONFLICT');
        $this->assertDatabaseHas('reviews', ['id' => $review->id]);
        $this->assertDatabaseCount('admin_operation_events', 0);
        Storage::disk('public')->assertExists($asset->primary_path);
    }

    public function test_telegram_seed_does_not_restore_a_deleted_import(): void
    {
        [$actor,$review] = $this->fixture();
        $payload = json_decode(file_get_contents(database_path('seeders/data/telegram_reviews.json')), true);
        $ref = $payload['reviews'][0]['source_ref'];
        $review->update(['source_ref' => $ref]);
        $review->sources()->update(['source_type' => 'telegram', 'source_ref' => $ref]);
        $this->remove($review)->assertOk();
        $this->seed(TelegramReviewsSeeder::class);
        $this->assertDatabaseMissing('reviews', ['source_ref' => $ref]);
        $this->assertDatabaseMissing('review_sources', ['source_ref' => $ref]);
    }

    public function test_home_and_inclusive_added_date_filters_use_creation_instead_of_review_date(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin_full']));
        $a = Review::create(['source' => 'admin', 'display_name' => 'A', 'text' => 'A', 'show_on_home' => true, 'review_written_at' => '2020-01-01']);
        $b = Review::create(['source' => 'admin', 'display_name' => 'B', 'text' => 'B', 'show_on_home' => true, 'review_written_at' => '2021-01-01']);
        $c = Review::create(['source' => 'admin', 'display_name' => 'C', 'text' => 'C', 'show_on_home' => false, 'review_written_at' => '2022-01-01']);
        foreach ([[$a, '2026-10-10 00:00:00'], [$b, '2026-10-11 23:59:59'], [$c, '2026-10-11 12:00:00']] as [$review,$created]) {
            $review->forceFill(['created_at' => $created])->save();
        }
        $this->getJson('/api/v1/admin/reviews?home=yes&addedFrom=2026-10-10&addedTo=2026-10-11&addedOrder=oldest')->assertOk()->assertJsonPath('data.0.id', $a->id)->assertJsonPath('data.1.id', $b->id)->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/admin/reviews?home=no&addedFrom=2026-10-11')->assertOk()->assertJsonPath('data.0.id', $c->id)->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/admin/reviews?home=yes&addedOrder=newest')->assertOk()->assertJsonPath('data.0.id', $b->id);
        $this->getJson('/api/v1/admin/reviews?addedFrom=bad')->assertUnprocessable();
        $this->getJson('/api/v1/admin/reviews?addedFrom=2026-10-12&addedTo=2026-10-10')->assertUnprocessable();
    }
}
