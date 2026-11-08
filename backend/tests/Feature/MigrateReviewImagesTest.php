<?php

namespace Tests\Feature;

use App\Models\Review;
use App\Models\ReviewEvidence;
use App\Models\ReviewImageAsset;
use App\Models\ReviewImageMigration;
use App\Models\ReviewMedia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MigrateReviewImagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
        Storage::fake('review-test', ['url' => 'https://media.example.test']);
        config(['review-media.disk' => 'review-test', 'review-media.frontend_public' => Storage::disk('local')->path('frontend')]);
        File::ensureDirectoryExists(config('review-media.frontend_public').'/images/reviews/telegram');
    }

    private function media(Review $review, string $name = 'screen.webp'): ReviewMedia
    {
        File::put(config('review-media.frontend_public').'/images/reviews/telegram/'.$name, UploadedFile::fake()->image('source.png', 100, 180)->get());

        return ReviewMedia::create(['review_id' => $review->id, 'type' => 'legacy_screenshot', 'url' => '/images/reviews/telegram/'.$name,
            'alt' => 'Скриншот', 'width' => 100, 'height' => 180, 'sort_order' => 3]);
    }

    private function review(array $attributes = []): Review
    {
        return Review::create($attributes + ['source' => 'legacy_ocr', 'display_name' => 'Анна', 'text' => 'Отзыв о курсе.']);
    }

    public function test_dry_run_does_not_write_and_apply_is_repeatable_without_deleting_source(): void
    {
        $review = $this->review();
        $media = $this->media($review);
        $old = $media->url;
        $this->artisan('reviews:migrate-images')->assertSuccessful();
        $this->assertDatabaseCount('review_image_assets', 0);
        $this->assertDatabaseCount('review_image_migrations', 0);
        $this->artisan('reviews:migrate-images', ['--apply' => true])->assertSuccessful();
        $migration = ReviewImageMigration::sole();
        $asset = ReviewImageAsset::sole();
        $this->assertSame('ready', $asset->status);
        $this->assertSame($review->id, $asset->review_id);
        $this->assertSame('/storage/'.$asset->primary_path, $media->fresh()->url);
        $this->assertSame([3, 'legacy_screenshot', 'Скриншот'], [$media->fresh()->sort_order, $media->type, $media->alt]);
        $this->assertNotNull($migration->switched_at);
        $this->assertFileExists(config('review-media.frontend_public').$old);
        $this->artisan('reviews:migrate-images', ['--apply' => true])->assertSuccessful();
        $this->assertDatabaseCount('review_image_assets', 1);
        $this->get('/review-images/legacy?path='.urlencode($old))->assertRedirect($asset->url());
        $this->artisan('reviews:migrate-images', ['--verify' => true])->assertSuccessful();
        $this->artisan('reviews:migrate-images', ['--prune' => true])->assertSuccessful();
        $this->assertFileDoesNotExist(config('review-media.frontend_public').$old);
        $this->assertNotNull($migration->fresh()->pruned_at);
        $this->get('/review-images/legacy?path='.urlencode($old))->assertRedirect($asset->url());
    }

    public function test_public_evidence_gets_variants_without_exposing_private_evidence(): void
    {
        $review = $this->review(['publication_status' => 'published', 'status' => 'published', 'moderation_status' => 'approved', 'dedupe_status' => 'unique', 'published_at' => now()]);
        Storage::disk('public')->put('reviews/test.png', UploadedFile::fake()->image('screen.png', 100, 180)->get());
        $evidence = ReviewEvidence::create(['review_id' => $review->id, 'type' => 'screenshot', 'file_path' => 'reviews/test.png',
            'is_public' => true, 'sha256' => str_repeat('a', 64), 'provenance' => 'legacy_site', 'metadata' => ['sort_order' => 4]]);
        $private = ReviewEvidence::create(['review_id' => $review->id, 'type' => 'screenshot', 'file_path' => 'reviews/test.png', 'is_public' => false]);
        $this->artisan('reviews:migrate-images', ['--apply' => true])->assertSuccessful();
        $asset = ReviewImageAsset::sole();
        $this->assertNull($evidence->fresh()->file_path);
        $this->assertSame(str_repeat('a', 64), $evidence->fresh()->sha256);
        $this->assertSame('reviews/test.png', $private->fresh()->file_path);
        $this->getJson('/api/v1/reviews/'.$review->id.'?image_variants=1')->assertOk()
            ->assertJsonPath('data.media.0.id', 'review_evidence:'.$evidence->id)
            ->assertJsonPath('data.media.0.provenance', 'legacy_site')
            ->assertJsonPath('data.media.0.url', $asset->url())
            ->assertJsonCount(4, 'data.media.0.variants');
        $this->getJson('/api/v1/reviews/'.$review->id)->assertOk()->assertJsonMissingPath('data.media.0.variants');
        $this->artisan('reviews:migrate-images', ['--prune' => true])->assertFailed();
        Storage::disk('public')->assertExists('reviews/test.png');
    }

    public function test_failed_processing_can_retry_and_never_switches_or_prunes_source(): void
    {
        $media = $this->media($this->review());
        $old = $media->url;
        config(['review-media.binary' => '/missing/encoder']);
        $this->artisan('reviews:migrate-images', ['--apply' => true])->assertFailed();
        $this->assertSame($old, $media->fresh()->url);
        $this->assertFileExists(config('review-media.frontend_public').$old);
        config(['review-media.binary' => null]);
        $this->artisan('reviews:migrate-images', ['--apply' => true])->assertSuccessful();
        $this->assertDatabaseCount('review_image_assets', 1);
        $this->assertNotSame($old, $media->fresh()->url);
    }

    public function test_prune_rejects_changed_sources_missing_outputs_and_reintroduced_references(): void
    {
        $media = $this->media($this->review());
        $old = $media->url;
        $file = config('review-media.frontend_public').$old;
        $original = File::get($file);
        $this->artisan('reviews:migrate-images', ['--apply' => true])->assertSuccessful();
        File::append($file, 'changed');
        $this->artisan('reviews:migrate-images', ['--prune' => true])->assertFailed();
        File::put($file, $original);
        $asset = ReviewImageAsset::sole();
        $variant = $asset->variants[0];
        $bytes = Storage::disk('review-test')->get($variant['path']);
        Storage::disk('review-test')->delete($variant['path']);
        $this->artisan('reviews:migrate-images', ['--prune' => true])->assertFailed();
        Storage::disk('review-test')->put($variant['path'], $bytes);
        $absolute = ReviewMedia::create(['review_id' => $media->review_id, 'type' => 'attachment', 'url' => 'https://school.example'.$old]);
        $this->artisan('reviews:migrate-images', ['--prune' => true])->assertFailed();
        $absolute->delete();
        ReviewMedia::create(['review_id' => $media->review_id, 'type' => 'attachment', 'url' => $old]);
        $this->artisan('reviews:migrate-images', ['--prune' => true])->assertFailed();
        $this->assertFileExists($file);
    }

    public function test_identical_inputs_within_review_share_one_asset_and_symlinks_are_rejected(): void
    {
        $review = $this->review();
        $first = $this->media($review);
        $second = $this->media($review, 'second.webp');
        File::copy(config('review-media.frontend_public').$first->url, config('review-media.frontend_public').$second->url);
        $this->artisan('reviews:migrate-images', ['--apply' => true])->assertSuccessful();
        $this->assertDatabaseCount('review_image_assets', 1);
        $this->assertSame($first->fresh()->url, $second->fresh()->url);
        $escape = $this->media($review, 'escape.webp');
        unlink(config('review-media.frontend_public').$escape->url);
        $outside = Storage::disk('local')->path('outside.png');
        File::put($outside, UploadedFile::fake()->image('source.png')->get());
        symlink($outside, config('review-media.frontend_public').$escape->url);
        $this->artisan('reviews:migrate-images', ['--apply' => true])->assertFailed();
        $this->assertSame('/images/reviews/telegram/escape.webp', $escape->fresh()->url);
        $this->get('/review-images/legacy?path=../../.env')->assertNotFound();
    }

    public function test_concurrent_binding_change_prevents_switching_the_prepared_asset(): void
    {
        $media = $this->media($this->review());
        $old = $media->url;
        $disk = Storage::disk('review-test');
        $changed = false;
        $interleaved = \Mockery::mock($disk);
        $interleaved->shouldReceive('put')->andReturnUsing(function (...$args) use ($disk, $media, &$changed): bool {
            if (! $changed) {
                $media->update(['url' => '/images/reviews/telegram/replaced.webp']);
                $changed = true;
            }

            return $disk->put(...$args);
        });
        Storage::set('review-test', $interleaved);
        $this->artisan('reviews:migrate-images', ['--apply' => true])->assertFailed();
        $this->assertSame('/images/reviews/telegram/replaced.webp', $media->fresh()->url);
        $this->assertNull(ReviewImageMigration::sole()->switched_at);
        $this->assertFileExists(config('review-media.frontend_public').$old);
    }

    public function test_shared_source_is_removed_only_after_all_reviews_have_switched(): void
    {
        $first = $this->media($this->review());
        $second = ReviewMedia::create(['review_id' => $this->review()->id, 'url' => $first->url, 'type' => 'legacy_screenshot']);
        $this->artisan('reviews:migrate-images', ['--apply' => true, '--review' => [$first->review_id]])->assertSuccessful();
        $this->artisan('reviews:migrate-images', ['--prune' => true])->assertFailed();
        $this->assertFileExists(config('review-media.frontend_public').$second->url);
        $this->artisan('reviews:migrate-images', ['--apply' => true])->assertSuccessful();
        $this->artisan('reviews:migrate-images', ['--prune' => true])->assertSuccessful();
        $this->assertSame(2, ReviewImageMigration::whereNotNull('pruned_at')->count());
    }

    public function test_prune_checks_the_whole_batch_before_removing_the_first_source(): void
    {
        $first = $this->media($this->review(), 'first.webp');
        $this->media($this->review(), 'second.webp');
        $this->artisan('reviews:migrate-images', ['--apply' => true])->assertSuccessful();
        $last = ReviewImageMigration::orderByDesc('id')->firstOrFail()->asset;
        Storage::disk('review-test')->delete($last->variants[0]['path']);
        $this->artisan('reviews:migrate-images', ['--prune' => true])->assertFailed();
        $this->assertFileExists(config('review-media.frontend_public').$first->url);
        $this->assertSame(0, ReviewImageMigration::whereNotNull('pruned_at')->count());
    }

    public function test_import_metadata_public_pointer_moves_without_changing_private_evidence(): void
    {
        $media = $this->media($this->review());
        $old = $media->url;
        $evidence = ReviewEvidence::create(['review_id' => $media->review_id, 'type' => 'screenshot', 'is_public' => false,
            'file_path' => 'photos/private-original.jpg', 'sha256' => str_repeat('a', 64),
            'metadata' => ['public_review_media_url' => $old, 'source_note' => 'keep']]);
        $this->artisan('reviews:migrate-images', ['--apply' => true])->assertSuccessful();
        $this->assertSame(ReviewImageAsset::sole()->url(), $evidence->fresh()->metadata['public_review_media_url']);
        $this->assertSame('photos/private-original.jpg', $evidence->fresh()->file_path);
        $this->assertFalse($evidence->fresh()->is_public);
        $this->assertSame(str_repeat('a', 64), $evidence->fresh()->sha256);
        $this->assertSame('keep', $evidence->fresh()->metadata['source_note']);
        $this->artisan('reviews:migrate-images', ['--sync-metadata' => true])->assertSuccessful();
        $this->assertCount(2, ReviewImageMigration::sole()->references);
        $this->artisan('reviews:migrate-images', ['--prune' => true])->assertSuccessful();
        $this->assertFileDoesNotExist(config('review-media.frontend_public').$old);
    }

    public function test_zero_limit_does_nothing_and_invalid_limit_is_rejected(): void
    {
        $this->media($this->review());
        $this->artisan('reviews:migrate-images', ['--apply' => true, '--limit' => '0'])->assertSuccessful();
        $this->assertDatabaseCount('review_image_assets', 0);
        $this->artisan('reviews:migrate-images', ['--apply' => true, '--limit' => 'invalid'])->assertFailed();
        $this->assertDatabaseCount('review_image_assets', 0);
    }
}
