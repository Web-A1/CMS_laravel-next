<?php

namespace Tests\Feature;

use App\Exceptions\AdminReviewException;
use App\Models\Review;
use App\Models\User;
use App\Support\Admin\ReviewsPanel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ReviewHomePlacementTest extends TestCase
{
    use RefreshDatabase;

    private function review(array $input = []): Review
    {
        return Review::query()->create(array_replace(['display_name' => 'Анна', 'text' => 'Отзыв о занятиях.',
            'source' => 'admin', 'review_written_at' => '2026-01-15', 'published_at' => now()->subDay()], $input));
    }

    public function test_home_returns_only_selected_published_reviews_without_restricting_catalog(): void
    {
        $selected = $this->review(['status' => 'published', 'show_on_home' => true]);
        $ordinary = $this->review(['status' => 'published', 'show_on_home' => false]);
        $this->review(['status' => 'draft', 'show_on_home' => true]);
        $this->review(['status' => 'published', 'publication_status' => 'hidden', 'show_on_home' => true]);

        $this->getJson('/api/v1/reviews?placement=home')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $selected->id);
        $this->getJson('/api/v1/reviews')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/reviews/'.$ordinary->id)->assertOk();
        $this->getJson('/api/v1/reviews?placement=unknown')->assertUnprocessable();
    }

    public function test_catalog_prioritizes_home_reviews_before_publication_date_across_pages_and_navigation(): void
    {
        $selected = $this->review(['status' => 'published', 'show_on_home' => true,
            'published_at' => now()->subDays(10)]);
        $older = $this->review(['status' => 'published', 'review_written_at' => '2026-10-01',
            'published_at' => now()->subDays(3)]);
        $newer = $this->review(['status' => 'published', 'review_written_at' => '2020-01-01',
            'published_at' => now()->subDay()]);
        $tie = $this->review(['status' => 'published', 'published_at' => $newer->published_at]);
        $this->review(['status' => 'draft', 'show_on_home' => true]);
        $this->review(['status' => 'published', 'show_on_home' => true, 'published_at' => now()->addDay()]);

        foreach (['', '&search='.urlencode('Отзыв')] as $filter) {
            $this->getJson('/api/v1/reviews?per_page=2'.$filter)->assertOk()
                ->assertJsonPath('data.0.id', $selected->id)
                ->assertJsonPath('data.1.id', $tie->id)
                ->assertJsonPath('meta.total', 4);
            $this->getJson('/api/v1/reviews?per_page=2&page=2'.$filter)->assertOk()
                ->assertJsonPath('data.0.id', $newer->id)
                ->assertJsonPath('data.1.id', $older->id);
        }

        $this->getJson('/api/v1/reviews/'.$tie->id)->assertOk()
            ->assertJsonPath('meta.navigation.previousId', $selected->id)
            ->assertJsonPath('meta.navigation.nextId', $newer->id)
            ->assertJsonPath('meta.navigation.position', 2);

        $selected->update(['show_on_home' => false]);
        $this->getJson('/api/v1/reviews')->assertOk()->assertJsonPath('data.0.id', $tie->id)
            ->assertJsonPath('data.3.id', $selected->id);
        $older->update(['show_on_home' => true]);
        $this->getJson('/api/v1/reviews')->assertOk()->assertJsonPath('data.0.id', $older->id);
    }

    public function test_catalog_orders_selected_reviews_by_publication_date_without_changing_home_order(): void
    {
        $recentlyPublished = $this->review(['status' => 'published', 'show_on_home' => true,
            'review_written_at' => '2020-01-01', 'published_at' => now()->subDay()]);
        $recentlyWritten = $this->review(['status' => 'published', 'show_on_home' => true,
            'review_written_at' => '2026-01-01', 'published_at' => now()->subDays(2)]);

        $this->getJson('/api/v1/reviews')->assertOk()
            ->assertJsonPath('data.0.id', $recentlyPublished->id)
            ->assertJsonPath('data.1.id', $recentlyWritten->id);
        $this->getJson('/api/v1/reviews?placement=home')->assertOk()
            ->assertJsonPath('data.0.id', $recentlyWritten->id)
            ->assertJsonPath('data.1.id', $recentlyPublished->id);
    }

    public function test_home_placement_is_independent_audited_and_revision_checked_for_imported_reviews(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin_full']), 'web');
        $review = $this->review(['source' => 'legacy_ocr', 'status' => 'published']);
        $panel = app(ReviewsPanel::class);
        $input = ['showOnHome' => true, 'revision' => 0];
        $saved = $panel->execute('placement', $review->id, $input, 'placement-1');
        $this->assertTrue($saved['showOnHome']);
        $this->assertSame('published', $review->fresh()->publication_status);
        $this->assertSame($saved['revision'], $panel->execute('placement', $review->id, $input, 'placement-1')['revision']);
        $this->assertDatabaseCount('review_moderation_events', 1);
        $this->assertDatabaseHas('review_moderation_events', ['event_type' => 'admin_placement']);

        try {
            $panel->execute('placement', $review->id, ['showOnHome' => false, 'revision' => 0], 'placement-stale');
            $this->fail('Stale placement must fail.');
        } catch (AdminReviewException $exception) {
            $this->assertSame('ADMIN_REVIEW_REVISION_CONFLICT', $exception->errorCode);
        }
        $panel->execute('placement', $review->id, ['showOnHome' => false, 'revision' => $saved['revision']], 'placement-2');
        $this->getJson('/api/v1/reviews?placement=home')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/reviews/'.$review->id)->assertOk();
    }

    public function test_home_placement_no_op_does_not_change_metadata_or_revision(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin_full']), 'web');
        $review = $this->review(['source' => 'legacy_ocr', 'status' => 'published', 'show_on_home' => false]);
        $before = $review->fresh()->getAttributes();
        $this->travel(10)->seconds();
        $saved = app(ReviewsPanel::class)->execute('placement', $review->id, ['showOnHome' => false, 'revision' => 0], 'home-noop');
        $this->assertSame(0, $saved['revision']);
        $this->assertSame($before, $review->fresh()->getAttributes());
        $this->assertDatabaseCount('review_moderation_events', 0);
    }

    public function test_migration_preserves_only_the_existing_top_twenty_published_reviews(): void
    {
        for ($index = 1; $index <= 22; $index++) {
            $this->review(['status' => 'published']);
        }
        $hidden = $this->review(['status' => 'published', 'publication_status' => 'hidden']);
        $future = $this->review(['status' => 'published', 'published_at' => now()->addDay()]);
        $migration = require database_path('migrations/2026_10_19_120000_add_show_on_home_to_reviews_table.php');
        $migration->down();
        $migration->up();
        $this->assertSame(20, Review::query()->where('show_on_home', true)->count());
        $this->assertFalse($hidden->fresh()->show_on_home);
        $this->assertFalse($future->fresh()->show_on_home);
        $this->assertFalse(Review::query()->oldest('id')->firstOrFail()->show_on_home);
        $this->assertTrue(Review::query()->whereKey(22)->firstOrFail()->show_on_home);
        $this->assertFalse($this->review(['status' => 'published'])->show_on_home ?? false);
    }

    public function test_placement_rejects_missing_permission_and_unexpected_fields(): void
    {
        $review = $this->review(['status' => 'published']);
        $this->actingAs(User::factory()->create(['role' => 'admin_full']), 'web');
        try {
            app(ReviewsPanel::class)->execute('placement', $review->id,
                ['showOnHome' => true, 'revision' => 0, 'text' => 'Запрещённая правка.'], 'bad-input');
            $this->fail('Unexpected fields must fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('request', $exception->errors());
        }
        $this->actingAs(User::factory()->create(['role' => 'admin_limited']), 'web');
        $this->expectException(HttpException::class);
        app(ReviewsPanel::class)->execute('placement', $review->id, ['showOnHome' => true, 'revision' => 0], 'forbidden');
    }
}
