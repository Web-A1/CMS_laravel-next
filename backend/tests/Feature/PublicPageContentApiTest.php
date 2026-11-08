<?php

namespace Tests\Feature;

use App\Models\ContentEntry;
use App\Models\ContentPlacement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicPageContentApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_about_page_returns_published_enabled_page_content(): void
    {
        $about = $this->createPageEntry(
            title: 'О школе',
            body: 'Christmedschool помогает студентам медицинских вузов.',
            description: 'Описание школы для SEO.',
            seoTitle: 'О Christmedschool',
        );
        $this->placeEntry($about, ContentPlacement::ABOUT_PAGE, 15);

        $this->getJson('/api/v1/pages/about')
            ->assertOk()
            ->assertJsonPath('data.slug', 'about')
            ->assertJsonPath('data.title', 'О школе')
            ->assertJsonPath('data.description', 'Описание школы для SEO.')
            ->assertJsonPath('data.body', 'Christmedschool помогает студентам медицинских вузов.')
            ->assertJsonPath('data.sortOrder', 15)
            ->assertJsonPath('data.seoTitle', 'О Christmedschool')
            ->assertJsonPath('data.seoDescription', 'Описание школы для SEO.');
    }

    public function test_about_page_hides_unpublished_unplaced_disabled_and_wrong_type_content(): void
    {
        $negativeCases = [
            'draft' => fn () => $this->createPageEntry(status: ContentEntry::STATUS_DRAFT),
            'hidden' => fn () => $this->createPageEntry(status: ContentEntry::STATUS_HIDDEN),
            'archived' => fn () => $this->createPageEntry(status: ContentEntry::STATUS_ARCHIVED),
            'future' => fn () => $this->createPageEntry(publishedAt: now()->addDay()),
            'unplaced' => fn () => $this->createPageEntry(),
            'disabled' => function (): ContentEntry {
                $entry = $this->createPageEntry();
                $this->placeEntry($entry, ContentPlacement::ABOUT_PAGE, 10, false);

                return $entry;
            },
            'wrong type' => function (): ContentEntry {
                $entry = $this->createEntry(ContentEntry::TYPE_ARTICLE, 'about', 'Статья о школе');
                $this->placeEntry($entry, ContentPlacement::ABOUT_PAGE, 10);

                return $entry;
            },
            'wrong placement' => function (): ContentEntry {
                $entry = $this->createPageEntry();
                $this->placeEntry($entry, ContentPlacement::ARTICLES_INDEX, 10);

                return $entry;
            },
        ];

        foreach ($negativeCases as $label => $setup) {
            ContentPlacement::query()->delete();
            ContentEntry::query()->delete();

            $setup();

            $this->getJson('/api/v1/pages/about')
                ->assertNotFound();
        }
    }

    public function test_about_page_does_not_accept_route_method_or_selection_drift(): void
    {
        $wrongType = $this->createEntry(ContentEntry::TYPE_FAQ, 'about', 'FAQ о школе');
        $this->placeEntry($wrongType, ContentPlacement::ABOUT_PAGE, 10);

        $this->getJson('/api/v1/pages/contact')
            ->assertNotFound();

        $this->postJson('/api/v1/pages/about')
            ->assertStatus(405);

        $this->getJson('/api/v1/pages/about?content_type=faq&placement=pages.about')
            ->assertNotFound();
    }

    private function createPageEntry(
        string $title = 'О школе',
        string $body = 'Текст страницы о школе.',
        ?string $description = null,
        string $status = ContentEntry::STATUS_PUBLISHED,
        int $sortOrder = 0,
        mixed $publishedAt = null,
        ?string $seoTitle = null,
    ): ContentEntry {
        return $this->createEntry(
            ContentEntry::TYPE_PAGE,
            'about',
            $title,
            body: $body,
            description: $description,
            status: $status,
            sortOrder: $sortOrder,
            publishedAt: $publishedAt,
            seoTitle: $seoTitle,
        );
    }

    private function createEntry(
        string $contentType,
        string $slug,
        string $title,
        string $body = 'Текст публичной страницы.',
        ?string $description = null,
        string $status = ContentEntry::STATUS_PUBLISHED,
        int $sortOrder = 0,
        mixed $publishedAt = null,
        ?string $seoTitle = null,
    ): ContentEntry {
        return ContentEntry::query()->create([
            'content_type' => $contentType,
            'slug' => $slug,
            'title' => $title,
            'body' => $body,
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
