<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\OrderItem;
use App\Models\Review;
use App\Models\ReviewMedia;
use App\Models\ReviewSource;
use App\Models\SellableEntity;
use App\Models\Subject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicContentApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_subjects_returns_active_subjects_with_active_courses(): void
    {
        $subject = Subject::query()->create([
            'slug' => 'anatomiya',
            'title' => 'Анатомия',
            'sort_order' => 10,
            'is_active' => true,
        ]);
        $course = Course::query()->create([
            'subject_id' => $subject->id,
            'slug' => 'osteologiya',
            'title' => 'Остеология',
            'price_cents' => 150000,
            'sort_order' => 10,
            'is_active' => true,
        ]);
        SellableEntity::query()->create([
            'sellable_kind' => OrderItem::KIND_COURSE,
            'entity_type' => OrderItem::KIND_COURSE,
            'entity_id' => $course->id,
            'sku' => 'course:anatomiya:osteologiya',
            'title' => 'Остеология',
            'currency' => 'RUB',
            'price_cents' => 175000,
            'price_version' => 3,
            'status' => SellableEntity::STATUS_ACTIVE,
        ]);
        Course::query()->create([
            'subject_id' => $subject->id,
            'slug' => 'hidden',
            'title' => 'Скрытый курс',
            'is_active' => false,
        ]);
        Subject::query()->create([
            'slug' => 'hidden-subject',
            'title' => 'Скрытый предмет',
            'is_active' => false,
        ]);

        $this->getJson('/api/v1/catalog/subjects')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'anatomiya')
            ->assertJsonPath('data.0.courses.0.itemId', 'course:anatomiya:osteologiya')
            ->assertJsonPath('data.0.courses.0.price', 150000)
            ->assertJsonPath('data.0.courses.0.sellable.sku', 'course:anatomiya:osteologiya')
            ->assertJsonPath('data.0.courses.0.sellable.kind', OrderItem::KIND_COURSE)
            ->assertJsonPath('data.0.courses.0.sellable.price', 175000)
            ->assertJsonPath('data.0.courses.0.sellable.currency', 'RUB')
            ->assertJsonPath('data.0.courses.0.sellable.priceVersion', 3)
            ->assertJsonMissingPath('data.0.courses.0.sellable.id')
            ->assertJsonMissingPath('data.0.courses.0.sellable.status')
            ->assertJsonMissingPath('data.0.courses.0.sellable.metadata')
            ->assertJsonCount(1, 'data.0.courses');
    }

    public function test_catalog_subjects_keeps_sellable_null_without_active_course_sellable(): void
    {
        $subject = Subject::query()->create([
            'slug' => 'anatomiya',
            'title' => 'Анатомия',
            'sort_order' => 10,
            'is_active' => true,
        ]);
        $course = Course::query()->create([
            'subject_id' => $subject->id,
            'slug' => 'osteologiya',
            'title' => 'Остеология',
            'price_cents' => 150000,
            'sort_order' => 10,
            'is_active' => true,
        ]);
        SellableEntity::query()->create([
            'sellable_kind' => OrderItem::KIND_COURSE,
            'entity_type' => OrderItem::KIND_COURSE,
            'entity_id' => $course->id,
            'sku' => 'draft-course:anatomiya:osteologiya',
            'title' => 'Черновик курса',
            'currency' => 'RUB',
            'price_cents' => 175000,
            'price_version' => 1,
            'status' => SellableEntity::STATUS_DRAFT,
        ]);
        SellableEntity::query()->create([
            'sellable_kind' => OrderItem::KIND_LESSON,
            'entity_type' => OrderItem::KIND_COURSE,
            'entity_id' => $course->id,
            'sku' => 'lesson:anatomiya:osteologiya',
            'title' => 'Урок',
            'currency' => 'RUB',
            'price_cents' => 50000,
            'price_version' => 1,
            'status' => SellableEntity::STATUS_ACTIVE,
        ]);
        SellableEntity::query()->create([
            'sellable_kind' => OrderItem::KIND_COURSE,
            'entity_type' => OrderItem::KIND_COURSE,
            'entity_id' => $course->id,
            'sku' => 'archived-course:anatomiya:osteologiya',
            'title' => 'Архивный курс',
            'currency' => 'RUB',
            'price_cents' => 175000,
            'price_version' => 1,
            'status' => SellableEntity::STATUS_ARCHIVED,
        ]);

        $response = $this->getJson('/api/v1/catalog/subjects')
            ->assertOk()
            ->assertJsonPath('data.0.courses.0.itemId', 'course:anatomiya:osteologiya');

        $coursePayload = $response->json('data.0.courses.0');

        $this->assertArrayHasKey('sellable', $coursePayload);
        $this->assertNull($coursePayload['sellable']);
    }

    public function test_reviews_returns_only_published_reviews_with_filters_and_media(): void
    {
        $anatomy = Subject::query()->create([
            'slug' => 'anatomiya',
            'title' => 'Анатомия',
            'is_active' => true,
        ]);
        $osteology = Course::query()->create([
            'subject_id' => $anatomy->id,
            'slug' => 'osteologiya',
            'title' => 'Остеология',
            'is_active' => true,
        ]);
        $myology = Course::query()->create([
            'subject_id' => $anatomy->id,
            'slug' => 'miologiya',
            'title' => 'Миология',
            'is_active' => true,
        ]);

        $published = Review::query()->create([
            'course_id' => $osteology->id,
            'text' => 'Опубликованный отзыв по остеологии.',
            'display_name' => 'егор а.',
            'is_anonymous' => false,
            'status' => Review::STATUS_PUBLISHED,
            'source' => 'dev_seed',
            'review_written_at' => now()->subDays(2),
            'published_at' => now()->subDay(),
        ]);
        ReviewMedia::query()->create([
            'review_id' => $published->id,
            'type' => 'legacy_screenshot',
            'url' => '/images/reviews/demo.png',
            'alt' => 'Скриншот отзыва',
            'sort_order' => 10,
        ]);
        Review::query()->create([
            'course_id' => $myology->id,
            'text' => 'Черновик не должен быть публичным.',
            'display_name' => 'Черновик',
            'status' => Review::STATUS_DRAFT,
            'source' => 'legacy_manual',
        ]);
        Review::query()->create([
            'subject_id' => $anatomy->id,
            'text' => 'Опубликованный отзыв по анатомии без конкретного курса.',
            'display_name' => 'Анонимно',
            'is_anonymous' => true,
            'status' => Review::STATUS_PUBLISHED,
            'source' => 'legacy_ocr',
            'review_written_at' => now()->subDays(3),
            'published_at' => now()->subDays(3),
        ]);

        $this->getJson('/api/v1/reviews?subject=anatomiya&course=osteologiya')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.displayName', 'Егор А.')
            ->assertJsonMissingPath('data.0.rating')
            ->assertJsonPath('data.0.course.slug', 'osteologiya')
            ->assertJsonPath('data.0.subject.slug', 'anatomiya')
            ->assertJsonPath('data.0.reviewWrittenAt', $published->review_written_at->toIso8601String())
            ->assertJsonPath('data.0.media.0.type', 'legacy_screenshot')
            ->assertJsonPath('meta.totalPublished', 2)
            ->assertJsonPath('meta.filters.subject', 'anatomiya')
            ->assertJsonPath('meta.filters.course', 'osteologiya')
            ->assertJsonMissingPath('meta.filters.rating')
            ->assertJsonCount(1, 'meta.facets.subjects')
            ->assertJsonPath('meta.facets.subjects.0.slug', 'anatomiya')
            ->assertJsonPath('meta.facets.subjects.0.reviewsCount', 2)
            ->assertJsonCount(1, 'meta.facets.courses')
            ->assertJsonPath('meta.facets.courses.0.courseSlug', 'osteologiya')
            ->assertJsonPath('meta.facets.courses.0.subjectSlug', 'anatomiya')
            ->assertJsonPath('meta.facets.courses.0.reviewsCount', 1);

        $this->getJson('/api/v1/reviews?subject=anatomiya')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.filters.subject', 'anatomiya')
            ->assertJsonMissingPath('data.0.rating')
            ->assertJsonMissingPath('data.1.rating');
    }

    public function test_reviews_api_excludes_non_public_workflow_states_from_list_facets_and_show(): void
    {
        $subject = Subject::query()->create([
            'slug' => 'anatomiya',
            'title' => 'Анатомия',
            'is_active' => true,
        ]);
        $course = Course::query()->create([
            'subject_id' => $subject->id,
            'slug' => 'osteologiya',
            'title' => 'Остеология',
            'is_active' => true,
        ]);

        $public = Review::query()->create([
            'subject_id' => $subject->id,
            'course_id' => $course->id,
            'text' => 'Публичный отзыв.',
            'display_name' => 'Публичный автор',
            'status' => Review::STATUS_PUBLISHED,
            'moderation_status' => Review::MODERATION_APPROVED,
            'publication_status' => Review::PUBLICATION_PUBLISHED,
            'dedupe_status' => Review::DEDUPE_UNIQUE,
            'source' => 'manual',
            'review_written_at' => now()->subDay(),
            'published_at' => now()->subDay(),
        ]);

        $excludedReviews = [
            Review::query()->create([
                'subject_id' => $subject->id,
                'course_id' => $course->id,
                'text' => 'Черновик.',
                'display_name' => 'Draft',
                'status' => Review::STATUS_DRAFT,
                'source' => 'manual',
                'published_at' => now()->subDay(),
            ]),
            Review::query()->create([
                'subject_id' => $subject->id,
                'course_id' => $course->id,
                'text' => 'Скрытый отзыв.',
                'display_name' => 'Hidden',
                'status' => Review::STATUS_PUBLISHED,
                'moderation_status' => Review::MODERATION_APPROVED,
                'publication_status' => Review::PUBLICATION_HIDDEN,
                'source' => 'manual',
                'published_at' => now()->subDay(),
            ]),
            Review::query()->create([
                'subject_id' => $subject->id,
                'course_id' => $course->id,
                'text' => 'Архивный отзыв.',
                'display_name' => 'Archived',
                'status' => Review::STATUS_PUBLISHED,
                'moderation_status' => Review::MODERATION_APPROVED,
                'publication_status' => Review::PUBLICATION_ARCHIVED,
                'source' => 'manual',
                'published_at' => now()->subDay(),
            ]),
            Review::query()->create([
                'subject_id' => $subject->id,
                'course_id' => $course->id,
                'text' => 'Ожидает модерации.',
                'display_name' => 'Pending',
                'status' => Review::STATUS_PUBLISHED,
                'moderation_status' => Review::MODERATION_PENDING,
                'publication_status' => Review::PUBLICATION_PUBLISHED,
                'source' => 'manual',
                'published_at' => now()->subDay(),
            ]),
            Review::query()->create([
                'subject_id' => $subject->id,
                'course_id' => $course->id,
                'text' => 'Требует проверки.',
                'display_name' => 'Needs Review',
                'status' => Review::STATUS_PUBLISHED,
                'moderation_status' => Review::MODERATION_NEEDS_REVIEW,
                'publication_status' => Review::PUBLICATION_PUBLISHED,
                'source' => 'manual',
                'published_at' => now()->subDay(),
            ]),
            Review::query()->create([
                'subject_id' => $subject->id,
                'course_id' => $course->id,
                'text' => 'Отклонён модерацией.',
                'display_name' => 'Rejected',
                'status' => Review::STATUS_PUBLISHED,
                'moderation_status' => Review::MODERATION_REJECTED,
                'publication_status' => Review::PUBLICATION_PUBLISHED,
                'source' => 'manual',
                'published_at' => now()->subDay(),
            ]),
            Review::query()->create([
                'subject_id' => $subject->id,
                'course_id' => $course->id,
                'text' => 'Дубль.',
                'display_name' => 'Duplicate',
                'status' => Review::STATUS_PUBLISHED,
                'moderation_status' => Review::MODERATION_APPROVED,
                'publication_status' => Review::PUBLICATION_PUBLISHED,
                'dedupe_status' => Review::DEDUPE_DUPLICATE,
                'source' => 'manual',
                'published_at' => now()->subDay(),
            ]),
            Review::query()->create([
                'subject_id' => $subject->id,
                'course_id' => $course->id,
                'text' => 'Без даты публикации.',
                'display_name' => 'No Date',
                'status' => Review::STATUS_PUBLISHED,
                'moderation_status' => Review::MODERATION_APPROVED,
                'publication_status' => Review::PUBLICATION_PUBLISHED,
                'source' => 'manual',
                'published_at' => null,
            ]),
            Review::query()->create([
                'subject_id' => $subject->id,
                'course_id' => $course->id,
                'text' => 'Будущий отзыв.',
                'display_name' => 'Future',
                'status' => Review::STATUS_PUBLISHED,
                'moderation_status' => Review::MODERATION_APPROVED,
                'publication_status' => Review::PUBLICATION_PUBLISHED,
                'source' => 'manual',
                'published_at' => now()->addDay(),
            ]),
        ];

        $this->getJson('/api/v1/reviews?subject=anatomiya&course=osteologiya')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $public->id)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.totalPublished', 1)
            ->assertJsonPath('meta.facets.subjects.0.reviewsCount', 1)
            ->assertJsonPath('meta.facets.courses.0.reviewsCount', 1);

        foreach ($excludedReviews as $review) {
            $this->getJson("/api/v1/reviews/{$review->id}")
                ->assertNotFound();
        }
    }

    public function test_reviews_api_paginates_published_reviews(): void
    {
        foreach (range(1, 15) as $index) {
            Review::query()->create([
                'text' => "Опубликованный отзыв {$index}.",
                'display_name' => "Студент {$index}",
                'is_anonymous' => false,
                'status' => Review::STATUS_PUBLISHED,
                'source' => 'dev_seed',
                'review_written_at' => now()->subMinutes($index),
                'published_at' => now()->subMinutes($index),
            ]);
        }

        $this->getJson('/api/v1/reviews?per_page=12&page=2')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.displayName', 'Студент 13')
            ->assertJsonPath('meta.page', 2)
            ->assertJsonPath('meta.perPage', 12)
            ->assertJsonPath('meta.total', 15)
            ->assertJsonPath('meta.lastPage', 2)
            ->assertJsonPath('meta.totalPublished', 15);
    }

    public function test_reviews_show_returns_published_review_with_filtered_navigation(): void
    {
        $anatomy = Subject::query()->create([
            'slug' => 'anatomiya',
            'title' => 'Анатомия',
            'is_active' => true,
        ]);
        $histology = Subject::query()->create([
            'slug' => 'gistologiya',
            'title' => 'Гистология',
            'is_active' => true,
        ]);

        $newer = Review::query()->create([
            'subject_id' => $anatomy->id,
            'text' => 'Новый отзыв по анатомии.',
            'display_name' => 'Студент 1',
            'status' => Review::STATUS_PUBLISHED,
            'source' => 'dev_seed',
            'review_written_at' => now()->subHour(),
            'published_at' => now()->subHour(),
        ]);
        $middle = Review::query()->create([
            'subject_id' => $anatomy->id,
            'text' => 'Средний отзыв по анатомии.',
            'display_name' => 'Студент 2',
            'status' => Review::STATUS_PUBLISHED,
            'source' => 'dev_seed',
            'review_written_at' => now()->subHours(2),
            'published_at' => now()->subHours(2),
        ]);
        $older = Review::query()->create([
            'subject_id' => $anatomy->id,
            'text' => 'Старый отзыв по анатомии.',
            'display_name' => 'Студент 3',
            'status' => Review::STATUS_PUBLISHED,
            'source' => 'dev_seed',
            'review_written_at' => now()->subHours(3),
            'published_at' => now()->subHours(3),
        ]);
        Review::query()->create([
            'subject_id' => $histology->id,
            'text' => 'Отзыв по гистологии.',
            'display_name' => 'Студент 4',
            'status' => Review::STATUS_PUBLISHED,
            'source' => 'dev_seed',
            'review_written_at' => now()->subMinutes(30),
            'published_at' => now()->subMinutes(30),
        ]);

        $this->getJson("/api/v1/reviews/{$middle->id}?subject=anatomiya")
            ->assertOk()
            ->assertJsonPath('data.id', $middle->id)
            ->assertJsonMissingPath('data.rating')
            ->assertJsonPath('data.source', 'dev_seed')
            ->assertJsonPath('data.importOrigin', 'dev_seed')
            ->assertJsonPath('data.subject.slug', 'anatomiya')
            ->assertJsonPath('meta.navigation.previousId', $newer->id)
            ->assertJsonPath('meta.navigation.nextId', $older->id)
            ->assertJsonPath('meta.navigation.position', 2)
            ->assertJsonPath('meta.navigation.total', 3)
            ->assertJsonPath('meta.navigation.filters.subject', 'anatomiya');

        $this->getJson("/api/v1/reviews/{$middle->id}?subject=gistologiya")
            ->assertNotFound();
    }

    public function test_reviews_api_uses_evidence_when_review_media_absent(): void
    {
        $review = Review::query()->create([
            'text' => 'Отзыв с вложением только через evidence.',
            'display_name' => 'Секретный источник',
            'is_anonymous' => true,
            'status' => Review::STATUS_PUBLISHED,
            'source' => 'legacy_ocr',
            'review_written_at' => now()->subHours(1),
            'published_at' => now()->subHour(),
        ]);

        $source = $review->sources()->create([
            'source_type' => ReviewSource::TYPE_TELEGRAM,
            'source_ref' => 'evidence-only',
            'is_public' => false,
        ]);

        $source->evidence()->create([
            'review_id' => $review->id,
            'type' => 'screenshot',
            'file_path' => 'reviews/sources/legacy/otzyvy-001.webp',
            'storage_url' => '/storage/reviews/sources/legacy/otzyvy-001.webp',
            'is_public' => true,
            'caption' => 'Скриншот отзыва',
            'provenance' => 'legacy_site',
            'sha256' => hash('sha256', 'public-evidence'),
            'metadata' => [
                'width' => 1024,
                'height' => 768,
                'sort_order' => 10,
            ],
        ]);
        $source->evidence()->create([
            'review_id' => $review->id,
            'type' => 'screenshot',
            'file_path' => 'reviews/sources/legacy/private.webp',
            'storage_url' => '/storage/reviews/sources/legacy/private.webp',
            'is_public' => false,
            'caption' => 'Внутренний скриншот',
            'provenance' => 'legacy_site',
            'sha256' => hash('sha256', 'private-evidence'),
        ]);
        $source->evidence()->create([
            'review_id' => $review->id,
            'type' => 'attachment',
            'file_path' => 'reviews/sources/legacy/source.webarchive',
            'storage_url' => '/storage/reviews/sources/legacy/source.webarchive',
            'is_public' => true,
            'caption' => 'Техническое вложение',
            'provenance' => 'legacy_site',
            'sha256' => hash('sha256', 'attachment-evidence'),
        ]);

        $this->getJson('/api/v1/reviews')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonCount(1, 'data.0.media')
            ->assertJsonPath('data.0.media.0.id', 'review_evidence:1')
            ->assertJsonPath('data.0.media.0.type', 'screenshot')
            ->assertJsonPath('data.0.media.0.url', '/storage/reviews/sources/legacy/otzyvy-001.webp');
    }

    public function test_reviews_api_hides_legacy_text_screenshot_when_public_legacy_evidence_exists(): void
    {
        $review = Review::query()->create([
            'text' => 'Отзыв, найденный и в Telegram, и на старой странице отзывов.',
            'display_name' => 'Диана',
            'is_anonymous' => false,
            'status' => Review::STATUS_PUBLISHED,
            'source' => 'legacy_ocr',
            'review_written_at' => now()->subHours(3),
            'published_at' => now()->subHour(),
        ]);

        ReviewMedia::query()->create([
            'review_id' => $review->id,
            'type' => 'legacy_screenshot',
            'url' => '/images/reviews/telegram/text-screenshot.webp',
            'alt' => 'Скриншот отзыва: Диана',
            'width' => 591,
            'height' => 1280,
            'sort_order' => 10,
        ]);

        $source = $review->sources()->create([
            'source_type' => ReviewSource::TYPE_LEGACY_SITE,
            'source_ref' => 'legacy_site:otzyvy:018',
            'source_url' => 'https://christmedschool.com/otzyvy',
            'is_public' => false,
        ]);

        $source->evidence()->create([
            'review_id' => $review->id,
            'type' => 'screenshot',
            'file_path' => 'reviews/sources/legacy/otzyvy/legacy_site-otzyvy-018.webp',
            'storage_url' => '/storage/reviews/sources/legacy/otzyvy/legacy_site-otzyvy-018.webp',
            'is_public' => true,
            'caption' => 'Скриншот отзыва со старого сайта',
            'provenance' => ReviewSource::TYPE_LEGACY_SITE,
            'sha256' => hash('sha256', 'legacy-site-text-screenshot'),
            'metadata' => [
                'width' => 591,
                'height' => 1280,
                'sort_order' => 0,
            ],
        ]);

        $this->getJson('/api/v1/reviews')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonCount(1, 'data.0.media')
            ->assertJsonPath('data.0.media.0.id', 'review_evidence:1')
            ->assertJsonPath('data.0.media.0.type', 'screenshot')
            ->assertJsonPath('data.0.media.0.url', '/storage/reviews/sources/legacy/otzyvy/legacy_site-otzyvy-018.webp')
            ->assertJsonPath('data.0.media.0.provenance', 'legacy_site')
            ->assertJsonPath('data.0.media.0.sourceType', ReviewSource::TYPE_LEGACY_SITE);
    }

    public function test_reviews_api_exposes_public_source_link_and_hides_service_screenshots(): void
    {
        $review = Review::query()->create([
            'text' => 'Отзыв из внешнего публичного источника с фото вложением.',
            'display_name' => 'Татьяна Марфенко',
            'is_anonymous' => false,
            'status' => Review::STATUS_PUBLISHED,
            'source' => 'legacy_ocr',
            'review_written_at' => now()->subDays(2),
            'published_at' => now()->subDay(),
        ]);

        ReviewMedia::query()->create([
            'review_id' => $review->id,
            'type' => 'legacy_screenshot',
            'url' => '/images/reviews/telegram/text-screenshot.webp',
            'alt' => 'Скриншот текста отзыва',
            'sort_order' => 0,
        ]);

        $source = $review->sources()->create([
            'source_type' => ReviewSource::TYPE_VK_WEBARCHIVE,
            'source_ref' => 'vk:test',
            'source_url' => 'https://example.invalid/demo',
            'is_public' => true,
        ]);

        $source->evidence()->create([
            'review_id' => $review->id,
            'type' => 'screenshot',
            'file_path' => 'reviews/sources/vk/text-screenshot.webp',
            'storage_url' => '/storage/reviews/sources/vk/text-screenshot.webp',
            'is_public' => true,
            'caption' => 'Скриншот карточки VK',
            'provenance' => ReviewSource::TYPE_VK_WEBARCHIVE,
            'sha256' => hash('sha256', 'vk-text-screenshot'),
        ]);

        $source->evidence()->create([
            'review_id' => $review->id,
            'type' => 'image',
            'file_path' => 'reviews/sources/vk/gradebook.webp',
            'storage_url' => '/storage/reviews/sources/vk/gradebook.webp',
            'is_public' => true,
            'caption' => 'Фото зачётки',
            'provenance' => ReviewSource::TYPE_VK_WEBARCHIVE,
            'sha256' => hash('sha256', 'vk-gradebook'),
            'metadata' => [
                'width' => 604,
                'height' => 453,
            ],
        ]);

        $this->getJson('/api/v1/reviews')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonCount(1, 'data.0.sourceLinks')
            ->assertJsonPath('data.0.sourceLinks.0.label', 'Смотреть отзыв ВКонтакте')
            ->assertJsonPath('data.0.sourceLinks.0.url', 'https://example.invalid/demo')
            ->assertJsonCount(1, 'data.0.media')
            ->assertJsonPath('data.0.media.0.type', 'image')
            ->assertJsonPath('data.0.media.0.url', '/storage/reviews/sources/vk/gradebook.webp');
    }

    public function test_reviews_api_exposes_only_safe_source_links_and_media_fields(): void
    {
        $review = Review::query()->create([
            'text' => 'Публичный отзыв с несколькими источниками.',
            'display_name' => 'Мария',
            'status' => Review::STATUS_PUBLISHED,
            'source' => 'legacy_ocr',
            'review_written_at' => now()->subDays(4),
            'published_at' => now()->subDay(),
        ]);

        $publicYandexSource = $review->sources()->create([
            'source_type' => ReviewSource::TYPE_YANDEX_MAPS_WEBARCHIVE,
            'source_ref' => 'yandex:public',
            'source_url' => 'https://example.invalid/demo',
            'is_public' => true,
            'external_author_name' => 'raw maria',
            'raw_text' => 'Сырой текст отзыва.',
            'raw_payload' => ['external_rating' => 5],
            'content_hash' => hash('sha256', 'raw-public-yandex'),
        ]);

        $review->sources()->create([
            'source_type' => ReviewSource::TYPE_VK_WEBARCHIVE,
            'source_ref' => 'vk:private',
            'source_url' => 'https://example.invalid/demo',
            'is_public' => false,
        ]);
        $review->sources()->create([
            'source_type' => ReviewSource::TYPE_LEGACY_SITE,
            'source_ref' => 'legacy:public',
            'source_url' => 'https://christmedschool.com/otzyvy',
            'is_public' => true,
        ]);
        $review->sources()->create([
            'source_type' => ReviewSource::TYPE_MANUAL,
            'source_ref' => 'manual:public',
            'source_url' => 'https://example.test/manual-source',
            'is_public' => true,
        ]);

        $publicYandexSource->evidence()->create([
            'review_id' => $review->id,
            'type' => 'screenshot',
            'file_path' => 'reviews/sources/yandex/service-screenshot.webp',
            'storage_url' => '/storage/reviews/sources/yandex/service-screenshot.webp',
            'is_public' => true,
            'caption' => 'Сервисный скриншот Яндекс.Карт',
            'provenance' => ReviewSource::TYPE_YANDEX_MAPS_WEBARCHIVE,
            'sha256' => hash('sha256', 'service-screenshot'),
        ]);
        $publicYandexSource->evidence()->create([
            'review_id' => $review->id,
            'type' => 'image',
            'file_path' => 'reviews/sources/yandex/public-image.webp',
            'storage_url' => '/storage/reviews/sources/yandex/public-image.webp',
            'is_public' => true,
            'caption' => 'Фото к отзыву',
            'provenance' => ReviewSource::TYPE_YANDEX_MAPS_WEBARCHIVE,
            'sha256' => hash('sha256', 'public-image'),
            'metadata' => [
                'width' => 800,
                'height' => 600,
            ],
        ]);
        $publicYandexSource->evidence()->create([
            'review_id' => $review->id,
            'type' => 'image',
            'file_path' => 'reviews/sources/yandex/private-image.webp',
            'storage_url' => '/storage/reviews/sources/yandex/private-image.webp',
            'is_public' => false,
            'caption' => 'Приватное фото',
            'provenance' => ReviewSource::TYPE_YANDEX_MAPS_WEBARCHIVE,
            'sha256' => hash('sha256', 'private-image'),
        ]);
        $publicYandexSource->evidence()->create([
            'review_id' => $review->id,
            'type' => 'attachment',
            'file_path' => 'reviews/sources/yandex/raw.webarchive',
            'storage_url' => '/storage/reviews/sources/yandex/raw.webarchive',
            'is_public' => true,
            'caption' => 'Raw webarchive',
            'provenance' => ReviewSource::TYPE_YANDEX_MAPS_WEBARCHIVE,
            'sha256' => hash('sha256', 'raw-webarchive'),
        ]);

        $response = $this->getJson("/api/v1/reviews/{$review->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data.sourceLinks')
            ->assertJsonPath('data.sourceLinks.0.type', ReviewSource::TYPE_YANDEX_MAPS_WEBARCHIVE)
            ->assertJsonPath('data.sourceLinks.0.label', 'Смотреть отзыв на Яндекс.Картах')
            ->assertJsonPath('data.sourceLinks.0.url', 'https://example.invalid/demo')
            ->assertJsonCount(1, 'data.media')
            ->assertJsonPath('data.media.0.id', 'review_evidence:2')
            ->assertJsonPath('data.media.0.type', 'image')
            ->assertJsonPath('data.media.0.url', '/storage/reviews/sources/yandex/public-image.webp')
            ->assertJsonPath('data.media.0.provenance', ReviewSource::TYPE_YANDEX_MAPS_WEBARCHIVE)
            ->assertJsonPath('data.media.0.sourceType', ReviewSource::TYPE_YANDEX_MAPS_WEBARCHIVE)
            ->assertJsonMissingPath('data.rating')
            ->assertJsonMissingPath('data.external_rating')
            ->assertJsonMissingPath('data.raw_text')
            ->assertJsonMissingPath('data.rawPayload')
            ->assertJsonMissingPath('data.contentHash')
            ->assertJsonMissingPath('data.sourceLinks.1')
            ->assertJsonMissingPath('data.media.1');

        $payload = json_encode($response->json(), JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('private-review', $payload);
        $this->assertStringNotContainsString('christmedschool.com/otzyvy', $payload);
        $this->assertStringNotContainsString('manual-source', $payload);
        $this->assertStringNotContainsString('service-screenshot.webp', $payload);
        $this->assertStringNotContainsString('private-image.webp', $payload);
        $this->assertStringNotContainsString('raw.webarchive', $payload);
        $this->assertStringNotContainsString('raw maria', $payload);
        $this->assertStringNotContainsString('raw-public-yandex', $payload);
    }

    public function test_reviews_subject_facets_are_sorted_by_review_count_desc(): void
    {
        $rare = Subject::query()->create([
            'slug' => 'rare-subject',
            'title' => 'Редкий предмет',
            'sort_order' => 1,
            'is_active' => true,
        ]);
        $popular = Subject::query()->create([
            'slug' => 'popular-subject',
            'title' => 'Популярный предмет',
            'sort_order' => 20,
            'is_active' => true,
        ]);

        Review::query()->create([
            'subject_id' => $rare->id,
            'text' => 'Один отзыв.',
            'display_name' => 'Автор',
            'status' => Review::STATUS_PUBLISHED,
            'source' => 'manual',
            'published_at' => now(),
        ]);

        foreach (range(1, 3) as $index) {
            Review::query()->create([
                'subject_id' => $popular->id,
                'text' => "Популярный отзыв {$index}.",
                'display_name' => "Автор {$index}",
                'status' => Review::STATUS_PUBLISHED,
                'source' => 'manual',
                'published_at' => now(),
            ]);
        }

        $this->getJson('/api/v1/reviews')
            ->assertOk()
            ->assertJsonPath('meta.facets.subjects.0.slug', 'popular-subject')
            ->assertJsonPath('meta.facets.subjects.0.reviewsCount', 3)
            ->assertJsonPath('meta.facets.subjects.1.slug', 'rare-subject')
            ->assertJsonPath('meta.facets.subjects.1.reviewsCount', 1);
    }

    public function test_reviews_api_normalizes_public_display_names(): void
    {
        Review::query()->create([
            'text' => 'Отзыв с нечитаемым именем.',
            'display_name' => 'freekl1e ау лол',
            'status' => Review::STATUS_PUBLISHED,
            'source' => 'manual',
            'review_written_at' => now(),
            'published_at' => now(),
        ]);
        Review::query()->create([
            'text' => 'Отзыв с человеческим именем.',
            'display_name' => 'егор а.',
            'status' => Review::STATUS_PUBLISHED,
            'source' => 'manual',
            'review_written_at' => now()->subDay(),
            'published_at' => now()->subDay(),
        ]);

        $this->getJson('/api/v1/reviews')
            ->assertOk()
            ->assertJsonPath('data.0.displayName', 'Анонимный автор')
            ->assertJsonPath('data.1.displayName', 'Егор А.');
    }

    public function test_anonymous_reviews_never_expose_a_stored_name_or_author_photo(): void
    {
        $review = Review::query()->create([
            'text' => 'Опубликованный анонимный отзыв.',
            'display_name' => 'Скрытое Имя',
            'author_photo_path' => 'review-authors/private-identity.webp',
            'is_anonymous' => true,
            'status' => Review::STATUS_PUBLISHED,
            'source' => 'manual',
            'review_written_at' => now(),
            'published_at' => now(),
        ]);

        foreach (['/api/v1/reviews', "/api/v1/reviews/{$review->id}"] as $url) {
            $prefix = $url === '/api/v1/reviews' ? 'data.0' : 'data';
            $this->getJson($url)
                ->assertOk()
                ->assertJsonPath($prefix.'.displayName', 'Анонимный автор')
                ->assertJsonPath($prefix.'.authorPhotoUrl', null)
                ->assertJsonMissingPath($prefix.'.authorPhotoPath');
        }
    }

    public function test_reviews_api_ignores_removed_rating_filter_without_accept_header(): void
    {
        $this->get('/api/v1/reviews?rating=9')
            ->assertOk()
            ->assertJsonMissingPath('meta.filters.rating');
    }

    public function test_reviews_api_rejects_unknown_sort_contract(): void
    {
        $this->getJson('/api/v1/reviews?sort=oldest')
            ->assertUnprocessable();

        $this->getJson('/api/v1/reviews?sort=latest')
            ->assertOk()
            ->assertJsonPath('meta.sort', 'latest');
    }
}
