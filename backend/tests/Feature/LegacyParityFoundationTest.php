<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\LegacyImportMapping;
use App\Models\LegacyProductMapping;
use App\Models\LegacyRouteMapping;
use App\Models\Lesson;
use App\Models\OrderItem;
use App\Models\SellableEntity;
use App\Models\SellableGrantRule;
use App\Models\Subject;
use App\Models\UserEntitlementGrant;
use App\Services\GrantPreviewService;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class LegacyParityFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_route_and_import_manifest_contracts_are_persisted(): void
    {
        $route = LegacyRouteMapping::query()->create([
            'legacy_path' => '/platform/course/anatomy/lesson-1',
            'target_path' => '/lk/learning/anatomy/lesson-1',
            'source_surface' => LegacyRouteMapping::SURFACE_PLATFORM,
            'route_kind' => LegacyRouteMapping::KIND_LEARNING,
            'action' => LegacyRouteMapping::ACTION_DIRECT,
            'parity_status' => LegacyRouteMapping::STATUS_MAPPED,
            'requires_auth' => true,
            'metadata' => ['legacy_page_type' => 'lesson'],
        ]);
        $mapping = LegacyImportMapping::query()->create([
            'batch_key' => 'legacy-audit-fixture',
            'source_table' => 'products',
            'source_id' => '101',
            'target_domain' => 'catalog',
            'target_type' => 'sellable_entity',
            'target_id' => 44,
            'mapping_status' => LegacyImportMapping::MAPPING_MAPPED,
            'validation_status' => LegacyImportMapping::VALIDATION_PASSED,
            'idempotency_key' => 'products:101:catalog',
            'source_fingerprint_hash' => hash('sha256', 'products:101:v1'),
            'metadata' => ['legacy_url' => '/buy/101'],
        ]);

        $this->assertTrue($route->requires_auth);
        $this->assertSame(['legacy_page_type' => 'lesson'], $route->metadata);
        $this->assertSame('sellable_entity', $mapping->target_type);
        $this->assertDatabaseHas('legacy_import_mappings', [
            'source_table' => 'products',
            'source_id' => '101',
            'target_domain' => 'catalog',
            'idempotency_key' => 'products:101:catalog',
        ]);
    }

    public function test_import_mapping_idempotency_key_is_unique(): void
    {
        LegacyImportMapping::query()->create($this->importMappingAttributes(
            sourceId: '101',
            idempotencyKey: 'products:101:catalog'
        ));

        $this->expectException(QueryException::class);

        LegacyImportMapping::query()->create($this->importMappingAttributes(
            sourceId: '102',
            idempotencyKey: 'products:101:catalog'
        ));
    }

    public function test_import_mapping_source_scope_preserves_id_only_unique_identity(): void
    {
        $mapping = LegacyImportMapping::query()->create($this->importMappingAttributes(
            sourceId: '201',
            idempotencyKey: 'products:201:catalog'
        ));

        $this->assertSame(LegacyImportMapping::SOURCE_SCOPE_ID_ONLY, $mapping->refresh()->source_scope_type);
        $this->assertSame(LegacyImportMapping::SOURCE_SCOPE_ID_ONLY, $mapping->source_scope_id);

        $this->expectException(QueryException::class);

        LegacyImportMapping::query()->create($this->importMappingAttributes(
            sourceId: '201',
            idempotencyKey: 'products:201:catalog:second'
        ));
    }

    public function test_import_mapping_source_scope_allows_same_source_for_different_legacy_products(): void
    {
        LegacyImportMapping::query()->create([
            ...$this->importMappingAttributes(sourceId: '301', idempotencyKey: 'platform_page:301:product:9001'),
            'source_table' => 'platform_page',
            'target_domain' => 'learning_lesson_body',
            'source_scope_type' => LegacyImportMapping::SOURCE_SCOPE_LEGACY_PRODUCT,
            'source_scope_id' => '9001',
        ]);
        LegacyImportMapping::query()->create([
            ...$this->importMappingAttributes(sourceId: '301', idempotencyKey: 'platform_page:301:product:9002'),
            'source_table' => 'platform_page',
            'target_domain' => 'learning_lesson_body',
            'source_scope_type' => LegacyImportMapping::SOURCE_SCOPE_LEGACY_PRODUCT,
            'source_scope_id' => '9002',
        ]);

        $this->assertSame(2, LegacyImportMapping::query()->count());

        $this->expectException(QueryException::class);

        LegacyImportMapping::query()->create([
            ...$this->importMappingAttributes(sourceId: '301', idempotencyKey: 'platform_page:301:product:9001:duplicate'),
            'source_table' => 'platform_page',
            'target_domain' => 'learning_lesson_body',
            'source_scope_type' => LegacyImportMapping::SOURCE_SCOPE_LEGACY_PRODUCT,
            'source_scope_id' => '9001',
        ]);
    }

    public function test_import_mapping_source_scope_allows_id_only_and_product_rows_for_same_source(): void
    {
        LegacyImportMapping::query()->create([
            ...$this->importMappingAttributes(sourceId: '401', idempotencyKey: 'platform_page:401'),
            'source_table' => 'platform_page',
            'target_domain' => 'learning_lesson_body',
        ]);
        LegacyImportMapping::query()->create([
            ...$this->importMappingAttributes(sourceId: '401', idempotencyKey: 'platform_page:401:product:9101'),
            'source_table' => 'platform_page',
            'target_domain' => 'learning_lesson_body',
            'source_scope_type' => LegacyImportMapping::SOURCE_SCOPE_LEGACY_PRODUCT,
            'source_scope_id' => '9101',
        ]);

        $this->assertSame(2, LegacyImportMapping::query()->count());
        $this->assertSame(
            [
                LegacyImportMapping::SOURCE_SCOPE_ID_ONLY,
                LegacyImportMapping::SOURCE_SCOPE_LEGACY_PRODUCT,
            ],
            LegacyImportMapping::query()->orderBy('id')->pluck('source_scope_type')->all(),
        );
    }

    public function test_sellable_mapping_and_grant_preview_use_atomic_entitlements(): void
    {
        [, $course, $lessons] = $this->createCourseWithLessons(lessonCount: 2);
        $sellable = SellableEntity::query()->create([
            'sellable_kind' => OrderItem::KIND_COURSE,
            'entity_type' => OrderItem::KIND_COURSE,
            'entity_id' => $course->id,
            'sku' => $course->itemId(),
            'title' => $course->title,
            'currency' => 'RUB',
            'price_cents' => 150000,
            'price_version' => 1,
            'status' => SellableEntity::STATUS_ACTIVE,
        ]);
        LegacyProductMapping::query()->create([
            'legacy_product_id' => 7001,
            'legacy_buyaction_id' => 9001,
            'sellable_entity_id' => $sellable->id,
            'mapping_status' => LegacyProductMapping::STATUS_MAPPED,
            'legacy_payload_hash' => hash('sha256', 'legacy-product-7001'),
            'grant_snapshot_version' => 1,
        ]);
        SellableGrantRule::query()->create([
            'sellable_entity_id' => $sellable->id,
            'atom_kind' => UserEntitlementGrant::ATOM_LESSON,
            'atom_id' => $lessons[1]->id,
            'grant_snapshot_version' => 1,
            'sort_order' => 2,
        ]);
        SellableGrantRule::query()->create([
            'sellable_entity_id' => $sellable->id,
            'atom_kind' => UserEntitlementGrant::ATOM_LESSON,
            'atom_id' => $lessons[0]->id,
            'grant_snapshot_version' => 1,
            'sort_order' => 1,
        ]);

        $preview = app(GrantPreviewService::class)->preview($sellable);

        $this->assertSame(1, $preview['version']);
        $this->assertSame(OrderItem::KIND_COURSE, $preview['sellable_kind']);
        $this->assertSame($course->id, $preview['entity_id']);
        $this->assertSame([
            ['kind' => UserEntitlementGrant::ATOM_LESSON, 'id' => $lessons[0]->id],
            ['kind' => UserEntitlementGrant::ATOM_LESSON, 'id' => $lessons[1]->id],
        ], $preview['atoms']);
        $this->assertCount(1, $sellable->legacyProductMappings);
    }

    public function test_grant_preview_rejects_sellable_without_rules(): void
    {
        $sellable = SellableEntity::query()->create([
            'sellable_kind' => OrderItem::KIND_LESSON,
            'entity_type' => OrderItem::KIND_LESSON,
            'entity_id' => 10,
            'sku' => 'lesson:empty',
            'title' => 'Empty lesson',
            'currency' => 'RUB',
            'price_cents' => 10000,
            'price_version' => 1,
            'status' => SellableEntity::STATUS_ACTIVE,
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('SELLABLE_HAS_NO_GRANT_RULES');

        app(GrantPreviewService::class)->preview($sellable);
    }

    /**
     * @return array<string, mixed>
     */
    private function importMappingAttributes(string $sourceId, string $idempotencyKey): array
    {
        return [
            'batch_key' => 'legacy-audit-fixture',
            'source_table' => 'products',
            'source_id' => $sourceId,
            'target_domain' => 'catalog',
            'target_type' => 'sellable_entity',
            'target_id' => (int) $sourceId,
            'mapping_status' => LegacyImportMapping::MAPPING_PENDING,
            'validation_status' => LegacyImportMapping::VALIDATION_PENDING,
            'idempotency_key' => $idempotencyKey,
            'source_fingerprint_hash' => hash('sha256', "products:{$sourceId}:v1"),
        ];
    }

    /**
     * @return array{0: Subject, 1: Course, 2: array<int, Lesson>}
     */
    private function createCourseWithLessons(int $lessonCount): array
    {
        $subject = Subject::query()->create([
            'slug' => 'subject-'.Str::uuid(),
            'title' => 'Анатомия',
            'sort_order' => 1,
            'is_active' => true,
        ]);
        $course = Course::query()->create([
            'subject_id' => $subject->id,
            'slug' => 'course-'.Str::uuid(),
            'title' => 'Остеология',
            'short_title' => 'Остеология',
            'price_cents' => 150000,
            'sort_order' => 1,
            'is_active' => true,
        ]);
        $lessons = [];
        for ($i = 1; $i <= $lessonCount; $i++) {
            $lessons[] = Lesson::query()->create([
                'course_id' => $course->id,
                'slug' => 'lesson-'.$i.'-'.Str::uuid(),
                'title' => 'Тема '.$i,
                'sort_order' => $i,
                'is_active' => true,
            ]);
        }

        return [$subject, $course, $lessons];
    }
}
