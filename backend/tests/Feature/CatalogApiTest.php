<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Lesson;
use App\Models\OrderItem;
use App\Models\SellableEntity;
use App\Models\Subject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_subjects_returns_only_active_public_tree(): void
    {
        $subject = $this->createSubject(
            slug: 'anatomiya',
            title: 'Анатомия',
            sortOrder: 20
        );
        $firstCourse = $this->createCourse(
            subject: $subject,
            slug: 'osteologiya',
            title: 'Остеология',
            sortOrder: 20
        );
        $secondCourse = $this->createCourse(
            subject: $subject,
            slug: 'miologiya',
            title: 'Миология',
            sortOrder: 10
        );

        $this->createCourse(subject: $subject, slug: 'hidden-course', title: 'Скрытый курс', isActive: false);
        $this->createSubject(slug: 'hidden-subject', title: 'Скрытый предмет', isActive: false);
        $this->createLesson(course: $firstCourse, slug: 'inactive-lesson', title: 'Скрытый урок', isActive: false);
        $this->createLesson(course: $firstCourse, slug: 'bones', title: 'Кости', sortOrder: 20);
        $this->createLesson(course: $firstCourse, slug: 'intro', title: 'Введение', sortOrder: 10);
        $this->createLesson(course: $secondCourse, slug: 'muscles', title: 'Мышцы');

        $response = $this->getJson('/api/v1/catalog/subjects')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'anatomiya')
            ->assertJsonPath('data.0.title', 'Анатомия')
            ->assertJsonCount(2, 'data.0.courses');

        $courses = $response->json('data.0.courses');

        $this->assertSame(['miologiya', 'osteologiya'], array_column($courses, 'slug'));
        $this->assertSame(['muscles'], array_column($courses[0]['lessons'], 'slug'));
        $this->assertSame(['intro', 'bones'], array_column($courses[1]['lessons'], 'slug'));
    }

    public function test_catalog_subjects_exposes_only_active_course_sellable_payload(): void
    {
        $subject = $this->createSubject(slug: 'anatomiya', title: 'Анатомия');
        $course = $this->createCourse(
            subject: $subject,
            slug: 'osteologiya',
            title: 'Остеология',
            priceCents: 150000
        );

        $this->createSellable(
            course: $course,
            sku: 'draft-course:anatomiya:osteologiya',
            status: SellableEntity::STATUS_DRAFT
        );
        $this->createSellable(
            course: $course,
            sku: 'lesson:anatomiya:osteologiya',
            kind: OrderItem::KIND_LESSON
        );
        $this->createSellable(
            course: $course,
            sku: 'course:anatomiya:osteologiya',
            priceCents: 175000,
            priceVersion: 3
        );

        $response = $this->getJson('/api/v1/catalog/subjects')
            ->assertOk()
            ->assertJsonPath('data.0.courses.0.itemId', 'course:anatomiya:osteologiya')
            ->assertJsonPath('data.0.courses.0.price', 150000)
            ->assertJsonPath('data.0.courses.0.sellable.sku', 'course:anatomiya:osteologiya')
            ->assertJsonPath('data.0.courses.0.sellable.kind', OrderItem::KIND_COURSE)
            ->assertJsonPath('data.0.courses.0.sellable.price', 175000)
            ->assertJsonPath('data.0.courses.0.sellable.currency', 'RUB')
            ->assertJsonPath('data.0.courses.0.sellable.priceVersion', 3)
            ->assertJsonMissingPath('data.0.courses.0.sellable.id')
            ->assertJsonMissingPath('data.0.courses.0.sellable.status')
            ->assertJsonMissingPath('data.0.courses.0.sellable.metadata');

        $this->assertSame(
            ['sku', 'kind', 'price', 'currency', 'priceVersion'],
            array_keys($response->json('data.0.courses.0.sellable'))
        );
    }

    private function createSubject(
        string $slug,
        string $title,
        int $sortOrder = 10,
        bool $isActive = true
    ): Subject {
        return Subject::query()->create([
            'slug' => $slug,
            'title' => $title,
            'sort_order' => $sortOrder,
            'is_active' => $isActive,
        ]);
    }

    private function createCourse(
        Subject $subject,
        string $slug,
        string $title,
        int $priceCents = 0,
        int $sortOrder = 10,
        bool $isActive = true
    ): Course {
        return Course::query()->create([
            'subject_id' => $subject->id,
            'slug' => $slug,
            'title' => $title,
            'price_cents' => $priceCents,
            'sort_order' => $sortOrder,
            'is_active' => $isActive,
        ]);
    }

    private function createLesson(
        Course $course,
        string $slug,
        string $title,
        int $sortOrder = 10,
        bool $isActive = true
    ): Lesson {
        return Lesson::query()->create([
            'course_id' => $course->id,
            'slug' => $slug,
            'title' => $title,
            'sort_order' => $sortOrder,
            'is_active' => $isActive,
        ]);
    }

    private function createSellable(
        Course $course,
        string $sku,
        string $kind = OrderItem::KIND_COURSE,
        string $status = SellableEntity::STATUS_ACTIVE,
        int $priceCents = 100000,
        int $priceVersion = 1
    ): SellableEntity {
        return SellableEntity::query()->create([
            'sellable_kind' => $kind,
            'entity_type' => OrderItem::KIND_COURSE,
            'entity_id' => $course->id,
            'sku' => $sku,
            'title' => $course->title,
            'currency' => 'RUB',
            'price_cents' => $priceCents,
            'price_version' => $priceVersion,
            'status' => $status,
            'metadata' => ['source' => 'test'],
        ]);
    }
}
