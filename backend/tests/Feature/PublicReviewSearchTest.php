<?php

namespace Tests\Feature;

use App\Models\Review;
use App\Models\Subject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicReviewSearchTest extends TestCase
{
    use RefreshDatabase;

    private function review(array $attributes = []): Review
    {
        return Review::query()->create(array_replace([
            'display_name' => 'Анна', 'text' => 'Полезные занятия.', 'source' => 'admin',
            'status' => 'published', 'published_at' => now()->subDay(),
        ], $attributes));
    }

    private function ids(string $search, array $filters = []): array
    {
        return $this->getJson('/api/v1/reviews?'.http_build_query(['search' => $search, ...$filters]))
            ->assertOk()->json('data.*.id');
    }

    public function test_search_matches_unicode_text_public_name_or_exact_id(): void
    {
        $text = $this->review(['text' => 'Отличная АНАТОМИЯ']);
        $name = $this->review(['display_name' => 'Анатомия']);
        $this->assertEqualsCanonicalizing([$text->id, $name->id], $this->ids('анатомия'));
        $this->assertSame([$text->id], $this->ids('  '.$text->id.'  '));
        $this->assertSame([$text->id], $this->ids('00'.$text->id));
        $this->assertSame([], $this->ids(str_repeat('9', 100)));
        $this->assertSame([], $this->ids('0'));
        $this->assertSame([], $this->ids('несуществующий'));
    }

    public function test_search_never_exposes_unpublished_rows_or_masked_names(): void
    {
        $this->review(['text' => 'секрет', 'status' => 'draft']);
        $this->review(['text' => 'секрет', 'publication_status' => 'hidden']);
        $this->review(['text' => 'секрет', 'published_at' => now()->addDay()]);
        $anonymous = $this->review(['display_name' => 'Секретная фамилия', 'is_anonymous' => true]);
        $masked = $this->review(['display_name' => 'private_user_731']);
        $this->assertSame([], $this->ids('секрет'));
        $this->assertSame([], $this->ids('private_user'));
        $this->assertEqualsCanonicalizing([$anonymous->id, $masked->id], $this->ids('АНОНИМНЫЙ'));

        $edited = $this->review(['display_name' => 'public_user_731', 'content_edited_at' => now()]);
        $this->assertSame([$edited->id], $this->ids('public_user'));
        $normalized = $this->review(['display_name' => '  МАРИЯ   ПЕТРОВА  ']);
        $this->assertSame([$normalized->id], $this->ids('мария петрова'));
        $this->getJson('/api/v1/reviews/'.$normalized->id)->assertJsonPath('data.displayName', 'Мария Петрова');
    }

    public function test_percent_underscore_and_backslash_are_literal_characters(): void
    {
        foreach (['100%', 'a_b', 'x\\y'] as $needle) {
            $match = $this->review(['text' => 'Точное вхождение '.$needle]);
            $this->review(['text' => '100 баллов axb xyy']);
            $this->assertSame([$match->id], $this->ids($needle));
        }
    }

    public function test_search_applies_before_pagination_and_to_detail_navigation_with_subject(): void
    {
        $subject = Subject::query()->create(['title' => 'Анатомия', 'slug' => 'anatomy']);
        $first = $this->review(['text' => 'курс подходит', 'subject_id' => $subject->id]);
        $second = $this->review(['text' => 'курс интересный', 'subject_id' => $subject->id]);
        $outside = $this->review(['text' => 'курс вне предмета']);
        $unmatched = $this->review(['subject_id' => $subject->id]);
        $query = http_build_query(['search' => 'КУРС', 'subject' => 'anatomy', 'per_page' => 1]);
        $this->getJson('/api/v1/reviews?'.$query)->assertOk()->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.lastPage', 2)->assertJsonPath('data.0.id', $second->id);
        $this->getJson('/api/v1/reviews?'.$query.'&page=2')->assertJsonPath('data.0.id', $first->id);
        $this->getJson('/api/v1/reviews/'.$second->id.'?'.$query)->assertOk()
            ->assertJsonPath('meta.navigation.total', 2)->assertJsonPath('meta.navigation.nextId', $first->id)
            ->assertJsonPath('meta.navigation.previousId', null);
        foreach ([$outside, $unmatched] as $review) {
            $this->getJson('/api/v1/reviews/'.$review->id.'?'.$query)->assertNotFound();
        }
    }

    public function test_empty_search_preserves_catalog_and_invalid_search_is_rejected(): void
    {
        $this->review();
        $this->assertCount(1, $this->ids('  '));
        foreach (['search' => str_repeat('x', 201), 'search[]' => 'x'] as $key => $value) {
            $query = http_build_query([$key => $value]);
            $this->getJson('/api/v1/reviews?'.$query)->assertUnprocessable();
            $this->getJson('/api/v1/reviews/1?'.$query)->assertUnprocessable();
        }
    }

    public function test_search_matches_only_the_displayed_review_date_in_utc(): void
    {
        $match = $this->review(['review_written_at' => '2024-10-19T22:30:00Z']);
        $this->review(['review_written_at' => '2024-10-20T00:30:00Z']);
        $this->review(['review_written_at' => null, 'published_at' => '2024-10-19T12:00:00Z']);
        $this->review(['review_written_at' => '2024-10-19T22:30:00Z', 'publication_status' => 'hidden']);

        foreach (['19 октября 2024 г.', '19 ОКТЯБРЯ', '19 октября  2024 г.'] as $query) {
            $this->assertSame([$match->id], $this->ids($query));
        }
        $this->getJson('/api/v1/reviews/'.$match->id.'?'.http_build_query(['search' => '19 октября 2024 г.']))
            ->assertOk()->assertJsonPath('meta.navigation.total', 1);
    }
}
