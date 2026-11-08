<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Lesson;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\SellableEntity;
use App\Models\SellableGrantRule;
use App\Models\Subject;
use App\Models\User;
use App\Models\UserEntitlementGrant;
use Database\Seeders\CatalogSellableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CatalogSellableSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_creates_active_course_sellable_and_grant_rules_for_active_course(): void
    {
        [$course, $lessons] = $this->createCourseWithLessons(priceCents: 150000);

        $this->seed(CatalogSellableSeeder::class);

        $sellable = SellableEntity::query()->where('sku', $course->itemId())->firstOrFail();

        $this->assertSame(OrderItem::KIND_COURSE, $sellable->sellable_kind);
        $this->assertSame(OrderItem::KIND_COURSE, $sellable->entity_type);
        $this->assertSame($course->id, $sellable->entity_id);
        $this->assertSame($course->title, $sellable->title);
        $this->assertSame('RUB', $sellable->currency);
        $this->assertSame(150000, $sellable->price_cents);
        $this->assertSame(1, $sellable->price_version);
        $this->assertSame(SellableEntity::STATUS_ACTIVE, $sellable->status);
        $this->assertSame('catalog_sellable_seeder', $sellable->metadata['managed_by']);

        $this->assertSame(
            $lessons->pluck('id')->all(),
            SellableGrantRule::query()
                ->where('sellable_entity_id', $sellable->id)
                ->orderBy('sort_order')
                ->pluck('atom_id')
                ->all()
        );
        $this->assertSame(
            [UserEntitlementGrant::ATOM_LESSON, UserEntitlementGrant::ATOM_LESSON],
            SellableGrantRule::query()
                ->where('sellable_entity_id', $sellable->id)
                ->orderBy('sort_order')
                ->pluck('atom_kind')
                ->all()
        );
    }

    public function test_seeder_is_idempotent_for_managed_course_sellables(): void
    {
        $this->createCourseWithLessons();

        $this->seed(CatalogSellableSeeder::class);
        $sellableId = SellableEntity::query()->sole()->id;
        $grantRuleIds = SellableGrantRule::query()->orderBy('id')->pluck('id')->all();

        $this->seed(CatalogSellableSeeder::class);

        $this->assertDatabaseCount('sellable_entities', 1);
        $this->assertSame($sellableId, SellableEntity::query()->sole()->id);
        $this->assertSame(
            $grantRuleIds,
            SellableGrantRule::query()->orderBy('id')->pluck('id')->all()
        );
    }

    public function test_seeder_updates_managed_sellable_and_versions_changed_grant_rules(): void
    {
        [$course, $lessons] = $this->createCourseWithLessons(priceCents: 180000);
        $sellable = SellableEntity::query()->create([
            'sellable_kind' => OrderItem::KIND_COURSE,
            'entity_type' => OrderItem::KIND_COURSE,
            'entity_id' => $course->id,
            'sku' => $course->itemId(),
            'title' => 'Устаревшее название',
            'currency' => 'RUB',
            'price_cents' => 1,
            'price_version' => 1,
            'status' => SellableEntity::STATUS_ACTIVE,
            'metadata' => ['managed_by' => 'catalog_sellable_seeder'],
        ]);
        SellableGrantRule::query()->create([
            'sellable_entity_id' => $sellable->id,
            'atom_kind' => UserEntitlementGrant::ATOM_GUIDE,
            'atom_id' => 999,
            'grant_snapshot_version' => 1,
            'sort_order' => 1,
        ]);

        $this->seed(CatalogSellableSeeder::class);

        $sellable->refresh();
        $this->assertSame($course->title, $sellable->title);
        $this->assertSame(180000, $sellable->price_cents);
        $this->assertSame(2, $sellable->price_version);
        $this->assertSame(2, (int) $sellable->grantRules()->max('grant_snapshot_version'));
        $this->assertSame(
            $lessons->pluck('id')->all(),
            $sellable->grantRules()
                ->where('grant_snapshot_version', 2)
                ->orderBy('sort_order')
                ->pluck('atom_id')
                ->all()
        );
        $this->assertTrue($sellable->grantRules()
            ->where('grant_snapshot_version', 1)
            ->where('atom_kind', UserEntitlementGrant::ATOM_GUIDE)
            ->exists());
    }

    public function test_seeder_bumps_price_version_without_changing_grant_version_for_price_only_change(): void
    {
        [$course] = $this->createCourseWithLessons(priceCents: 150000);

        $this->seed(CatalogSellableSeeder::class);
        $sellable = SellableEntity::query()->where('sku', $course->itemId())->firstOrFail();
        $grantRuleIds = $sellable->grantRules()->orderBy('id')->pluck('id')->all();

        $course->forceFill(['price_cents' => 210000])->save();
        $this->seed(CatalogSellableSeeder::class);

        $sellable->refresh();
        $this->assertSame(210000, $sellable->price_cents);
        $this->assertSame(2, $sellable->price_version);
        $this->assertSame(1, (int) $sellable->grantRules()->max('grant_snapshot_version'));
        $this->assertSame($grantRuleIds, $sellable->grantRules()->orderBy('id')->pluck('id')->all());
    }

    public function test_seeder_versions_grant_rules_without_bumping_price_version_for_lesson_set_change(): void
    {
        [$course] = $this->createCourseWithLessons(priceCents: 150000);

        $this->seed(CatalogSellableSeeder::class);
        $sellable = SellableEntity::query()->where('sku', $course->itemId())->firstOrFail();

        $thirdLesson = Lesson::query()->create([
            'course_id' => $course->id,
            'slug' => 'new-lesson',
            'title' => 'Новый урок',
            'sort_order' => 3,
            'is_active' => true,
        ]);

        $this->seed(CatalogSellableSeeder::class);

        $sellable->refresh();
        $this->assertSame(1, $sellable->price_version);
        $this->assertSame(2, (int) $sellable->grantRules()->max('grant_snapshot_version'));
        $this->assertSame(
            [$course->lessons()->where('slug', 'vvedenie')->value('id'), $course->lessons()->where('slug', 'kosti')->value('id'), $thirdLesson->id],
            $sellable->grantRules()
                ->where('grant_snapshot_version', 2)
                ->orderBy('sort_order')
                ->pluck('atom_id')
                ->all()
        );
    }

    public function test_seeder_preserves_manual_draft_and_archived_sellable_data(): void
    {
        [$course] = $this->createCourseWithLessons(priceCents: 150000);
        $manual = SellableEntity::query()->create([
            'sellable_kind' => OrderItem::KIND_COURSE,
            'entity_type' => OrderItem::KIND_COURSE,
            'entity_id' => $course->id,
            'sku' => $course->itemId(),
            'title' => 'Ручной sellable',
            'currency' => 'RUB',
            'price_cents' => 999999,
            'price_version' => 7,
            'status' => SellableEntity::STATUS_ACTIVE,
            'metadata' => ['managed_by' => 'manual'],
        ]);
        $draft = SellableEntity::query()->create([
            'sellable_kind' => OrderItem::KIND_COURSE,
            'entity_type' => OrderItem::KIND_COURSE,
            'entity_id' => $course->id,
            'sku' => 'draft:'.$course->itemId(),
            'title' => 'Черновик',
            'currency' => 'RUB',
            'price_cents' => 100,
            'price_version' => 1,
            'status' => SellableEntity::STATUS_DRAFT,
        ]);
        $archived = SellableEntity::query()->create([
            'sellable_kind' => OrderItem::KIND_COURSE,
            'entity_type' => OrderItem::KIND_COURSE,
            'entity_id' => $course->id,
            'sku' => 'archived:'.$course->itemId(),
            'title' => 'Архив',
            'currency' => 'RUB',
            'price_cents' => 100,
            'price_version' => 1,
            'status' => SellableEntity::STATUS_ARCHIVED,
        ]);

        $this->seed(CatalogSellableSeeder::class);

        $manual->refresh();
        $draft->refresh();
        $archived->refresh();

        $this->assertSame('Ручной sellable', $manual->title);
        $this->assertSame(999999, $manual->price_cents);
        $this->assertSame(7, $manual->price_version);
        $this->assertSame(SellableEntity::STATUS_ACTIVE, $manual->status);
        $this->assertSame(SellableEntity::STATUS_DRAFT, $draft->status);
        $this->assertSame(SellableEntity::STATUS_ARCHIVED, $archived->status);
        $this->assertDatabaseCount('sellable_entities', 3);
    }

    public function test_seeder_does_not_publish_active_sellable_for_course_without_active_lessons(): void
    {
        $subject = Subject::query()->create([
            'slug' => 'anatomiya',
            'title' => 'Анатомия',
            'sort_order' => 1,
            'is_active' => true,
        ]);
        $course = Course::query()->create([
            'subject_id' => $subject->id,
            'slug' => 'empty-course',
            'title' => 'Пустой курс',
            'price_cents' => 150000,
            'sort_order' => 1,
            'is_active' => true,
        ]);
        Lesson::query()->create([
            'course_id' => $course->id,
            'slug' => 'inactive-lesson',
            'title' => 'Архивный урок',
            'sort_order' => 1,
            'is_active' => false,
        ]);
        $sellable = SellableEntity::query()->create([
            'sellable_kind' => OrderItem::KIND_COURSE,
            'entity_type' => OrderItem::KIND_COURSE,
            'entity_id' => $course->id,
            'sku' => $course->load('subject')->itemId(),
            'title' => $course->title,
            'currency' => 'RUB',
            'price_cents' => 150000,
            'price_version' => 1,
            'status' => SellableEntity::STATUS_ACTIVE,
            'metadata' => ['managed_by' => 'catalog_sellable_seeder'],
        ]);

        $this->seed(CatalogSellableSeeder::class);

        $sellable->refresh();
        $this->assertSame(SellableEntity::STATUS_ARCHIVED, $sellable->status);
        $this->assertSame('course_not_grantable', $sellable->metadata['archived_reason']);
    }

    public function test_catalog_returns_sellable_sku_after_seeding(): void
    {
        $this->createCourseWithLessons();

        $this->seed(CatalogSellableSeeder::class);

        $this->getJson('/api/v1/catalog/subjects')
            ->assertOk()
            ->assertJsonPath('data.0.courses.0.sellable.sku', 'course:anatomiya:osteologiya')
            ->assertJsonPath('data.0.courses.0.sellable.price', 150000)
            ->assertJsonPath('data.0.courses.0.sellable.currency', 'RUB');
    }

    public function test_seeded_sellable_can_create_draft_order_from_sellable_sku(): void
    {
        [$course] = $this->createCourseWithLessons(priceCents: 190000);
        $user = User::factory()->create(['role' => 'user_no_purchase']);

        $this->seed(CatalogSellableSeeder::class);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/payments/orders', [
            'sellable_sku' => $course->itemId(),
        ])
            ->assertCreated()
            ->assertJsonPath('payment_status', 'draft')
            ->assertJsonPath('access_status', 'not_granted');

        $order = Order::query()->with('items')->findOrFail((int) $response->json('order_id'));
        $item = $order->items->sole();

        $this->assertSame($course->id, $order->course_id);
        $this->assertSame(190000, $order->amount_final);
        $this->assertSame($course->itemId(), $item->sellable_sku);
        $this->assertSame(2, count($item->grant_snapshot['atoms']));
        $this->assertSame(0, UserEntitlementGrant::query()->count());
    }

    /**
     * @return array{0: Course, 1: Collection<int, Lesson>}
     */
    private function createCourseWithLessons(int $priceCents = 150000): array
    {
        $subject = Subject::query()->create([
            'slug' => 'anatomiya',
            'title' => 'Анатомия',
            'sort_order' => 1,
            'is_active' => true,
        ]);
        $course = Course::query()->create([
            'subject_id' => $subject->id,
            'slug' => 'osteologiya',
            'title' => 'Остеология',
            'short_title' => 'Остеология',
            'price_cents' => $priceCents,
            'sort_order' => 1,
            'is_active' => true,
        ]);
        $firstLesson = Lesson::query()->create([
            'course_id' => $course->id,
            'slug' => 'vvedenie',
            'title' => 'Введение',
            'sort_order' => 1,
            'is_active' => true,
        ]);
        $secondLesson = Lesson::query()->create([
            'course_id' => $course->id,
            'slug' => 'kosti',
            'title' => 'Кости',
            'sort_order' => 2,
            'is_active' => true,
        ]);
        Lesson::query()->create([
            'course_id' => $course->id,
            'slug' => 'archive',
            'title' => 'Архив',
            'sort_order' => 3,
            'is_active' => false,
        ]);

        return [$course->load('subject'), collect([$firstLesson, $secondLesson])];
    }
}
