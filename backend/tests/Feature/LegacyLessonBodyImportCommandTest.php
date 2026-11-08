<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\LegacyImportMapping;
use App\Models\Lesson;
use App\Models\LessonLearningContent;
use App\Models\Subject;
use App\Models\User;
use App\Models\UserEntitlementGrant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LegacyLessonBodyImportCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_reports_without_database_writes(): void
    {
        $this->createLessonWithLegacyPage(201);
        $this->createLessonWithLegacyPage(202, courseSlug: 'ambiguous-a');
        $this->createLessonWithLegacyPage(202, courseSlug: 'ambiguous-b');

        $sourcePath = $this->writeSourceJson([
            [
                'id' => 201,
                'content' => '<p>Чистый текст.</p>',
            ],
            [
                'id' => 202,
                'content' => '<p>Повторяющийся legacy_page_id.</p>',
            ],
        ]);
        $reportPath = storage_path('app/legacy-lesson-body-dry-run-report.json');

        $this->artisan('legacy:learning:import-bodies', [
            '--source-json' => $sourcePath,
            '--report' => $reportPath,
        ])
            ->expectsOutputToContain('Dry-run completed: total=2 import_draft=1 manual_review=1 written_mappings=0')
            ->assertSuccessful();

        $report = $this->readReport($reportPath);

        $this->assertSame('dry-run', $report['mode']);
        $this->assertSame(2, $report['stats']['source_rows']);
        $this->assertSame(2, $report['stats']['source_unique_rows']);
        $this->assertSame(0, $report['stats']['source_duplicates_skipped']);
        $this->assertSame(2, $report['stats']['total']);
        $this->assertSame(1, $report['stats']['import_draft']);
        $this->assertSame(1, $report['stats']['manual_review']);
        $this->assertSame(0, $report['stats']['written_mappings']);
        $this->assertSame(0, $report['stats']['stale_imported_drafts']);
        $this->assertSame(0, $report['stats']['protected_asset_reference_rows']);
        $this->assertSame(0, $report['stats']['protected_asset_reference_blocks']);
        $this->assertSame(['legacy_file' => 0, 'legacy_video' => 0], $report['stats']['protected_asset_reference_rows_by_kind']);
        $this->assertSame(['legacy_file' => 0, 'legacy_video' => 0], $report['stats']['protected_asset_reference_blocks_by_kind']);
        $this->assertSame(['ambiguous_legacy_page_id' => 1], $report['stats']['manual_review_reasons']);
        $this->assertSame('import_draft', $report['items'][0]['action']);
        $this->assertSame('manual_review', $report['items'][1]['action']);
        $this->assertArrayNotHasKey('protected_asset_references_count', $report['items'][0]);
        $this->assertArrayNotHasKey('legacy_file_references_count', $report['items'][0]);
        $this->assertArrayNotHasKey('legacy_video_references_count', $report['items'][0]);
        $this->assertFalse($report['items'][0]['stale_imported_draft']);
        $this->assertFalse($report['items'][1]['stale_imported_draft']);
        $this->assertSame('ambiguous_legacy_page_id', $report['items'][1]['manual_review_reason']);
        $this->assertArrayNotHasKey('source_path', $report);
        $this->assertSame(basename($sourcePath), $report['source_file']);
        $this->assertReportDoesNotLeakLegacyPayload($report, [
            'Чистый текст',
            'Повторяющийся legacy_page_id',
            $sourcePath,
        ]);
        $this->assertSame(0, LegacyImportMapping::query()->count());
        $this->assertSame(0, LessonLearningContent::query()->count());
    }

    public function test_apply_imports_unambiguous_rows_and_is_idempotent(): void
    {
        $lesson = $this->createLessonWithLegacyPage(203);
        $sourcePath = $this->writeSourceJson([
            [
                'id' => 203,
                'content' => '<p>Первая версия.</p>',
            ],
        ]);
        $reportPath = storage_path('app/legacy-lesson-body-apply-report.json');

        $this->artisan('legacy:learning:import-bodies', [
            '--source-json' => $sourcePath,
            '--report' => $reportPath,
            '--apply' => true,
        ])
            ->expectsOutputToContain('Apply completed: total=1 import_draft=1 manual_review=0 written_mappings=1')
            ->assertSuccessful();

        $content = LessonLearningContent::query()->firstOrFail();
        $mapping = LegacyImportMapping::query()->firstOrFail();
        $report = $this->readReport($reportPath);

        $this->assertSame($lesson->id, $content->lesson_id);
        $this->assertSame('Первая версия.', data_get($content->body, 'blocks.0.text'));
        $this->assertSame(LessonLearningContent::STATUS_DRAFT, $content->status);
        $this->assertSame(LegacyImportMapping::MAPPING_MAPPED, $mapping->mapping_status);
        $this->assertSame($content->id, $mapping->target_id);
        $this->assertSame('apply', $report['mode']);
        $this->assertSame(1, $report['stats']['source_rows']);
        $this->assertSame(1, $report['stats']['source_unique_rows']);
        $this->assertSame(0, $report['stats']['source_duplicates_skipped']);
        $this->assertSame($content->id, $report['items'][0]['target_content_id']);

        $this->writeSourceJson([
            [
                'id' => 203,
                'content' => '<p>Вторая версия.</p>',
            ],
        ], $sourcePath);

        $this->artisan('legacy:learning:import-bodies', [
            '--source-json' => $sourcePath,
            '--apply' => true,
        ])->assertSuccessful();

        $this->assertSame(1, LessonLearningContent::query()->count());
        $this->assertSame(1, LegacyImportMapping::query()->count());
        $this->assertSame('Вторая версия.', data_get($content->refresh()->body, 'blocks.0.text'));
    }

    public function test_include_inactive_allows_controlled_product_scoped_backfill(): void
    {
        $lesson = $this->createLessonWithLegacyPage(805, legacyProductId: 9805);
        $lesson->forceFill(['is_active' => false])->save();
        $lesson->course->forceFill(['is_active' => false])->save();
        $sourcePath = $this->writeSourceJson([
            [
                'id' => 805,
                'product_id' => 9805,
                'content' => '<p>Текст неактивного урока.</p>',
            ],
        ]);
        $reportPath = storage_path('app/legacy-lesson-body-include-inactive-report.json');

        $this->artisan('legacy:learning:import-bodies', [
            '--source-json' => $sourcePath,
            '--report' => $reportPath,
            '--include-inactive' => true,
        ])
            ->expectsOutputToContain('Dry-run completed: total=1 import_draft=1 manual_review=0 written_mappings=0')
            ->assertSuccessful();

        $report = $this->readReport($reportPath);

        $this->assertSame('dry-run', $report['mode']);
        $this->assertSame($lesson->id, $report['items'][0]['target_lesson_id']);
        $this->assertSame(0, LessonLearningContent::query()->count());
        $this->assertSame(0, LegacyImportMapping::query()->count());
    }

    public function test_dry_run_skips_identical_duplicate_id_only_source_rows_without_database_writes(): void
    {
        $this->createLessonWithLegacyPage(801);
        $sourcePath = $this->writeSourceJson([
            [
                'id' => 801,
                'content' => '<p>Одинаковый текст.</p>',
            ],
            [
                'id' => 801,
                'content' => '<p>Одинаковый текст.</p>',
            ],
        ]);
        $reportPath = storage_path('app/legacy-lesson-body-duplicate-dry-run-report.json');

        $this->artisan('legacy:learning:import-bodies', [
            '--source-json' => $sourcePath,
            '--report' => $reportPath,
        ])
            ->expectsOutputToContain('Dry-run completed: total=1 import_draft=1 manual_review=0 written_mappings=0')
            ->expectsOutputToContain('Duplicate source rows skipped: 1')
            ->assertSuccessful();

        $report = $this->readReport($reportPath);

        $this->assertSame(2, $report['stats']['source_rows']);
        $this->assertSame(1, $report['stats']['source_unique_rows']);
        $this->assertSame(1, $report['stats']['source_duplicates_skipped']);
        $this->assertSame(1, $report['stats']['total']);
        $this->assertCount(1, $report['items']);
        $this->assertSame(0, LegacyImportMapping::query()->count());
        $this->assertSame(0, LessonLearningContent::query()->count());
        $this->assertReportDoesNotLeakLegacyPayload($report, [
            'Одинаковый текст',
            $sourcePath,
        ]);
    }

    public function test_apply_skips_identical_duplicate_product_scoped_source_rows_and_writes_once(): void
    {
        $lesson = $this->createLessonWithLegacyPage(802, legacyProductId: 9801);
        $sourcePath = $this->writeSourceJson([
            [
                'id' => 990,
                'product_id' => 9801,
                'content' => '<p>Видео {video::55}</p>',
            ],
            [
                'id' => 990,
                'product_id' => 9801,
                'content' => '<p>Видео {video::55}</p>',
            ],
        ]);
        $reportPath = storage_path('app/legacy-lesson-body-duplicate-product-apply-report.json');

        $this->artisan('legacy:learning:import-bodies', [
            '--source-json' => $sourcePath,
            '--report' => $reportPath,
            '--apply' => true,
        ])
            ->expectsOutputToContain('Apply completed: total=1 import_draft=1 manual_review=0 written_mappings=1')
            ->expectsOutputToContain('Duplicate source rows skipped: 1')
            ->assertSuccessful();

        $report = $this->readReport($reportPath);
        $content = $lesson->learningContent()->firstOrFail();
        $mapping = LegacyImportMapping::query()->firstOrFail();

        $this->assertSame(2, $report['stats']['source_rows']);
        $this->assertSame(1, $report['stats']['source_unique_rows']);
        $this->assertSame(1, $report['stats']['source_duplicates_skipped']);
        $this->assertSame(1, $report['stats']['protected_asset_reference_rows']);
        $this->assertSame(1, $report['stats']['protected_asset_reference_blocks']);
        $this->assertSame(['legacy_file' => 0, 'legacy_video' => 1], $report['stats']['protected_asset_reference_rows_by_kind']);
        $this->assertSame(['legacy_file' => 0, 'legacy_video' => 1], $report['stats']['protected_asset_reference_blocks_by_kind']);
        $this->assertSame(1, LessonLearningContent::query()->count());
        $this->assertSame(1, LegacyImportMapping::query()->count());
        $this->assertSame($content->id, $mapping->target_id);
        $this->assertSame('990', $mapping->source_id);
        $this->assertSame('9801', $mapping->source_scope_id);
        $this->assertReportDoesNotLeakLegacyPayload($report, [
            'Видео',
            '{video::55}',
            'legacyAssetId',
            'assetKind',
            'legacy_protected_asset_reference',
            $sourcePath,
        ]);
    }

    public function test_apply_rejects_conflicting_duplicate_source_identity_before_database_writes(): void
    {
        $this->createLessonWithLegacyPage(803);
        $sourcePath = $this->writeSourceJson([
            [
                'id' => 803,
                'content' => '<p>Первая версия.</p>',
            ],
            [
                'id' => 803,
                'content' => '<p>Вторая версия.</p>',
            ],
        ]);
        $reportPath = storage_path('app/legacy-lesson-body-duplicate-conflict-report.json');
        File::delete($reportPath);

        $this->artisan('legacy:learning:import-bodies', [
            '--source-json' => $sourcePath,
            '--report' => $reportPath,
            '--apply' => true,
        ])
            ->expectsOutput('Duplicate source identity conflict for source_id=803;scope=id_only.')
            ->assertFailed();

        $this->assertFalse(File::exists($reportPath));
        $this->assertSame(0, LegacyImportMapping::query()->count());
        $this->assertSame(0, LessonLearningContent::query()->count());
    }

    public function test_dry_run_deduplicates_canonical_id_and_product_alias_rows(): void
    {
        $this->createLessonWithLegacyPage(804, legacyProductId: 9802);
        $sourcePath = $this->writeSourceJson([
            [
                'id' => 991,
                'product_id' => 9802,
                'content' => '<p>Канонический текст.</p>',
            ],
            [
                'id' => '991',
                'productId' => '9802',
                'content' => '<p>Канонический текст.</p>',
            ],
        ]);
        $reportPath = storage_path('app/legacy-lesson-body-canonical-duplicate-report.json');

        $this->artisan('legacy:learning:import-bodies', [
            '--source-json' => $sourcePath,
            '--report' => $reportPath,
        ])
            ->expectsOutputToContain('Dry-run completed: total=1 import_draft=1 manual_review=0 written_mappings=0')
            ->expectsOutputToContain('Duplicate source rows skipped: 1')
            ->assertSuccessful();

        $report = $this->readReport($reportPath);

        $this->assertSame(2, $report['stats']['source_rows']);
        $this->assertSame(1, $report['stats']['source_unique_rows']);
        $this->assertSame(1, $report['stats']['source_duplicates_skipped']);
        $this->assertSame('991', $report['items'][0]['source_id']);
        $this->assertSame(9802, $report['items'][0]['source_product_id']);
        $this->assertSame(0, LegacyImportMapping::query()->count());
        $this->assertSame(0, LessonLearningContent::query()->count());
        $this->assertReportDoesNotLeakLegacyPayload($report, [
            'Канонический текст',
            $sourcePath,
        ]);
    }

    public function test_limit_is_applied_before_duplicate_source_rows_are_skipped(): void
    {
        $this->createLessonWithLegacyPage(805);
        $this->createLessonWithLegacyPage(806);
        $sourcePath = $this->writeSourceJson([
            [
                'id' => 805,
                'content' => '<p>Ограниченный текст.</p>',
            ],
            [
                'id' => 805,
                'content' => '<p>Ограниченный текст.</p>',
            ],
            [
                'id' => 806,
                'content' => '<p>Строка за пределом limit.</p>',
            ],
        ]);
        $reportPath = storage_path('app/legacy-lesson-body-limit-dedupe-report.json');

        $this->artisan('legacy:learning:import-bodies', [
            '--source-json' => $sourcePath,
            '--report' => $reportPath,
            '--limit' => 2,
        ])
            ->expectsOutputToContain('Dry-run completed: total=1 import_draft=1 manual_review=0 written_mappings=0')
            ->expectsOutputToContain('Duplicate source rows skipped: 1')
            ->assertSuccessful();

        $report = $this->readReport($reportPath);

        $this->assertSame(2, $report['stats']['source_rows']);
        $this->assertSame(1, $report['stats']['source_unique_rows']);
        $this->assertSame(1, $report['stats']['source_duplicates_skipped']);
        $this->assertSame(1, $report['stats']['total']);
        $this->assertSame('805', $report['items'][0]['source_id']);
        $this->assertSame(0, LegacyImportMapping::query()->count());
        $this->assertSame(0, LessonLearningContent::query()->count());
        $this->assertReportDoesNotLeakLegacyPayload($report, [
            'Ограниченный текст',
            'Строка за пределом limit',
            $sourcePath,
        ]);
    }

    public function test_dry_run_does_not_mix_id_only_and_product_scoped_source_rows(): void
    {
        $idOnlyLesson = $this->createLessonWithLegacyPage(807, courseSlug: 'id-only');
        $productLesson = $this->createLessonWithLegacyPage(807, courseSlug: 'product-scoped', legacyProductId: 9803);
        $sourcePath = $this->writeSourceJson([
            [
                'id' => 807,
                'content' => '<p>Текст id-only.</p>',
            ],
            [
                'id' => 807,
                'product_id' => 9803,
                'content' => '<p>Текст product-scoped.</p>',
            ],
        ]);
        $reportPath = storage_path('app/legacy-lesson-body-id-and-product-scope-report.json');

        $this->artisan('legacy:learning:import-bodies', [
            '--source-json' => $sourcePath,
            '--report' => $reportPath,
        ])
            ->expectsOutputToContain('Dry-run completed: total=2 import_draft=1 manual_review=1 written_mappings=0')
            ->assertSuccessful();

        $report = $this->readReport($reportPath);

        $this->assertSame(2, $report['stats']['source_rows']);
        $this->assertSame(2, $report['stats']['source_unique_rows']);
        $this->assertSame(0, $report['stats']['source_duplicates_skipped']);
        $this->assertSame('manual_review', $report['items'][0]['action']);
        $this->assertSame('ambiguous_legacy_page_id', $report['items'][0]['manual_review_reason']);
        $this->assertSame('import_draft', $report['items'][1]['action']);
        $this->assertSame([$idOnlyLesson->id, $productLesson->id], $report['items'][0]['matched_lesson_ids']);
        $this->assertSame([$productLesson->id], $report['items'][1]['matched_lesson_ids']);
        $this->assertSame(0, LegacyImportMapping::query()->count());
        $this->assertSame(0, LessonLearningContent::query()->count());
        $this->assertReportDoesNotLeakLegacyPayload($report, [
            'Текст id-only',
            'Текст product-scoped',
            $sourcePath,
        ]);
    }

    public function test_dry_run_can_match_lesson_by_legacy_product_id_when_page_id_is_shared(): void
    {
        $targetLesson = $this->createLessonWithLegacyPage(1200, courseSlug: 'shared-page-a', legacyProductId: 9001);
        $this->createLessonWithLegacyPage(1200, courseSlug: 'shared-page-b', legacyProductId: 9002);
        $sourcePath = $this->writeSourceJson([
            [
                'id' => 501,
                'product_id' => 9001,
                'content' => '<p>Текст по продукту.</p>',
            ],
        ]);
        $reportPath = storage_path('app/legacy-lesson-body-product-match-report.json');

        $this->artisan('legacy:learning:import-bodies', [
            '--source-json' => $sourcePath,
            '--report' => $reportPath,
        ])
            ->expectsOutputToContain('Dry-run completed: total=1 import_draft=1 manual_review=0 written_mappings=0')
            ->assertSuccessful();

        $report = $this->readReport($reportPath);

        $this->assertSame('dry-run', $report['mode']);
        $this->assertSame('import_draft', $report['items'][0]['action']);
        $this->assertSame(9001, $report['items'][0]['source_product_id']);
        $this->assertSame(LegacyImportMapping::SOURCE_SCOPE_LEGACY_PRODUCT, $report['items'][0]['source_scope_type']);
        $this->assertSame('9001', $report['items'][0]['source_scope_id']);
        $this->assertSame([$targetLesson->id], $report['items'][0]['matched_lesson_ids']);
        $this->assertSame($targetLesson->id, $report['items'][0]['target_lesson_id']);
        $this->assertSame(0, $report['stats']['manual_review']);
        $this->assertSame(0, LegacyImportMapping::query()->count());
        $this->assertSame(0, LessonLearningContent::query()->count());
    }

    public function test_apply_imports_product_aware_rows_with_same_platform_page_id_and_is_idempotent(): void
    {
        $lessonA = $this->createLessonWithLegacyPage(1201, courseSlug: 'product-apply-a', legacyProductId: 9004);
        $lessonB = $this->createLessonWithLegacyPage(1201, courseSlug: 'product-apply-b', legacyProductId: 9005);
        $sourcePath = $this->writeSourceJson([
            [
                'id' => 503,
                'product_id' => 9004,
                'content' => '<p>Текст первого продукта.</p>',
            ],
            [
                'id' => 503,
                'product_id' => 9005,
                'content' => '<p>Текст второго продукта.</p>',
            ],
        ]);
        $reportPath = storage_path('app/legacy-lesson-body-product-apply-report.json');

        $this->artisan('legacy:learning:import-bodies', [
            '--source-json' => $sourcePath,
            '--apply' => true,
            '--report' => $reportPath,
        ])
            ->expectsOutputToContain('Apply completed: total=2 import_draft=2 manual_review=0 written_mappings=2')
            ->assertSuccessful();

        $report = $this->readReport($reportPath);
        $mappings = LegacyImportMapping::query()->orderBy('source_scope_id')->get();

        $this->assertSame(2, LegacyImportMapping::query()->count());
        $this->assertSame(2, LessonLearningContent::query()->count());
        $this->assertSame(2, $report['stats']['source_rows']);
        $this->assertSame(2, $report['stats']['source_unique_rows']);
        $this->assertSame(0, $report['stats']['source_duplicates_skipped']);
        $this->assertSame(['9004', '9005'], $mappings->pluck('source_scope_id')->all());
        $this->assertSame(LegacyImportMapping::SOURCE_SCOPE_LEGACY_PRODUCT, $mappings[0]->source_scope_type);
        $this->assertSame('Текст первого продукта.', data_get($lessonA->learningContent()->firstOrFail()->body, 'blocks.0.text'));
        $this->assertSame('Текст второго продукта.', data_get($lessonB->learningContent()->firstOrFail()->body, 'blocks.0.text'));
        $this->assertSame(9004, data_get($lessonA->learningContent()->firstOrFail()->metadata, 'legacy_product_id'));
        $this->assertSame('9004', $report['items'][0]['source_scope_id']);
        $this->assertSame('9005', $report['items'][1]['source_scope_id']);

        $this->writeSourceJson([
            [
                'id' => 503,
                'product_id' => 9004,
                'content' => '<p>Обновленный текст первого продукта.</p>',
            ],
        ], $sourcePath);

        $this->artisan('legacy:learning:import-bodies', [
            '--source-json' => $sourcePath,
            '--apply' => true,
        ])->assertSuccessful();

        $this->assertSame(2, LegacyImportMapping::query()->count());
        $this->assertSame(2, LessonLearningContent::query()->count());
        $this->assertSame('Обновленный текст первого продукта.', data_get($lessonA->learningContent()->firstOrFail()->body, 'blocks.0.text'));
    }

    public function test_rejects_invalid_product_id_without_database_writes(): void
    {
        $sourcePath = $this->writeSourceJson([
            [
                'id' => 504,
                'product_id' => '9001x',
                'content' => '<p>Некорректный product id.</p>',
            ],
        ]);

        $this->artisan('legacy:learning:import-bodies', [
            '--source-json' => $sourcePath,
        ])
            ->expectsOutput('Source row 504 product_id must be a positive integer.')
            ->assertFailed();

        $this->assertSame(0, LegacyImportMapping::query()->count());
        $this->assertSame(0, LessonLearningContent::query()->count());
    }

    public function test_rejects_conflicting_product_id_aliases_without_database_writes(): void
    {
        $sourcePath = $this->writeSourceJson([
            [
                'id' => 505,
                'product_id' => 9001,
                'productId' => 9002,
                'content' => '<p>Конфликт product id.</p>',
            ],
        ]);

        $this->artisan('legacy:learning:import-bodies', [
            '--source-json' => $sourcePath,
            '--apply' => true,
        ])
            ->expectsOutput('Source row 505 product_id and productId must match when both are present.')
            ->assertFailed();

        $this->assertSame(0, LegacyImportMapping::query()->count());
        $this->assertSame(0, LessonLearningContent::query()->count());
    }

    public function test_product_id_rows_report_missing_product_mapping_without_falling_back_to_page_id(): void
    {
        $this->createLessonWithLegacyPage(502, legacyProductId: 9003);
        $sourcePath = $this->writeSourceJson([
            [
                'id' => 502,
                'product_id' => 9999,
                'content' => '<p>Текст без lesson product mapping.</p>',
            ],
        ]);
        $reportPath = storage_path('app/legacy-lesson-body-missing-product-report.json');

        $this->artisan('legacy:learning:import-bodies', [
            '--source-json' => $sourcePath,
            '--report' => $reportPath,
        ])
            ->expectsOutputToContain('Dry-run completed: total=1 import_draft=0 manual_review=1 written_mappings=0')
            ->assertSuccessful();

        $report = $this->readReport($reportPath);

        $this->assertSame(['missing_legacy_product_id' => 1], $report['stats']['manual_review_reasons']);
        $this->assertSame(9999, $report['items'][0]['source_product_id']);
        $this->assertSame('missing_legacy_product_id', $report['items'][0]['manual_review_reason']);
        $this->assertSame([], $report['items'][0]['matched_lesson_ids']);
        $this->assertSame(0, LegacyImportMapping::query()->count());
        $this->assertSame(0, LessonLearningContent::query()->count());
        $this->assertReportDoesNotLeakLegacyPayload($report, [
            'Текст без lesson product mapping',
            $sourcePath,
        ]);
    }

    public function test_product_aware_manual_review_does_not_report_stale_draft_from_other_product(): void
    {
        $lessonA = $this->createLessonWithLegacyPage(1300, courseSlug: 'stale-product-a', legacyProductId: 9101);
        $lessonB = $this->createLessonWithLegacyPage(1300, courseSlug: 'stale-product-b', legacyProductId: 9102);
        $sourcePath = $this->writeSourceJson([
            [
                'id' => 601,
                'product_id' => 9101,
                'content' => '<p>Первый продукт импортирован.</p>',
            ],
        ]);

        $this->artisan('legacy:learning:import-bodies', [
            '--source-json' => $sourcePath,
            '--apply' => true,
        ])->assertSuccessful();

        $contentA = $lessonA->learningContent()->firstOrFail();
        $this->assertSame('Первый продукт импортирован.', data_get($contentA->body, 'blocks.0.text'));

        $this->writeSourceJson([
            [
                'id' => 601,
                'product_id' => 9102,
                'content' => '<p>Файл {file::abc}</p>',
            ],
        ], $sourcePath);
        $dryRunReportPath = storage_path('app/legacy-lesson-body-product-stale-dry-run-report.json');

        $this->artisan('legacy:learning:import-bodies', [
            '--source-json' => $sourcePath,
            '--report' => $dryRunReportPath,
        ])
            ->expectsOutputToContain('Dry-run completed: total=1 import_draft=0 manual_review=1 written_mappings=0')
            ->assertSuccessful();

        $dryRunReport = $this->readReport($dryRunReportPath);

        $this->assertSame(0, $dryRunReport['stats']['stale_imported_drafts']);
        $this->assertFalse($dryRunReport['items'][0]['stale_imported_draft']);
        $this->assertSame($lessonB->id, $dryRunReport['items'][0]['target_lesson_id']);
        $this->assertSame('9102', $dryRunReport['items'][0]['source_scope_id']);
        $this->assertSame('unsupported_legacy_shortcode', $dryRunReport['items'][0]['manual_review_reason']);
        $this->assertSame(1, LegacyImportMapping::query()->count());
        $this->assertSame(1, LessonLearningContent::query()->count());

        $applyReportPath = storage_path('app/legacy-lesson-body-product-stale-apply-report.json');

        $this->artisan('legacy:learning:import-bodies', [
            '--source-json' => $sourcePath,
            '--report' => $applyReportPath,
            '--apply' => true,
        ])
            ->expectsOutputToContain('Apply completed: total=1 import_draft=0 manual_review=1 written_mappings=1')
            ->assertSuccessful();

        $blockedMapping = LegacyImportMapping::query()
            ->where('source_scope_id', '9102')
            ->firstOrFail();
        $applyReport = $this->readReport($applyReportPath);

        $this->assertSame(LegacyImportMapping::MAPPING_BLOCKED, $blockedMapping->mapping_status);
        $this->assertSame(LegacyImportMapping::VALIDATION_MANUAL_REVIEW, $blockedMapping->validation_status);
        $this->assertSame('unsupported_legacy_shortcode', $blockedMapping->manual_review_reason);
        $this->assertSame(0, $applyReport['stats']['stale_imported_drafts']);
        $this->assertFalse($applyReport['items'][0]['stale_imported_draft']);
        $this->assertSame(2, LegacyImportMapping::query()->count());
        $this->assertSame(1, LessonLearningContent::query()->count());
        $this->assertSame('Первый продукт импортирован.', data_get($contentA->refresh()->body, 'blocks.0.text'));
        $this->assertReportDoesNotLeakLegacyPayload($applyReport, [
            'Файл',
            '{file::abc}',
            $sourcePath,
        ]);
    }

    public function test_apply_imports_supported_shortcodes_as_internal_reference_draft_blocks(): void
    {
        $lesson = $this->createLessonWithLegacyPage(204);
        $sourcePath = $this->writeSourceJson([
            [
                'id' => 204,
                'content' => '<p>Видео {video::55}</p><p>Файл {file::99}</p>',
            ],
        ]);
        $reportPath = storage_path('app/legacy-lesson-body-shortcode-report.json');

        $this->artisan('legacy:learning:import-bodies', [
            '--source-json' => $sourcePath,
            '--report' => $reportPath,
            '--apply' => true,
        ])
            ->expectsOutputToContain('Apply completed: total=1 import_draft=1 manual_review=0 written_mappings=1')
            ->assertSuccessful();

        $mapping = LegacyImportMapping::query()->firstOrFail();
        $content = $lesson->learningContent()->firstOrFail();

        $this->assertSame(LegacyImportMapping::MAPPING_MAPPED, $mapping->mapping_status);
        $this->assertSame(LegacyImportMapping::VALIDATION_PASSED, $mapping->validation_status);
        $this->assertSame($content->id, $mapping->target_id);
        $this->assertSame('legacy_protected_asset_reference', data_get($content->body, 'blocks.1.type'));
        $this->assertSame('legacy_video', data_get($content->body, 'blocks.1.assetKind'));
        $this->assertSame(55, data_get($content->body, 'blocks.1.legacyAssetId'));
        $this->assertSame('legacy_file', data_get($content->body, 'blocks.3.assetKind'));
        $this->assertSame(99, data_get($content->body, 'blocks.3.legacyAssetId'));
        $report = $this->readReport($reportPath);

        $this->assertSame([], $report['stats']['manual_review_reasons']);
        $this->assertSame(0, $report['stats']['stale_imported_drafts']);
        $this->assertSame(1, $report['stats']['protected_asset_reference_rows']);
        $this->assertSame(2, $report['stats']['protected_asset_reference_blocks']);
        $this->assertSame(['legacy_file' => 1, 'legacy_video' => 1], $report['stats']['protected_asset_reference_rows_by_kind']);
        $this->assertSame(['legacy_file' => 1, 'legacy_video' => 1], $report['stats']['protected_asset_reference_blocks_by_kind']);
        $this->assertArrayNotHasKey('protected_asset_references_count', $report['items'][0]);
        $this->assertArrayNotHasKey('legacy_file_references_count', $report['items'][0]);
        $this->assertArrayNotHasKey('legacy_video_references_count', $report['items'][0]);
        $this->assertFalse($report['items'][0]['stale_imported_draft']);
        $this->assertReportDoesNotLeakLegacyPayload($report, [
            'Видео',
            'Файл',
            '{file::99}',
            '{video::55}',
            'legacyAssetId',
            'assetKind',
            'legacy_protected_asset_reference',
            $sourcePath,
        ]);
    }

    public function test_apply_inventory_and_student_api_keep_internal_legacy_asset_references_safe(): void
    {
        $lesson = $this->createLessonWithLegacyPage(1204, legacyProductId: 9301);
        $sourcePath = $this->writeSourceJson([
            [
                'id' => 904,
                'product_id' => 9301,
                'content' => '<p>Видео {video::55}</p><p>Файл {file::99}</p>',
            ],
            [
                'id' => 904,
                'product_id' => 9301,
                'content' => '<p>Видео {video::55}</p><p>Файл {file::99}</p>',
            ],
        ]);
        $applyReportPath = storage_path('app/legacy-lesson-body-apply-inventory-report.json');

        $this->artisan('legacy:learning:import-bodies', [
            '--source-json' => $sourcePath,
            '--report' => $applyReportPath,
            '--apply' => true,
        ])
            ->expectsOutputToContain('Apply completed: total=1 import_draft=1 manual_review=0 written_mappings=1')
            ->expectsOutputToContain('Duplicate source rows skipped: 1')
            ->assertSuccessful();

        $applyReport = $this->readReport($applyReportPath);
        $content = $lesson->learningContent()->firstOrFail();

        $this->assertSame(2, $applyReport['stats']['source_rows']);
        $this->assertSame(1, $applyReport['stats']['source_unique_rows']);
        $this->assertSame(1, $applyReport['stats']['source_duplicates_skipped']);
        $this->assertSame(1, LessonLearningContent::query()->count());
        $this->assertSame(1, LegacyImportMapping::query()->count());
        $this->assertSame('legacy_protected_asset_reference', data_get($content->body, 'blocks.1.type'));
        $this->assertSame('legacy_video', data_get($content->body, 'blocks.1.assetKind'));
        $this->assertSame(55, data_get($content->body, 'blocks.1.legacyAssetId'));
        $this->assertReportDoesNotLeakLegacyPayload($applyReport, [
            'Видео',
            'Файл',
            '{video::55}',
            '{file::99}',
            'legacyAssetId',
            'assetKind',
            'legacy_protected_asset_reference',
            $sourcePath,
        ]);

        $inventoryReportPath = storage_path('app/legacy-protected-assets-after-apply-report.json');

        $this->artisan('legacy:learning:protected-assets:report', [
            '--report' => $inventoryReportPath,
        ])
            ->expectsOutputToContain('Protected asset inventory completed: scanned=1 contents_with_references=1 references=2')
            ->assertSuccessful();

        $inventoryReport = $this->readReport($inventoryReportPath);

        $this->assertSame(1, $inventoryReport['stats']['contents_scanned']);
        $this->assertSame(1, $inventoryReport['stats']['contents_with_references']);
        $this->assertSame(2, $inventoryReport['stats']['protected_asset_reference_blocks']);
        $this->assertSame(['legacy_file' => 1, 'legacy_video' => 1, 'unknown' => 0], $inventoryReport['stats']['protected_asset_reference_blocks_by_kind']);
        $this->assertSame($content->id, $inventoryReport['items'][0]['content_id']);
        $this->assertSame($lesson->id, $inventoryReport['items'][0]['lesson_id']);
        $this->assertSame('9301', $inventoryReport['items'][0]['source_scope_id']);
        $this->assertSame(2, $inventoryReport['items'][0]['references_count']);
        $this->assertReportDoesNotLeakLegacyPayload($inventoryReport, [
            'Видео',
            'Файл',
            '55',
            '99',
            'legacyAssetId',
            'assetKind',
            'legacy_protected_asset_reference',
            $inventoryReportPath,
        ]);

        $user = User::factory()->create(['role' => 'user_no_purchase']);
        $this->createGrant($user, $lesson);
        Sanctum::actingAs($user);

        $response = $this->getJson($this->lessonUrl($lesson))
            ->assertOk()
            ->assertJsonPath('data.content.status', 'metadata_only')
            ->assertJsonMissingPath('data.content.body');

        $this->assertStringNotContainsString('legacy_protected_asset_reference', $response->getContent() ?: '');
        $this->assertStringNotContainsString('legacyAssetId', $response->getContent() ?: '');
        $this->assertStringNotContainsString('assetKind', $response->getContent() ?: '');
        $this->assertStringNotContainsString('legacy_video', $response->getContent() ?: '');
        $this->assertStringNotContainsString('legacy_file', $response->getContent() ?: '');
    }

    public function test_dry_run_aggregates_protected_reference_counters_for_mixed_batch(): void
    {
        $this->createLessonWithLegacyPage(701);
        $this->createLessonWithLegacyPage(702);
        $this->createLessonWithLegacyPage(703);
        $this->createLessonWithLegacyPage(704);
        $this->createLessonWithLegacyPage(705);
        $this->createLessonWithLegacyPage(706);
        $sourcePath = $this->writeSourceJson([
            [
                'id' => 701,
                'content' => '<p>Чистый текст.</p>',
            ],
            [
                'id' => 702,
                'content' => '<p>Файл {file::99}</p>',
            ],
            [
                'id' => 703,
                'content' => '<p>Видео {video::55}</p>',
            ],
            [
                'id' => 704,
                'content' => '<p>Видео {video::56} и файл {file::100}</p>',
            ],
            [
                'id' => 705,
                'content' => '<p>Поломанный файл {file::abc}</p>',
            ],
            [
                'id' => 706,
                'content' => '   ',
            ],
        ]);
        $reportPath = storage_path('app/legacy-lesson-body-mixed-reference-report.json');

        $this->artisan('legacy:learning:import-bodies', [
            '--source-json' => $sourcePath,
            '--report' => $reportPath,
        ])
            ->expectsOutputToContain('Dry-run completed: total=6 import_draft=4 manual_review=2 written_mappings=0')
            ->assertSuccessful();

        $report = $this->readReport($reportPath);

        $this->assertSame(3, $report['stats']['protected_asset_reference_rows']);
        $this->assertSame(4, $report['stats']['protected_asset_reference_blocks']);
        $this->assertSame(['legacy_file' => 2, 'legacy_video' => 2], $report['stats']['protected_asset_reference_rows_by_kind']);
        $this->assertSame(['legacy_file' => 2, 'legacy_video' => 2], $report['stats']['protected_asset_reference_blocks_by_kind']);
        $this->assertSame([
            'empty_legacy_body' => 1,
            'unsupported_legacy_shortcode' => 1,
        ], $report['stats']['manual_review_reasons']);
        $this->assertSame(['import_draft', 'import_draft', 'import_draft', 'import_draft', 'manual_review', 'manual_review'], array_column($report['items'], 'action'));
        $this->assertSame(0, LegacyImportMapping::query()->count());
        $this->assertSame(0, LessonLearningContent::query()->count());
        $this->assertReportDoesNotLeakLegacyPayload($report, [
            'Чистый текст',
            'Файл',
            'Видео',
            'Поломанный файл',
            '{file::99}',
            '{video::55}',
            '{video::56}',
            '{file::100}',
            '{file::abc}',
            'legacyAssetId',
            'assetKind',
            'legacy_protected_asset_reference',
            $sourcePath,
        ]);
    }

    public function test_reports_stale_imported_draft_when_later_source_requires_manual_review(): void
    {
        $lesson = $this->createLessonWithLegacyPage(207);
        $sourcePath = $this->writeSourceJson([
            [
                'id' => 207,
                'content' => '<p>Первичная версия.</p>',
            ],
        ]);

        $this->artisan('legacy:learning:import-bodies', [
            '--source-json' => $sourcePath,
            '--apply' => true,
        ])->assertSuccessful();

        $content = LessonLearningContent::query()->firstOrFail();
        $mapping = LegacyImportMapping::query()->firstOrFail();

        $this->assertSame($lesson->id, $content->lesson_id);
        $this->assertSame('Первичная версия.', data_get($content->body, 'blocks.0.text'));
        $this->assertSame(LegacyImportMapping::MAPPING_MAPPED, $mapping->mapping_status);

        $this->writeSourceJson([
            [
                'id' => 207,
                'content' => '<p>Новый файл {file::abc}</p>',
            ],
        ], $sourcePath);
        $dryRunReportPath = storage_path('app/legacy-lesson-body-stale-dry-run-report.json');

        $this->artisan('legacy:learning:import-bodies', [
            '--source-json' => $sourcePath,
            '--report' => $dryRunReportPath,
        ])
            ->expectsOutputToContain('Dry-run completed: total=1 import_draft=0 manual_review=1 written_mappings=0')
            ->expectsOutputToContain('Stale imported drafts requiring manual review: 1')
            ->assertSuccessful();

        $dryRunReport = $this->readReport($dryRunReportPath);

        $this->assertStaleDraftReportItem($dryRunReport, $lesson, $content);
        $this->assertSame(LegacyImportMapping::MAPPING_MAPPED, $mapping->refresh()->mapping_status);
        $this->assertSame('Первичная версия.', data_get($content->refresh()->body, 'blocks.0.text'));
        $this->assertSame(1, LessonLearningContent::query()->count());
        $this->assertSame(1, LegacyImportMapping::query()->count());

        $applyReportPath = storage_path('app/legacy-lesson-body-stale-apply-report.json');

        $this->artisan('legacy:learning:import-bodies', [
            '--source-json' => $sourcePath,
            '--report' => $applyReportPath,
            '--apply' => true,
        ])
            ->expectsOutputToContain('Apply completed: total=1 import_draft=0 manual_review=1 written_mappings=1')
            ->expectsOutputToContain('Stale imported drafts requiring manual review: 1')
            ->assertSuccessful();

        $mapping->refresh();

        $this->assertStaleDraftReportItem($this->readReport($applyReportPath), $lesson, $content);
        $this->assertSame(LegacyImportMapping::MAPPING_BLOCKED, $mapping->mapping_status);
        $this->assertSame(LegacyImportMapping::VALIDATION_MANUAL_REVIEW, $mapping->validation_status);
        $this->assertSame('unsupported_legacy_shortcode', $mapping->manual_review_reason);
        $this->assertSame($content->id, $mapping->target_id);
        $this->assertTrue(data_get($mapping->metadata, 'stale_imported_draft'));
        $this->assertSame('Первичная версия.', data_get($content->refresh()->body, 'blocks.0.text'));
        $this->assertSame(1, LessonLearningContent::query()->count());
        $this->assertSame(1, LegacyImportMapping::query()->count());
    }

    public function test_reports_stale_imported_draft_for_ambiguous_legacy_page_id(): void
    {
        $lesson = $this->createLessonWithLegacyPage(208, courseSlug: 'original');
        $sourcePath = $this->writeSourceJson([
            [
                'id' => 208,
                'content' => '<p>Первичная версия.</p>',
            ],
        ]);

        $this->artisan('legacy:learning:import-bodies', [
            '--source-json' => $sourcePath,
            '--apply' => true,
        ])->assertSuccessful();

        $content = LessonLearningContent::query()->firstOrFail();
        $mapping = LegacyImportMapping::query()->firstOrFail();

        $this->createLessonWithLegacyPage(208, courseSlug: 'duplicate');
        $this->writeSourceJson([
            [
                'id' => 208,
                'content' => '<p>Теперь legacy_page_id неоднозначен.</p>',
            ],
        ], $sourcePath);
        $reportPath = storage_path('app/legacy-lesson-body-stale-ambiguous-report.json');

        $this->artisan('legacy:learning:import-bodies', [
            '--source-json' => $sourcePath,
            '--report' => $reportPath,
        ])
            ->expectsOutputToContain('Dry-run completed: total=1 import_draft=0 manual_review=1 written_mappings=0')
            ->expectsOutputToContain('Stale imported drafts requiring manual review: 1')
            ->assertSuccessful();

        $report = $this->readReport($reportPath);

        $this->assertSame(1, $report['stats']['manual_review']);
        $this->assertSame(1, $report['stats']['stale_imported_drafts']);
        $this->assertSame(['ambiguous_legacy_page_id' => 1], $report['stats']['manual_review_reasons']);
        $this->assertSame('manual_review', $report['items'][0]['action']);
        $this->assertSame('ambiguous_legacy_page_id', $report['items'][0]['manual_review_reason']);
        $this->assertEqualsCanonicalizing([$lesson->id, Lesson::query()->where('slug', 'lesson-duplicate')->value('id')], $report['items'][0]['matched_lesson_ids']);
        $this->assertNull($report['items'][0]['target_lesson_id']);
        $this->assertNull($report['items'][0]['target_content_id']);
        $this->assertTrue($report['items'][0]['stale_imported_draft']);
        $this->assertSame([$content->id], $report['items'][0]['stale_imported_draft_content_ids']);
        $this->assertSame([$lesson->id], $report['items'][0]['stale_imported_draft_lesson_ids']);
        $this->assertSame(LegacyImportMapping::MAPPING_MAPPED, $mapping->refresh()->mapping_status);
        $this->assertSame('Первичная версия.', data_get($content->refresh()->body, 'blocks.0.text'));
        $this->assertSame(1, LessonLearningContent::query()->count());
        $this->assertSame(1, LegacyImportMapping::query()->count());
        $this->assertReportDoesNotLeakLegacyPayload($report, [
            'Теперь legacy_page_id неоднозначен',
            $sourcePath,
        ]);
    }

    public function test_report_aggregates_manual_review_reasons_for_mixed_batch_stably(): void
    {
        $this->createLessonWithLegacyPage(209, courseSlug: 'ambiguous-a');
        $this->createLessonWithLegacyPage(209, courseSlug: 'ambiguous-b');
        $this->createLessonWithLegacyPage(211);
        $this->createLessonWithLegacyPage(212);

        $sourcePath = $this->writeSourceJson([
            [
                'id' => 209,
                'content' => '<p>Неоднозначный урок.</p>',
            ],
            [
                'id' => 210,
                'content' => '<p>Нет нового урока.</p>',
            ],
            [
                'id' => 211,
                'content' => '<p>Файл {file::abc}</p>',
            ],
            [
                'id' => 212,
                'content' => '   ',
            ],
        ]);
        $reportPath = storage_path('app/legacy-lesson-body-mixed-manual-review-report.json');

        $this->artisan('legacy:learning:import-bodies', [
            '--source-json' => $sourcePath,
            '--report' => $reportPath,
        ])
            ->expectsOutputToContain('Dry-run completed: total=4 import_draft=0 manual_review=4 written_mappings=0')
            ->assertSuccessful();

        $report = $this->readReport($reportPath);

        $this->assertSame([
            'ambiguous_legacy_page_id' => 1,
            'empty_legacy_body' => 1,
            'missing_legacy_page_id' => 1,
            'unsupported_legacy_shortcode' => 1,
        ], $report['stats']['manual_review_reasons']);
        $this->assertSame(0, $report['stats']['stale_imported_drafts']);
        $this->assertSame(['ambiguous_legacy_page_id', 'missing_legacy_page_id', 'unsupported_legacy_shortcode', 'empty_legacy_body'], array_column($report['items'], 'manual_review_reason'));
        $this->assertSame(0, LessonLearningContent::query()->count());
        $this->assertSame(0, LegacyImportMapping::query()->count());
        $this->assertReportDoesNotLeakLegacyPayload($report, [
            'Неоднозначный урок',
            'Нет нового урока',
            'Файл',
            '{file::abc}',
            $sourcePath,
        ]);
    }

    public function test_limit_processes_only_first_rows(): void
    {
        $this->createLessonWithLegacyPage(205);
        $this->createLessonWithLegacyPage(206);
        $sourcePath = $this->writeSourceJson([
            [
                'id' => 205,
                'content' => '<p>Первый.</p>',
            ],
            [
                'id' => 206,
                'content' => '<p>Второй.</p>',
            ],
        ]);

        $this->artisan('legacy:learning:import-bodies', [
            '--source-json' => $sourcePath,
            '--apply' => true,
            '--limit' => 1,
        ])
            ->expectsOutputToContain('Apply completed: total=1 import_draft=1 manual_review=0 written_mappings=1')
            ->assertSuccessful();

        $this->assertSame(1, LessonLearningContent::query()->count());
        $this->assertSame(1, LegacyImportMapping::query()->count());
    }

    public function test_requires_source_json(): void
    {
        $this->artisan('legacy:learning:import-bodies')
            ->expectsOutput('Missing --source-json=/absolute/path/export.json.')
            ->assertFailed();
    }

    public function test_rejects_invalid_json_without_database_writes(): void
    {
        $sourcePath = storage_path('app/legacy-lesson-body-invalid.json');
        File::ensureDirectoryExists(dirname($sourcePath));
        File::put($sourcePath, '{not valid json');

        $this->artisan('legacy:learning:import-bodies', [
            '--source-json' => $sourcePath,
            '--apply' => true,
        ])
            ->expectsOutputToContain('Syntax error')
            ->assertFailed();

        $this->assertSame(0, LegacyImportMapping::query()->count());
        $this->assertSame(0, LessonLearningContent::query()->count());
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function writeSourceJson(array $rows, ?string $path = null): string
    {
        $path ??= storage_path('app/legacy-lesson-body-source.json');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode(['platform_pages' => $rows], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $path;
    }

    /**
     * @return array<string, mixed>
     */
    private function readReport(string $path): array
    {
        $this->assertFileExists($path);

        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function assertStaleDraftReportItem(array $report, Lesson $lesson, LessonLearningContent $content): void
    {
        $this->assertSame(1, $report['stats']['manual_review']);
        $this->assertSame(1, $report['stats']['stale_imported_drafts']);
        $this->assertSame(['unsupported_legacy_shortcode' => 1], $report['stats']['manual_review_reasons']);
        $this->assertSame('manual_review', $report['items'][0]['action']);
        $this->assertSame('unsupported_legacy_shortcode', $report['items'][0]['manual_review_reason']);
        $this->assertSame($lesson->id, $report['items'][0]['target_lesson_id']);
        $this->assertSame($content->id, $report['items'][0]['target_content_id']);
        $this->assertTrue($report['items'][0]['stale_imported_draft']);
        $this->assertSame([$content->id], $report['items'][0]['stale_imported_draft_content_ids']);
        $this->assertSame([$lesson->id], $report['items'][0]['stale_imported_draft_lesson_ids']);
        $this->assertSame(0, $report['items'][0]['body_blocks_count']);
        $this->assertReportDoesNotLeakLegacyPayload($report, [
            'Новый файл',
            '{file::abc}',
        ]);
    }

    /**
     * @param  array<string, mixed>  $report
     * @param  array<int, string>  $blockedValues
     */
    private function assertReportDoesNotLeakLegacyPayload(array $report, array $blockedValues): void
    {
        $payload = json_encode($report, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        foreach (array_merge($blockedValues, [
            '"body":',
            'source_fingerprint_hash',
            'source_path',
            '{file::',
            '{video::',
            'media',
            'files',
            'contentUrl',
            'signedUrl',
            'fileUrl',
            'downloadUrl',
            'videoUrl',
            'mediaUrl',
            'storageKey',
            'providerId',
        ]) as $blockedValue) {
            $this->assertStringNotContainsString($blockedValue, $payload);
        }
    }

    private function createLessonWithLegacyPage(int $legacyPageId, ?string $courseSlug = null, ?int $legacyProductId = null): Lesson
    {
        $courseSlug ??= 'course-'.$legacyPageId;

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

    private function createGrant(User $user, Lesson $lesson): UserEntitlementGrant
    {
        return UserEntitlementGrant::query()->create([
            'user_id' => $user->id,
            'atom_kind' => UserEntitlementGrant::ATOM_LESSON,
            'atom_id' => $lesson->id,
            'status' => UserEntitlementGrant::STATUS_ACTIVE,
            'source_type' => UserEntitlementGrant::SOURCE_TEST_FIXTURE,
            'source_id' => "lesson-{$lesson->id}",
            'idempotency_key' => "lesson-{$lesson->id}-{$user->id}",
            'granted_at' => now(),
        ]);
    }

    private function lessonUrl(Lesson $lesson): string
    {
        $lesson->loadMissing('course.subject');

        return "/api/v1/learning/subjects/{$lesson->course->subject->slug}/courses/{$lesson->course->slug}/lessons/{$lesson->slug}";
    }
}
