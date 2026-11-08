<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\LegacyImportMapping;
use App\Models\Lesson;
use App\Models\LessonLearningContent;
use App\Models\Subject;
use App\Services\Learning\LegacyLessonBodyImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LegacyLessonBodyImporterTest extends TestCase
{
    use RefreshDatabase;

    public function test_importer_creates_draft_learning_content_for_unambiguous_platform_page(): void
    {
        $lesson = $this->createLessonWithLegacyPage(1063);

        $mapping = $this->importer()->importPlatformPage([
            'id' => 1063,
            'content' => '<p>Первый абзац.</p><p>Второй абзац.</p>',
        ]);

        $content = LessonLearningContent::query()->firstOrFail();

        $this->assertSame(LegacyImportMapping::MAPPING_MAPPED, $mapping->mapping_status);
        $this->assertSame(LegacyImportMapping::VALIDATION_PASSED, $mapping->validation_status);
        $this->assertSame(LegacyLessonBodyImporter::TARGET_DOMAIN, $mapping->target_domain);
        $this->assertSame(LegacyLessonBodyImporter::TARGET_TYPE, $mapping->target_type);
        $this->assertSame($content->id, $mapping->target_id);
        $this->assertSame($lesson->id, $content->lesson_id);
        $this->assertSame(LessonLearningContent::STATUS_DRAFT, $content->status);
        $this->assertSame(LessonLearningContent::FORMAT_LESSON_BLOCKS_V1, $content->format);
        $this->assertSame('Первый абзац.', data_get($content->body, 'blocks.0.text'));
        $this->assertSame('Второй абзац.', data_get($content->body, 'blocks.1.text'));
        $this->assertSame(LessonLearningContent::IMPORT_STATUS_IMPORTED, $content->import_status);
        $this->assertNull($content->published_at);
    }

    public function test_importer_reuses_mapping_and_content_on_repeated_run(): void
    {
        $this->createLessonWithLegacyPage(1064);
        $importer = $this->importer();

        $firstMapping = $importer->importPlatformPage([
            'id' => 1064,
            'content' => '<p>Первичная версия.</p>',
        ]);
        $firstContentId = LessonLearningContent::query()->value('id');

        $secondMapping = $importer->importPlatformPage([
            'id' => 1064,
            'content' => '<p>Обновленная версия.</p>',
        ]);

        $this->assertSame($firstMapping->id, $secondMapping->id);
        $this->assertSame($firstContentId, LessonLearningContent::query()->value('id'));
        $this->assertSame('Обновленная версия.', data_get(LessonLearningContent::query()->firstOrFail()->body, 'blocks.0.text'));
        $this->assertSame(1, LegacyImportMapping::query()->count());
        $this->assertSame(1, LessonLearningContent::query()->count());
    }

    public function test_importer_marks_repeated_legacy_page_id_for_manual_review_without_content(): void
    {
        $this->createLessonWithLegacyPage(1065, courseSlug: 'course-a');
        $this->createLessonWithLegacyPage(1065, courseSlug: 'course-b');

        $mapping = $this->importer()->importPlatformPage([
            'id' => 1065,
            'content' => '<p>Неоднозначный материал.</p>',
        ]);

        $this->assertSame(LegacyImportMapping::MAPPING_BLOCKED, $mapping->mapping_status);
        $this->assertSame(LegacyImportMapping::VALIDATION_MANUAL_REVIEW, $mapping->validation_status);
        $this->assertSame('ambiguous_legacy_page_id', $mapping->manual_review_reason);
        $this->assertNull($mapping->target_id);
        $this->assertSame(0, LessonLearningContent::query()->count());
    }

    public function test_importer_does_not_overwrite_manual_content(): void
    {
        $lesson = $this->createLessonWithLegacyPage(1066);
        $manualContent = LessonLearningContent::query()->create([
            'lesson_id' => $lesson->id,
            'status' => LessonLearningContent::STATUS_PUBLISHED,
            'format' => LessonLearningContent::FORMAT_LESSON_BLOCKS_V1,
            'version' => 1,
            'body' => [
                'blocks' => [
                    [
                        'type' => 'paragraph',
                        'text' => 'Ручной текст.',
                    ],
                ],
                'toc' => [],
            ],
            'published_at' => now(),
            'import_status' => LessonLearningContent::IMPORT_STATUS_MANUAL,
        ]);

        $mapping = $this->importer()->importPlatformPage([
            'id' => 1066,
            'content' => '<p>Legacy текст не должен перетереть ручной.</p>',
        ]);

        $this->assertSame(LegacyImportMapping::MAPPING_BLOCKED, $mapping->mapping_status);
        $this->assertSame(LegacyImportMapping::VALIDATION_MANUAL_REVIEW, $mapping->validation_status);
        $this->assertSame('published_content_exists', $mapping->manual_review_reason);
        $this->assertSame($manualContent->id, $mapping->target_id);
        $this->assertSame('Ручной текст.', data_get($manualContent->refresh()->body, 'blocks.0.text'));
    }

    public function test_importer_structures_supported_file_and_video_shortcodes_as_draft_internal_references(): void
    {
        $lesson = $this->createLessonWithLegacyPage(1067);

        $mapping = $this->importer()->importPlatformPage([
            'id' => 1067,
            'content' => '<p>Видео: {video::55}</p><p>Файл {file::99}</p>',
        ]);
        $content = $lesson->learningContent()->firstOrFail();

        $this->assertSame(LegacyImportMapping::MAPPING_MAPPED, $mapping->mapping_status);
        $this->assertSame(LegacyImportMapping::VALIDATION_PASSED, $mapping->validation_status);
        $this->assertSame($content->id, $mapping->target_id);
        $this->assertSame('paragraph', data_get($content->body, 'blocks.0.type'));
        $this->assertSame('Видео:', data_get($content->body, 'blocks.0.text'));
        $this->assertSame('legacy_protected_asset_reference', data_get($content->body, 'blocks.1.type'));
        $this->assertSame('legacy_video', data_get($content->body, 'blocks.1.assetKind'));
        $this->assertSame(55, data_get($content->body, 'blocks.1.legacyAssetId'));
        $this->assertSame('paragraph', data_get($content->body, 'blocks.2.type'));
        $this->assertSame('Файл', data_get($content->body, 'blocks.2.text'));
        $this->assertSame('legacy_protected_asset_reference', data_get($content->body, 'blocks.3.type'));
        $this->assertSame('legacy_file', data_get($content->body, 'blocks.3.assetKind'));
        $this->assertSame(99, data_get($content->body, 'blocks.3.legacyAssetId'));
        $this->assertStringNotContainsString('{video::55}', json_encode($content->body, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('{file::99}', json_encode($content->body, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    public function test_importer_keeps_malformed_legacy_shortcodes_in_manual_review(): void
    {
        $this->createLessonWithLegacyPage(1069);

        $mapping = $this->importer()->importPlatformPage([
            'id' => 1069,
            'content' => '<p>Файл {file::abc}</p>',
        ]);

        $this->assertSame(LegacyImportMapping::MAPPING_BLOCKED, $mapping->mapping_status);
        $this->assertSame(LegacyImportMapping::VALIDATION_MANUAL_REVIEW, $mapping->validation_status);
        $this->assertSame('unsupported_legacy_shortcode', $mapping->manual_review_reason);
        $this->assertSame(0, LessonLearningContent::query()->count());
    }

    public function test_importer_keeps_spaced_legacy_shortcodes_in_manual_review(): void
    {
        $this->createLessonWithLegacyPage(1070);

        $mapping = $this->importer()->importPlatformPage([
            'id' => 1070,
            'content' => '<p>Файл { file::99 }</p>',
        ]);

        $this->assertSame(LegacyImportMapping::MAPPING_BLOCKED, $mapping->mapping_status);
        $this->assertSame(LegacyImportMapping::VALIDATION_MANUAL_REVIEW, $mapping->validation_status);
        $this->assertSame('unsupported_legacy_shortcode', $mapping->manual_review_reason);
        $this->assertSame(0, LessonLearningContent::query()->count());
    }

    public function test_importer_ignores_empty_legacy_file_placeholder(): void
    {
        $lesson = $this->createLessonWithLegacyPage(1071);

        $mapping = $this->importer()->importPlatformPage([
            'id' => 1071,
            'content' => '<p>Видео: {video::55}</p><p>{file::-}</p><p>Файл: {file::99}</p>',
        ]);

        $content = $lesson->learningContent()->firstOrFail();

        $this->assertSame(LegacyImportMapping::MAPPING_MAPPED, $mapping->mapping_status);
        $this->assertSame(
            ['paragraph', 'legacy_protected_asset_reference', 'paragraph', 'legacy_protected_asset_reference'],
            collect($content->body['blocks'])->pluck('type')->all(),
        );
        $this->assertStringNotContainsString(
            '{file::-}',
            json_encode($content->body, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        );
    }

    public function test_importer_scrubs_malformed_utf8_from_legacy_body(): void
    {
        $lesson = $this->createLessonWithLegacyPage(1072);

        $mapping = $this->importer()->importPlatformPage([
            'id' => 1072,
            'content' => "<p>Поврежденный хвост \xD1</p>",
        ]);
        $content = $lesson->learningContent()->firstOrFail();

        $this->assertSame(LegacyImportMapping::MAPPING_MAPPED, $mapping->mapping_status);
        $this->assertSame('Поврежденный хвост ?', data_get($content->body, 'blocks.0.text'));
        $this->assertJson(json_encode($content->body, JSON_THROW_ON_ERROR));
    }

    public function test_importer_applies_product_aware_rows_with_same_platform_page_id(): void
    {
        $lessonA = $this->createLessonWithLegacyPage(1200, courseSlug: 'product-a', legacyProductId: 7001);
        $lessonB = $this->createLessonWithLegacyPage(1200, courseSlug: 'product-b', legacyProductId: 7002);
        $importer = $this->importer();

        $mappingA = $importer->importPlatformPage([
            'id' => 1068,
            'product_id' => 7001,
            'content' => '<p>Текст первого продукта.</p>',
        ]);
        $mappingB = $importer->importPlatformPage([
            'id' => 1068,
            'product_id' => 7002,
            'content' => '<p>Текст второго продукта.</p>',
        ]);

        $this->assertNotSame($mappingA->id, $mappingB->id);
        $this->assertSame(LegacyImportMapping::SOURCE_SCOPE_LEGACY_PRODUCT, $mappingA->source_scope_type);
        $this->assertSame('7001', $mappingA->source_scope_id);
        $this->assertSame('7002', $mappingB->source_scope_id);
        $this->assertSame(2, LegacyImportMapping::query()->count());
        $this->assertSame(2, LessonLearningContent::query()->count());
        $this->assertSame('Текст первого продукта.', data_get($lessonA->learningContent()->firstOrFail()->body, 'blocks.0.text'));
        $this->assertSame('Текст второго продукта.', data_get($lessonB->learningContent()->firstOrFail()->body, 'blocks.0.text'));
        $this->assertSame(7001, data_get($lessonA->learningContent()->firstOrFail()->metadata, 'legacy_product_id'));

        $repeatMapping = $importer->importPlatformPage([
            'id' => 1068,
            'product_id' => 7001,
            'content' => '<p>Обновленный текст первого продукта.</p>',
        ]);

        $this->assertSame($mappingA->id, $repeatMapping->id);
        $this->assertSame(2, LegacyImportMapping::query()->count());
        $this->assertSame(2, LessonLearningContent::query()->count());
        $this->assertSame('Обновленный текст первого продукта.', data_get($lessonA->learningContent()->firstOrFail()->body, 'blocks.0.text'));
    }

    public function test_importer_ignores_inactive_stale_lessons_when_matching_by_product_id(): void
    {
        $activeLesson = $this->createLessonWithLegacyPage(1300, courseSlug: 'active-product', legacyProductId: 8001);
        $inactiveLesson = $this->createLessonWithLegacyPage(1301, courseSlug: 'inactive-product', legacyProductId: 8001);
        $inactiveLesson->forceFill(['is_active' => false])->save();
        $inactiveLesson->course->forceFill(['is_active' => false])->save();
        $inactiveLesson->course->subject->forceFill(['is_active' => false])->save();

        $mapping = $this->importer()->importPlatformPage([
            'id' => 1300,
            'product_id' => 8001,
            'content' => '<p>Текст активного урока.</p>',
        ]);

        $this->assertSame(LegacyImportMapping::MAPPING_MAPPED, $mapping->mapping_status);
        $this->assertSame($activeLesson->learningContent()->firstOrFail()->id, $mapping->target_id);
        $this->assertSame(1, LessonLearningContent::query()->count());
        $this->assertNull($inactiveLesson->learningContent()->first());
    }

    public function test_importer_can_match_inactive_lesson_when_explicitly_allowed(): void
    {
        $lesson = $this->createLessonWithLegacyPage(1302, courseSlug: 'inactive-product-backfill', legacyProductId: 8002);
        $lesson->forceFill(['is_active' => false])->save();
        $lesson->course->forceFill(['is_active' => false])->save();

        $mapping = $this->importer()->importPlatformPage([
            'id' => 1302,
            'product_id' => 8002,
            'content' => '<p>Текст backfill-урока.</p>',
        ], includeInactive: true);

        $this->assertSame(LegacyImportMapping::MAPPING_MAPPED, $mapping->mapping_status);
        $this->assertSame($lesson->learningContent()->firstOrFail()->id, $mapping->target_id);
        $this->assertSame('Текст backfill-урока.', data_get($lesson->learningContent()->firstOrFail()->body, 'blocks.0.text'));
    }

    private function importer(): LegacyLessonBodyImporter
    {
        return app(LegacyLessonBodyImporter::class);
    }

    private function createLessonWithLegacyPage(int $legacyPageId, string $courseSlug = 'course', ?int $legacyProductId = null): Lesson
    {
        $subject = Subject::query()->create([
            'slug' => 'subject-'.$courseSlug,
            'title' => 'Subject '.$courseSlug,
            'is_active' => true,
        ]);

        $course = Course::query()->create([
            'subject_id' => $subject->id,
            'slug' => $courseSlug,
            'title' => 'Course '.$courseSlug,
            'price_cents' => 100000,
            'is_active' => true,
        ]);

        return Lesson::query()->create([
            'course_id' => $course->id,
            'slug' => 'lesson-'.$courseSlug,
            'title' => 'Lesson '.$courseSlug,
            'sort_order' => 1,
            'is_active' => true,
            'legacy_product_id' => $legacyProductId,
            'metadata' => [
                'legacy_page_id' => $legacyPageId,
            ],
        ]);
    }
}
