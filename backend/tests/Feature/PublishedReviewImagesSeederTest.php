<?php

namespace Tests\Feature;

use App\Models\Review;
use App\Models\ReviewEvidence;
use App\Models\ReviewImageAsset;
use App\Models\ReviewMedia;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\PublishedReviewImagesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublishedReviewImagesSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeded_images_reference_published_versions_without_storage_credentials_or_originals(): void
    {
        $manifest = json_decode(file_get_contents(database_path('seeders/data/published_review_images.json')), true, flags: JSON_THROW_ON_ERROR);
        $source = array_key_first($manifest);
        $review = Review::create(['source' => 'legacy_ocr', 'display_name' => 'Анна', 'text' => 'Полезный курс.']);
        $media = ReviewMedia::create(['review_id' => $review->id, 'type' => 'legacy_screenshot', 'url' => $source]);
        $evidence = ReviewEvidence::create(['review_id' => $review->id, 'type' => 'screenshot', 'is_public' => false, 'file_path' => 'photos/private.jpg',
            'metadata' => ['public_review_media_url' => $source]]);
        $this->seed(PublishedReviewImagesSeeder::class);
        $asset = ReviewImageAsset::sole();
        $this->assertSame('review-media-published', $asset->disk);
        $this->assertSame('/storage/'.$asset->primary_path, $media->fresh()->url);
        $this->assertStringStartsWith('https://media.example.invalid/', $asset->url());
        $this->assertSame($asset->url(), $evidence->fresh()->metadata['public_review_media_url']);
        $this->assertFalse($evidence->fresh()->is_public);
        $this->assertSame('photos/private.jpg', $evidence->fresh()->file_path);
        $this->assertNotEmpty($asset->publicVariants());
        $this->seed(PublishedReviewImagesSeeder::class);
        $this->assertDatabaseCount('review_image_assets', 1);
    }

    public function test_fresh_database_seed_has_no_references_to_removed_working_files(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->assertGreaterThan(350, ReviewImageAsset::count());
        $this->assertSame(0, ReviewMedia::where('url', 'like', '/images/reviews/%')->count());
        $this->assertSame(0, ReviewEvidence::where('is_public', true)->where('file_path', 'like', 'reviews/%')->count());
    }
}
