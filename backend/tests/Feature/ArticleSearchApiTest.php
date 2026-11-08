<?php

namespace Tests\Feature;

use App\Models\ContentAuthor;
use App\Models\ContentCategory;
use App\Models\ContentEntry;
use App\Models\ContentPlacement;
use App\Models\Subject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ArticleSearchApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_uses_all_summary_fields_and_paginates_filtered_results(): void
    {
        $this->article('title-match', ['title' => 'HEART anatomy']);
        $this->article('excerpt-match', ['excerpt' => 'A healthy heart']);
        $this->article('description-match', ['seo_description' => 'Heart function']);
        $this->article('other');

        $this->getJson('/api/v1/articles?q=%20hEaRt%20&limit=1&page=2')->assertOk()
            ->assertJsonPath('meta.total', 3)->assertJsonPath('meta.lastPage', 3)
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.slug', 'excerpt-match');
    }

    public function test_wildcards_escape_character_and_backslash_are_literal(): void
    {
        $this->article('literal', ['title' => 'ATP 100%_path\\!']);
        $this->article('other', ['title' => 'ATP 100XXpath']);

        foreach (['%', '_', '\\', '!', '%_path\\!', "' OR 1=1 --"] as $query) {
            $response = $this->getJson('/api/v1/articles?'.http_build_query(['q' => $query]))->assertOk();
            $response->assertJsonPath('meta.total', str_starts_with($query, "'") ? 0 : 1);
        }
    }

    public function test_search_preserves_subject_category_and_visibility_boundaries(): void
    {
        $subject = Subject::query()->create(['title' => 'Anatomy', 'slug' => 'anatomy', 'is_active' => true]);
        $category = ContentCategory::query()->create(['title' => 'Archive', 'slug' => 'archive', 'content_type' => ContentCategory::TYPE_ARTICLE, 'is_active' => true]);
        $this->article('visible', ['title' => 'Heart', 'subject_id' => $subject->id, 'content_category_id' => $category->id]);
        $this->article('other-subject', ['title' => 'Heart']);
        $this->article('draft', ['title' => 'Heart', 'status' => ContentEntry::STATUS_DRAFT]);
        $this->article('future', ['title' => 'Heart', 'published_at' => now()->addDay()]);
        $disabled = $this->article('disabled', ['title' => 'Heart']);
        $disabled->placements()->update(['is_enabled' => false]);
        $this->article('body-only', ['body' => 'Heart']);

        $this->getJson('/api/v1/articles?q=heart')->assertOk()->assertJsonPath('meta.total', 3);
        $this->getJson('/api/v1/articles?q=heart&subject=anatomy')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.slug', 'visible');
        $this->getJson('/api/v1/articles?q=heart&category=archive')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.slug', 'visible');
        $this->getJson('/api/v1/articles?q=heart&subject=anatomy&category=archive')->assertUnprocessable();
    }

    public function test_search_validates_input_and_blank_search_restores_the_catalog(): void
    {
        $this->article('visible');
        $this->getJson('/api/v1/articles?q=%20%20')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/articles?q[]=heart')->assertUnprocessable()->assertJsonValidationErrors('q');
        $this->getJson('/api/v1/articles?'.http_build_query(['q' => str_repeat('a', 121)]))->assertUnprocessable()->assertJsonValidationErrors('q');
        $this->getJson('/api/v1/articles?'.http_build_query(['q' => str_repeat('я', 120)]))->assertOk();
    }

    public function test_search_matches_full_visible_body_unicode_and_public_author_fragments(): void
    {
        $author = ContentAuthor::query()->create(['name' => 'Мария Петрова', 'slug' => 'mariya', 'bio' => 'Непубличная биография']);
        $this->article('full-text', [
            'body_format' => 'html',
            'body' => '<p>Анонс.</p><p>ГЛУБОКАЯ <strong>ана</strong>томия&nbsp;сердца &amp; сосудов</p><p>Следующий абзац</p><img src="private-marker.jpg">',
            'content_author_id' => $author->id,
        ]);
        $this->article('unrelated');

        foreach (['глубокая анатомия', 'анатомия сердца & сосудов', 'сосудов следующий', 'мария', 'ПЕТРОВА', 'мария петрова'] as $query) {
            $this->getJson('/api/v1/articles?'.http_build_query(['q' => $query]))->assertOk()
                ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.slug', 'full-text')
                ->assertJsonMissingPath('data.0.body');
        }
        foreach (['private-marker', 'Непубличная биография'] as $query) {
            $this->getJson('/api/v1/articles?'.http_build_query(['q' => $query]))->assertJsonPath('meta.total', 0);
        }
    }

    public function test_search_matches_visible_russian_date_in_moscow_before_pagination(): void
    {
        $this->article('moscow-day', ['published_at' => '2024-10-18T22:30:00Z']);
        $this->article('utc-day', ['published_at' => '2024-10-18T12:00:00Z']);
        foreach (['19 октября 2024 г.', 'ОКТЯБРЯ 2024', '19 октября'] as $query) {
            $this->getJson('/api/v1/articles?'.http_build_query(['q' => $query, 'limit' => 1]))->assertOk()
                ->assertJsonPath('meta.total', str_starts_with($query, 'ОКТЯБРЯ') ? 2 : 1)
                ->assertJsonPath('data.0.slug', 'moscow-day');
        }
        $this->getJson('/api/v1/articles?'.http_build_query(['q' => '19 октября 2024 г.', 'page' => 2, 'limit' => 1]))
            ->assertJsonPath('meta.total', 1)->assertJsonCount(0, 'data');
    }

    public function test_subject_counts_follow_search_across_the_catalog_and_keep_zero_counts(): void
    {
        $anatomy = Subject::query()->create(['title' => 'Anatomy', 'slug' => 'anatomy', 'is_active' => true]);
        $histology = Subject::query()->create(['title' => 'Histology', 'slug' => 'histology', 'is_active' => true]);
        $chemistry = Subject::query()->create(['title' => 'Chemistry', 'slug' => 'chemistry', 'is_active' => true]);
        Subject::query()->create(['title' => 'Inactive', 'slug' => 'inactive', 'is_active' => false]);
        $this->article('title', ['title' => 'Heart', 'subject_id' => $anatomy->id]);
        $this->article('body', ['body' => '<p>Deep <strong>heart</strong></p>', 'body_format' => 'html', 'subject_id' => $anatomy->id]);
        $this->article('excerpt', ['excerpt' => 'Heart', 'subject_id' => $histology->id]);
        $this->article('unrelated', ['subject_id' => $chemistry->id]);
        $this->article('unassigned', ['title' => 'Heart']);
        $this->article('draft', ['title' => 'Heart', 'subject_id' => $anatomy->id, 'status' => ContentEntry::STATUS_DRAFT]);
        $this->article('future', ['title' => 'Heart', 'subject_id' => $anatomy->id, 'published_at' => now()->addDay()]);
        $disabled = $this->article('disabled', ['title' => 'Heart', 'subject_id' => $anatomy->id]);
        $disabled->placements()->update(['is_enabled' => false]);

        $expected = ['anatomy' => 2, 'histology' => 1, 'chemistry' => 0];
        foreach (['q=heart', 'q=heart&subject=anatomy&page=2&limit=1'] as $query) {
            $data = $this->getJson('/api/v1/article-subjects?'.$query)->assertOk()->json('data');
            $this->assertEquals($expected, collect($data)->pluck('count', 'slug')->all());
            $this->assertEqualsCanonicalizing(['id', 'slug', 'title', 'count'], array_keys($data[0]));
        }
        $this->getJson('/api/v1/articles?q=heart&limit=1&page=2')->assertOk()->assertJsonPath('meta.total', 4);
        $this->getJson('/api/v1/articles?q=heart&subject=anatomy&limit=1')->assertOk()->assertJsonPath('meta.total', 2);
        $none = $this->getJson('/api/v1/article-subjects?q=no-match')->assertOk()->json('data');
        $this->assertEquals(['anatomy' => 0, 'histology' => 0, 'chemistry' => 0], collect($none)->pluck('count', 'slug')->all());
        $global = $this->getJson('/api/v1/article-subjects')->assertOk()->json('data');
        $this->assertEquals(['anatomy' => 2, 'histology' => 1, 'chemistry' => 1], collect($global)->pluck('count', 'slug')->all());
        $this->getJson('/api/v1/article-subjects?q=%20%20')->assertOk()->assertExactJson(['data' => $global]);
    }

    public function test_subject_counts_use_the_same_public_text_matcher_and_query_validation(): void
    {
        $subject = Subject::query()->create(['title' => 'Anatomy', 'slug' => 'anatomy', 'is_active' => true]);
        $author = ContentAuthor::query()->create(['name' => 'Мария Петрова', 'slug' => 'mariya', 'bio' => 'private-biography']);
        $this->article('public-fields', [
            'title' => 'ATP 100%_path\\!', 'excerpt' => 'Heart', 'seo_description' => 'Функция сердца',
            'body' => '<p>Глубокая <strong>ана</strong>томия</p><img src="private-file.jpg">', 'body_format' => 'html',
            'content_author_id' => $author->id, 'subject_id' => $subject->id, 'published_at' => '2024-10-18T22:30:00Z',
        ]);
        foreach (['HEART', 'функция', 'ГЛУБОКАЯ АНАТОМИЯ', 'мария петрова', '19 октября 2024 г.', '%', '_', '\\', 'private-biography', 'private-file'] as $q) {
            $expected = str_starts_with($q, 'private-') ? 0 : 1;
            $query = http_build_query(['q' => $q]);
            $this->getJson('/api/v1/article-subjects?'.$query)->assertOk()->assertJsonPath('data.0.count', $expected);
            $this->getJson('/api/v1/articles?'.$query)->assertOk()->assertJsonPath('meta.total', $expected);
        }
        $this->getJson('/api/v1/article-subjects?q[]=heart')->assertUnprocessable()->assertJsonValidationErrors('q');
        $this->getJson('/api/v1/article-subjects?'.http_build_query(['q' => str_repeat('я', 121)]))->assertUnprocessable()->assertJsonValidationErrors('q');
        $this->getJson('/api/v1/article-subjects?'.http_build_query(['q' => str_repeat('я', 120)]))->assertOk();
    }

    private function article(string $slug, array $attributes = []): ContentEntry
    {
        $article = ContentEntry::query()->create(array_merge([
            'content_type' => ContentEntry::TYPE_ARTICLE,
            'slug' => $slug,
            'title' => 'Other material',
            'body' => 'Teaching material',
            'status' => ContentEntry::STATUS_PUBLISHED,
            'published_at' => now()->subDay(),
        ], $attributes));
        ContentPlacement::query()->create(['content_entry_id' => $article->id, 'placement' => ContentPlacement::ARTICLES_INDEX, 'sort_order' => 0, 'is_enabled' => true]);

        return $article;
    }
}
