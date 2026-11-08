<?php

namespace Tests\Feature;

use App\Models\ContentCategory;
use App\Models\ContentEntry;
use App\Models\ContentPlacement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ArticleContentApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_articles_returns_published_index_entries_with_category_metadata(): void
    {
        $category = ContentCategory::query()->create([
            'content_type' => ContentCategory::TYPE_ARTICLE,
            'slug' => 'learning',
            'title' => 'Обучение',
            'sort_order' => 10,
            'is_active' => true,
        ]);
        $published = $this->createArticleEntry(
            'study-plan',
            'Как выстроить план обучения',
            'Короткое описание статьи.',
            category: $category,
        );
        $this->placeEntry($published, ContentPlacement::ARTICLES_INDEX, 20);
        $draft = $this->createArticleEntry('draft-article', 'Черновик статьи', status: ContentEntry::STATUS_DRAFT);
        $this->placeEntry($draft, ContentPlacement::ARTICLES_INDEX, 10);

        $this->getJson('/api/v1/articles')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'study-plan')
            ->assertJsonPath('data.0.title', 'Как выстроить план обучения')
            ->assertJsonPath('data.0.description', 'Короткое описание статьи.')
            ->assertJsonMissingPath('data.0.body')
            ->assertJsonMissingPath('data.0.seoTitle')
            ->assertJsonMissingPath('data.0.seoDescription')
            ->assertJsonPath('data.0.category.slug', 'learning')
            ->assertJsonPath('data.0.category.title', 'Обучение')
            ->assertJsonPath('data.0.sortOrder', 20)
            ->assertJsonPath('meta.placement', ContentPlacement::ARTICLES_INDEX)
            ->assertJsonPath('meta.limit', null)
            ->assertJsonPath('meta.total', 1);
    }

    public function test_article_detail_returns_body_and_seo_for_visible_slug(): void
    {
        $article = $this->createArticleEntry(
            'cardiology-basics',
            'Основы кардиологии',
            'SEO описание кардиологии.',
            body: 'Полный текст статьи без HTML-разметки.',
            seoTitle: 'Кардиология для студентов',
        );
        $this->placeEntry($article, ContentPlacement::ARTICLES_INDEX, 10);

        $this->getJson('/api/v1/articles/cardiology-basics')
            ->assertOk()
            ->assertJsonPath('data.slug', 'cardiology-basics')
            ->assertJsonPath('data.title', 'Основы кардиологии')
            ->assertJsonPath('data.description', 'SEO описание кардиологии.')
            ->assertJsonPath('data.body', 'Полный текст статьи без HTML-разметки.')
            ->assertJsonPath('data.seoTitle', 'Кардиология для студентов')
            ->assertJsonPath('data.seoDescription', 'SEO описание кардиологии.');
    }

    public function test_articles_hide_future_disabled_placement_and_hidden_detail_but_preserve_inactive_category(): void
    {
        $inactiveCategory = ContentCategory::query()->create([
            'content_type' => ContentCategory::TYPE_ARTICLE,
            'slug' => 'inactive',
            'title' => 'Скрытая категория',
            'is_active' => false,
        ]);
        $visible = $this->createArticleEntry('visible', 'Видимая статья');
        $future = $this->createArticleEntry('future', 'Будущая статья', publishedAt: now()->addDay());
        $this->createArticleEntry('unplaced', 'Статья без placement');
        $disabledPlacement = $this->createArticleEntry('disabled-placement', 'Отключенная статья');
        $inactive = $this->createArticleEntry('inactive-category', 'Статья скрытой категории', category: $inactiveCategory);
        $hidden = $this->createArticleEntry('hidden-detail', 'Скрытая статья', status: ContentEntry::STATUS_HIDDEN);
        $archived = $this->createArticleEntry('archived-detail', 'Архивная статья', status: ContentEntry::STATUS_ARCHIVED);

        $this->placeEntry($visible, ContentPlacement::ARTICLES_INDEX, 10);
        $this->placeEntry($future, ContentPlacement::ARTICLES_INDEX, 20);
        $this->placeEntry($disabledPlacement, ContentPlacement::ARTICLES_INDEX, 30, false);
        $this->placeEntry($inactive, ContentPlacement::ARTICLES_INDEX, 40);
        $this->placeEntry($hidden, ContentPlacement::ARTICLES_INDEX, 50);
        $this->placeEntry($archived, ContentPlacement::ARTICLES_INDEX, 60);

        $this->getJson('/api/v1/articles')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.slug', 'inactive-category')
            ->assertJsonPath('data.1.slug', 'visible');

        $this->getJson('/api/v1/articles/hidden-detail')
            ->assertNotFound();

        $this->getJson('/api/v1/articles/unplaced')
            ->assertNotFound();

        $this->getJson('/api/v1/articles/archived-detail')
            ->assertNotFound();
    }

    public function test_articles_support_category_filter_and_limit_validation(): void
    {
        $learning = ContentCategory::query()->create([
            'content_type' => ContentCategory::TYPE_ARTICLE,
            'slug' => 'learning',
            'title' => 'Обучение',
            'is_active' => true,
        ]);
        $medicine = ContentCategory::query()->create([
            'content_type' => ContentCategory::TYPE_ARTICLE,
            'slug' => 'medicine',
            'title' => 'Медицина',
            'is_active' => true,
        ]);
        $learningArticle = $this->createArticleEntry('learning', 'Обучение', category: $learning);
        $medicineArticle = $this->createArticleEntry('medicine', 'Медицина', category: $medicine);

        $this->placeEntry($learningArticle, ContentPlacement::ARTICLES_INDEX, 10);
        $this->placeEntry($medicineArticle, ContentPlacement::ARTICLES_INDEX, 20);

        $this->getJson('/api/v1/articles?category=medicine')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'medicine')
            ->assertJsonPath('meta.total', 1);

        $this->getJson('/api/v1/articles?limit=51')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('limit');
    }

    private function createArticleEntry(
        string $slug,
        string $title,
        ?string $description = null,
        string $status = ContentEntry::STATUS_PUBLISHED,
        int $sortOrder = 0,
        ?ContentCategory $category = null,
        mixed $publishedAt = null,
        ?string $body = null,
        ?string $seoTitle = null,
    ): ContentEntry {
        return ContentEntry::query()->create([
            'content_category_id' => $category?->id,
            'content_type' => ContentEntry::TYPE_ARTICLE,
            'slug' => $slug,
            'title' => $title,
            'body' => $body ?? "Текст статьи {$slug}.",
            'status' => $status,
            'sort_order' => $sortOrder,
            'seo_title' => $seoTitle,
            'seo_description' => $description,
            'published_at' => $publishedAt ?? now()->subDay(),
        ]);
    }

    private function placeEntry(ContentEntry $entry, string $placement, int $sortOrder, bool $isEnabled = true): void
    {
        ContentPlacement::query()->create([
            'content_entry_id' => $entry->id,
            'placement' => $placement,
            'sort_order' => $sortOrder,
            'is_enabled' => $isEnabled,
        ]);
    }
}
