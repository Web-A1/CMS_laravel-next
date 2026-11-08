<?php

namespace Tests\Feature;

use App\Models\ContentCategory;
use App\Models\ContentEntry;
use App\Models\ContentPlacement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FaqContentApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_returns_more_than_seven_selected_questions_with_id_tie_break(): void
    {
        $ids = [];
        for ($i = 0; $i < 9; $i++) {
            $entry = $this->createFaqEntry('home-'.$i, 'Вопрос '.$i, sortOrder: 10);
            $this->placeEntry($entry, ContentPlacement::HOME_FAQ, 10);
            $ids[] = $entry->id;
        }
        $response = $this->getJson('/api/v1/faqs?placement=home.faq')->assertOk()->assertJsonCount(9, 'data');
        $this->assertSame($ids, array_column($response->json('data'), 'id'));
    }

    public function test_faqs_returns_published_index_entries_with_category_metadata(): void
    {
        $category = ContentCategory::query()->create([
            'content_type' => ContentCategory::TYPE_FAQ,
            'slug' => 'payments',
            'title' => 'Оплата',
            'sort_order' => 10,
            'is_active' => true,
        ]);
        $published = $this->createFaqEntry('payment-methods', 'Как оплатить курс?', category: $category);
        $this->placeEntry($published, ContentPlacement::FAQ_INDEX, 20);
        $draft = $this->createFaqEntry('draft-question', 'Черновик?', status: ContentEntry::STATUS_DRAFT);
        $this->placeEntry($draft, ContentPlacement::FAQ_INDEX, 10);

        $this->getJson('/api/v1/faqs')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'payment-methods')
            ->assertJsonPath('data.0.question', 'Как оплатить курс?')
            ->assertJsonPath('data.0.answer', 'Ответ на вопрос payment-methods.')
            ->assertJsonPath('data.0.category.slug', 'payments')
            ->assertJsonPath('data.0.category.title', 'Оплата')
            ->assertJsonPath('data.0.sortOrder', 20)
            ->assertJsonPath('meta.placement', ContentPlacement::FAQ_INDEX)
            ->assertJsonPath('meta.limit', null)
            ->assertJsonPath('meta.total', 1);
    }

    public function test_faqs_home_placement_returns_enabled_subset_in_placement_order(): void
    {
        $first = $this->createFaqEntry('first', 'Первый вопрос?', sortOrder: 30);
        $second = $this->createFaqEntry('second', 'Второй вопрос?', sortOrder: 10);
        $third = $this->createFaqEntry('third', 'Третий вопрос?', sortOrder: 20);
        $indexOnly = $this->createFaqEntry('index-only', 'Только на странице?');

        $this->placeEntry($first, ContentPlacement::HOME_FAQ, 30);
        $this->placeEntry($second, ContentPlacement::HOME_FAQ, 10);
        $this->placeEntry($third, ContentPlacement::HOME_FAQ, 20);
        $this->placeEntry($indexOnly, ContentPlacement::FAQ_INDEX, 5);

        $this->getJson('/api/v1/faqs?placement=home.faq&limit=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.slug', 'second')
            ->assertJsonPath('data.1.slug', 'third')
            ->assertJsonPath('meta.placement', ContentPlacement::HOME_FAQ)
            ->assertJsonPath('meta.limit', 2)
            ->assertJsonPath('meta.total', 3);
    }

    public function test_faqs_hide_future_inactive_category_and_disabled_placement_entries(): void
    {
        $inactiveCategory = ContentCategory::query()->create([
            'content_type' => ContentCategory::TYPE_FAQ,
            'slug' => 'inactive',
            'title' => 'Скрытая категория',
            'is_active' => false,
        ]);
        $visible = $this->createFaqEntry('visible', 'Видимый вопрос?');
        $future = $this->createFaqEntry('future', 'Будущий вопрос?', publishedAt: now()->addDay());
        $hidden = $this->createFaqEntry('hidden', 'Скрытый вопрос?', status: ContentEntry::STATUS_HIDDEN);
        $archived = $this->createFaqEntry('archived', 'Архивный вопрос?', status: ContentEntry::STATUS_ARCHIVED);
        $disabledPlacement = $this->createFaqEntry('disabled-placement', 'Отключенный вопрос?');
        $inactive = $this->createFaqEntry('inactive-category', 'Вопрос скрытой категории?', category: $inactiveCategory);
        $wrongPlacement = $this->createFaqEntry('home-only', 'Вопрос только для главной?');
        $wrongType = ContentEntry::query()->create([
            'content_type' => ContentEntry::TYPE_ARTICLE,
            'slug' => 'article-with-faq-placement',
            'title' => 'Статья в FAQ placement',
            'body' => 'Эта запись не должна попадать в FAQ.',
            'status' => ContentEntry::STATUS_PUBLISHED,
            'published_at' => now()->subDay(),
        ]);

        $this->placeEntry($visible, ContentPlacement::FAQ_INDEX, 10);
        $this->placeEntry($future, ContentPlacement::FAQ_INDEX, 20);
        $this->placeEntry($hidden, ContentPlacement::FAQ_INDEX, 30);
        $this->placeEntry($archived, ContentPlacement::FAQ_INDEX, 40);
        $this->placeEntry($disabledPlacement, ContentPlacement::FAQ_INDEX, 50, false);
        $this->placeEntry($inactive, ContentPlacement::FAQ_INDEX, 60);
        $this->placeEntry($wrongPlacement, ContentPlacement::HOME_FAQ, 70);
        $this->placeEntry($wrongType, ContentPlacement::FAQ_INDEX, 80);

        $this->getJson('/api/v1/faqs')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'visible')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonMissingPath('data.1');
    }

    public function test_faqs_expose_only_public_entry_fields_and_keep_total_before_limit(): void
    {
        $first = $this->createFaqEntry('first-public', 'Первый вопрос?', sortOrder: 30);
        $second = $this->createFaqEntry('second-public', 'Второй вопрос?', sortOrder: 10);

        $first->update([
            'payload' => ['internal_note' => 'Не для публичного API'],
            'seo_title' => 'SEO title не является FAQ contract',
            'seo_description' => 'SEO description не является FAQ contract',
        ]);

        $this->placeEntry($first, ContentPlacement::FAQ_INDEX, 20);
        $this->placeEntry($second, ContentPlacement::FAQ_INDEX, 10);

        $this->getJson('/api/v1/faqs?limit=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'second-public')
            ->assertJsonPath('data.0.question', 'Второй вопрос?')
            ->assertJsonPath('data.0.answer', 'Ответ на вопрос second-public.')
            ->assertJsonPath('data.0.sortOrder', 10)
            ->assertJsonPath('data.0.category', null)
            ->assertJsonPath('meta.placement', ContentPlacement::FAQ_INDEX)
            ->assertJsonPath('meta.limit', 1)
            ->assertJsonPath('meta.total', 2)
            ->assertJsonMissingPath('data.0.payload')
            ->assertJsonMissingPath('data.0.seoTitle')
            ->assertJsonMissingPath('data.0.seoDescription')
            ->assertJsonMissingPath('data.0.contentType')
            ->assertJsonMissingPath('data.0.status')
            ->assertJsonMissingPath('data.0.placements');
    }

    public function test_faqs_support_category_filter(): void
    {
        $payments = ContentCategory::query()->create([
            'content_type' => ContentCategory::TYPE_FAQ,
            'slug' => 'payments',
            'title' => 'Оплата',
            'is_active' => true,
        ]);
        $learning = ContentCategory::query()->create([
            'content_type' => ContentCategory::TYPE_FAQ,
            'slug' => 'learning',
            'title' => 'Обучение',
            'is_active' => true,
        ]);
        $paymentQuestion = $this->createFaqEntry('payment', 'Оплата?', category: $payments);
        $learningQuestion = $this->createFaqEntry('learning', 'Обучение?', category: $learning);

        $this->placeEntry($paymentQuestion, ContentPlacement::FAQ_INDEX, 10);
        $this->placeEntry($learningQuestion, ContentPlacement::FAQ_INDEX, 20);

        $this->getJson('/api/v1/faqs?category=learning')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'learning')
            ->assertJsonPath('meta.total', 1);
    }

    public function test_faqs_validate_public_filters(): void
    {
        $this->getJson('/api/v1/faqs?placement=admin.content')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('placement');

        $this->getJson('/api/v1/faqs?limit=51')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('limit');
    }

    private function createFaqEntry(
        string $slug,
        string $question,
        string $status = ContentEntry::STATUS_PUBLISHED,
        int $sortOrder = 0,
        ?ContentCategory $category = null,
        mixed $publishedAt = null,
    ): ContentEntry {
        return ContentEntry::query()->create([
            'content_category_id' => $category?->id,
            'content_type' => ContentEntry::TYPE_FAQ,
            'slug' => $slug,
            'title' => $question,
            'body' => "Ответ на вопрос {$slug}.",
            'status' => $status,
            'sort_order' => $sortOrder,
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
