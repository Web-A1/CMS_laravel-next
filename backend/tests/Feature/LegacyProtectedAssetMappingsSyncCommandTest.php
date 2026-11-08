<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\LegacyImportMapping;
use App\Models\LegacyProtectedAssetMapping;
use App\Models\Lesson;
use App\Models\LessonLearningContent;
use App\Models\Subject;
use App\Models\User;
use App\Models\UserEntitlementGrant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LegacyProtectedAssetMappingsSyncCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_reports_mapping_rows_without_database_writes_or_raw_asset_leakage(): void
    {
        $content = $this->createContent($this->createLesson('dry-run'), [
            [
                'type' => 'paragraph',
                'text' => 'Текст с защищёнными материалами.',
            ],
            [
                'type' => 'legacy_protected_asset_reference',
                'assetKind' => 'legacy_video',
                'legacyAssetId' => 880055,
                'origin' => 'legacy_shortcode',
            ],
            [
                'type' => 'legacy_protected_asset_reference',
                'assetKind' => 'legacy_file',
                'legacyAssetId' => 990099,
                'origin' => 'legacy_shortcode',
            ],
            [
                'type' => 'legacy_protected_asset_reference',
                'assetKind' => 'legacy_video',
                'legacyAssetId' => 880055,
                'origin' => 'legacy_shortcode',
            ],
        ], sourceId: '501', sourceScopeId: '9001');
        $reportPath = storage_path('app/legacy-protected-asset-mappings-dry-run-report.json');

        $this->artisan('legacy:learning:protected-assets:sync', [
            '--report' => $reportPath,
        ])
            ->expectsOutputToContain('Dry-run completed: scanned=1 references=3 mapping_rows=2 written_mappings=0 manual_review=0')
            ->assertSuccessful();

        $report = $this->readReport($reportPath);

        $this->assertSame('dry-run', $report['mode']);
        $this->assertSame('legacy_protected_asset_mappings', $report['target_table']);
        $this->assertSame(1, $report['stats']['contents_scanned']);
        $this->assertSame(1, $report['stats']['contents_with_references']);
        $this->assertSame(3, $report['stats']['protected_asset_reference_blocks']);
        $this->assertSame(2, $report['stats']['mapping_rows']);
        $this->assertSame(0, $report['stats']['written_mappings']);
        $this->assertSame(0, $report['stats']['manual_review']);
        $this->assertSame(['legacy_file' => 1, 'legacy_video' => 1], $report['stats']['mapping_rows_by_kind']);
        $this->assertSame($content->id, $report['items'][0]['content_id']);
        $videoItem = collect($report['items'])->firstWhere('asset_kind', 'legacy_video');
        $this->assertIsArray($videoItem);
        $this->assertSame(2, $videoItem['reference_count']);
        $this->assertSame(0, LegacyProtectedAssetMapping::query()->count());
        $this->assertReportDoesNotLeakRawPayload($report, [
            'Текст с защищёнными материалами',
            '880055',
            '990099',
            'legacyAssetId',
            'assetKind',
            'legacy_protected_asset_reference',
            'origin',
            $reportPath,
        ]);
    }

    public function test_apply_creates_backend_only_mapping_rows_and_is_idempotent(): void
    {
        $lesson = $this->createLesson('apply');
        $content = $this->createContent($lesson, [
            [
                'type' => 'legacy_protected_asset_reference',
                'assetKind' => 'legacy_video',
                'legacyAssetId' => 880055,
                'origin' => 'legacy_shortcode',
            ],
            [
                'type' => 'legacy_protected_asset_reference',
                'assetKind' => 'legacy_file',
                'legacyAssetId' => 990099,
                'origin' => 'legacy_shortcode',
            ],
            [
                'type' => 'legacy_protected_asset_reference',
                'assetKind' => 'legacy_video',
                'legacyAssetId' => 880055,
                'origin' => 'legacy_shortcode',
            ],
        ], sourceId: '502', sourceScopeId: '9002');
        $reportPath = storage_path('app/legacy-protected-asset-mappings-apply-report.json');

        $this->artisan('legacy:learning:protected-assets:sync', [
            '--report' => $reportPath,
            '--apply' => true,
        ])
            ->expectsOutputToContain('Apply completed: scanned=1 references=3 mapping_rows=2 written_mappings=2 manual_review=0')
            ->assertSuccessful();

        $report = $this->readReport($reportPath);
        $mappings = LegacyProtectedAssetMapping::query()->orderBy('asset_kind')->get();

        $this->assertSame('apply', $report['mode']);
        $this->assertSame(2, $mappings->count());
        $this->assertSame($content->id, $mappings[0]->lesson_learning_content_id);
        $this->assertSame($lesson->id, $mappings[0]->lesson_id);
        $this->assertSame('legacy_file', $mappings[0]->asset_kind);
        $this->assertSame(990099, $mappings[0]->legacy_asset_id);
        $this->assertSame(1, $mappings[0]->reference_count);
        $this->assertSame('legacy_video', $mappings[1]->asset_kind);
        $this->assertSame(880055, $mappings[1]->legacy_asset_id);
        $this->assertSame(2, $mappings[1]->reference_count);
        $this->assertSame(LegacyProtectedAssetMapping::READINESS_NEEDS_MAPPING, $mappings[1]->readiness_status);
        $this->assertSame(LegacyProtectedAssetMapping::VALIDATION_PENDING, $mappings[1]->validation_status);
        $this->assertSame('platform_page', $mappings[1]->source_table);
        $this->assertSame('502', $mappings[1]->source_id);
        $this->assertSame('legacy_product', $mappings[1]->source_scope_type);
        $this->assertSame('9002', $mappings[1]->source_scope_id);
        $this->assertNull($mappings[1]->internal_asset_type);
        $this->assertNull($mappings[1]->internal_asset_id);
        $this->assertReportDoesNotLeakRawPayload($report, [
            '880055',
            '990099',
            'legacyAssetId',
            'assetKind',
            'legacy_protected_asset_reference',
            $reportPath,
        ]);
        $mappings[1]->forceFill([
            'readiness_status' => LegacyProtectedAssetMapping::READINESS_MAPPED_INTERNAL,
            'validation_status' => LegacyProtectedAssetMapping::VALIDATION_PASSED,
            'internal_asset_type' => 'protected_video',
            'internal_asset_id' => 321,
            'manual_review_reason' => 'operator_checked',
            'metadata' => [
                'operator_note' => 'keep',
            ],
        ])->save();

        $secondReportPath = storage_path('app/legacy-protected-asset-mappings-apply-second-report.json');

        $this->artisan('legacy:learning:protected-assets:sync', [
            '--report' => $secondReportPath,
            '--apply' => true,
        ])->assertSuccessful();

        $secondReport = $this->readReport($secondReportPath);

        $this->assertSame(0, $secondReport['stats']['stale_mapping_rows']);
        $this->assertSame([], $secondReport['stale_items']);
        $this->assertSame(2, LegacyProtectedAssetMapping::query()->count());
        $this->assertSame(LegacyProtectedAssetMapping::READINESS_MAPPED_INTERNAL, $mappings[1]->refresh()->readiness_status);
        $this->assertSame(LegacyProtectedAssetMapping::VALIDATION_PASSED, $mappings[1]->validation_status);
        $this->assertSame('protected_video', $mappings[1]->internal_asset_type);
        $this->assertSame(321, $mappings[1]->internal_asset_id);
        $this->assertSame('operator_checked', $mappings[1]->manual_review_reason);
        $this->assertSame(['operator_note' => 'keep'], $mappings[1]->metadata);
    }

    public function test_sync_reports_stale_mapping_rows_without_deleting_or_leaking_raw_asset_ids(): void
    {
        $content = $this->createContent($this->createLesson('stale'), [
            [
                'type' => 'legacy_protected_asset_reference',
                'assetKind' => 'legacy_video',
                'legacyAssetId' => 880055,
                'origin' => 'legacy_shortcode',
            ],
        ]);

        $this->artisan('legacy:learning:protected-assets:sync', [
            '--apply' => true,
        ])->assertSuccessful();

        $mapping = LegacyProtectedAssetMapping::query()->firstOrFail();
        $content->forceFill([
            'body' => [
                'blocks' => [
                    [
                        'type' => 'paragraph',
                        'text' => 'Текст без защищённой ссылки.',
                    ],
                ],
                'toc' => [],
            ],
        ])->save();
        $reportPath = storage_path('app/legacy-protected-asset-mappings-stale-report.json');

        $this->artisan('legacy:learning:protected-assets:sync', [
            '--report' => $reportPath,
        ])
            ->expectsOutputToContain('Dry-run completed: scanned=1 references=0 mapping_rows=0 written_mappings=0 manual_review=0')
            ->assertSuccessful();

        $report = $this->readReport($reportPath);

        $this->assertSame(0, $report['stats']['protected_asset_reference_blocks']);
        $this->assertSame(0, $report['stats']['mapping_rows']);
        $this->assertSame(1, $report['stats']['stale_mapping_rows']);
        $this->assertSame($mapping->id, $report['stale_items'][0]['mapping_id']);
        $this->assertSame($content->id, $report['stale_items'][0]['content_id']);
        $this->assertSame('legacy_video', $report['stale_items'][0]['asset_kind']);
        $this->assertSame(1, LegacyProtectedAssetMapping::query()->count());
        $this->assertReportDoesNotLeakRawPayload($report, [
            'Текст без защищённой ссылки',
            '880055',
            'legacyAssetId',
            'assetKind',
            'legacy_protected_asset_reference',
            $reportPath,
        ]);
    }

    public function test_sync_reports_invalid_internal_references_without_writes(): void
    {
        $this->createContent($this->createLesson('invalid'), [
            [
                'type' => 'legacy_protected_asset_reference',
                'assetKind' => 'legacy_audio',
                'legacyAssetId' => 770077,
                'origin' => 'legacy_shortcode',
            ],
            [
                'type' => 'legacy_protected_asset_reference',
                'assetKind' => 'legacy_file',
                'legacyAssetId' => 0,
                'origin' => 'legacy_shortcode',
            ],
        ]);
        $reportPath = storage_path('app/legacy-protected-asset-mappings-invalid-report.json');

        $this->artisan('legacy:learning:protected-assets:sync', [
            '--report' => $reportPath,
        ])
            ->expectsOutputToContain('Dry-run completed: scanned=1 references=2 mapping_rows=0 written_mappings=0 manual_review=2')
            ->assertSuccessful();

        $report = $this->readReport($reportPath);

        $this->assertSame(1, $report['stats']['contents_with_references']);
        $this->assertSame(2, $report['stats']['protected_asset_reference_blocks']);
        $this->assertSame(0, $report['stats']['mapping_rows']);
        $this->assertSame(2, $report['stats']['manual_review']);
        $this->assertSame([
            'invalid_legacy_asset_id' => 1,
            'unsupported_asset_kind' => 1,
        ], $report['stats']['manual_review_reasons']);
        $this->assertSame([], $report['items']);
        $this->assertSame(0, LegacyProtectedAssetMapping::query()->count());
        $this->assertReportDoesNotLeakRawPayload($report, [
            'legacy_audio',
            '770077',
            'legacyAssetId',
            'assetKind',
            'legacy_protected_asset_reference',
            $reportPath,
        ]);
    }

    public function test_mapping_rows_do_not_leak_through_student_learning_api(): void
    {
        $lesson = $this->createLesson('api');
        $content = $this->createContent($lesson, [
            [
                'type' => 'legacy_protected_asset_reference',
                'assetKind' => 'legacy_video',
                'legacyAssetId' => 880055,
                'origin' => 'legacy_shortcode',
            ],
        ]);
        LegacyProtectedAssetMapping::query()->create([
            'lesson_learning_content_id' => $content->id,
            'lesson_id' => $lesson->id,
            'source_table' => 'platform_page',
            'source_id' => '504',
            'source_scope_type' => 'legacy_product',
            'source_scope_id' => '9004',
            'asset_kind' => LegacyProtectedAssetMapping::ASSET_KIND_VIDEO,
            'legacy_asset_id' => 880055,
            'reference_count' => 1,
            'first_block_index' => 0,
            'readiness_status' => LegacyProtectedAssetMapping::READINESS_NEEDS_MAPPING,
            'validation_status' => LegacyProtectedAssetMapping::VALIDATION_PENDING,
        ]);
        $user = User::factory()->create(['role' => 'user_no_purchase']);
        $this->createGrant($user, $lesson);
        Sanctum::actingAs($user);

        $response = $this->getJson($this->lessonUrl($lesson))
            ->assertOk()
            ->assertJsonPath('data.content.status', 'metadata_only')
            ->assertJsonMissingPath('data.content.body');

        foreach ([
            'legacy_protected_asset_reference',
            'legacyAssetId',
            'assetKind',
            'legacy_video',
            '880055',
            'legacy_protected_asset_mappings',
            'readiness_status',
        ] as $forbiddenValue) {
            $this->assertStringNotContainsString($forbiddenValue, $response->getContent() ?: '');
        }
    }

    public function test_import_sync_inventory_and_student_api_chain_keeps_mappings_backend_only(): void
    {
        $lesson = $this->createLessonWithLegacyPage(1404, legacyProductId: 9401);
        $sourcePath = $this->writeSourceJson([
            [
                'id' => 804,
                'product_id' => 9401,
                'content' => '<p>Видео {video::550001}</p><p>Файл {file::990001}</p>',
            ],
        ]);
        $importReportPath = storage_path('app/legacy-lesson-body-sync-chain-import-report.json');

        $this->artisan('legacy:learning:import-bodies', [
            '--source-json' => $sourcePath,
            '--report' => $importReportPath,
            '--apply' => true,
        ])
            ->expectsOutputToContain('Apply completed: total=1 import_draft=1 manual_review=0 written_mappings=1')
            ->assertSuccessful();

        $content = $lesson->learningContent()->firstOrFail();

        $this->assertSame(1, LessonLearningContent::query()->count());
        $this->assertSame(1, LegacyImportMapping::query()->count());
        $this->assertSame(0, LegacyProtectedAssetMapping::query()->count());
        $this->assertSame('legacy_protected_asset_reference', data_get($content->body, 'blocks.1.type'));
        $this->assertSame('legacy_video', data_get($content->body, 'blocks.1.assetKind'));
        $this->assertSame(550001, data_get($content->body, 'blocks.1.legacyAssetId'));
        $this->assertSame('legacy_file', data_get($content->body, 'blocks.3.assetKind'));
        $this->assertSame(990001, data_get($content->body, 'blocks.3.legacyAssetId'));
        $this->assertReportDoesNotLeakRawPayload($this->readReport($importReportPath), [
            'Видео',
            'Файл',
            '{video::550001}',
            '{file::990001}',
            'legacyAssetId',
            'assetKind',
            'legacy_protected_asset_reference',
            $sourcePath,
        ]);

        $syncReportPath = storage_path('app/legacy-protected-asset-mappings-sync-chain-report.json');

        $this->artisan('legacy:learning:protected-assets:sync', [
            '--report' => $syncReportPath,
            '--apply' => true,
        ])
            ->expectsOutputToContain('Apply completed: scanned=1 references=2 mapping_rows=2 written_mappings=2 manual_review=0')
            ->assertSuccessful();

        $syncReport = $this->readReport($syncReportPath);
        $mappings = LegacyProtectedAssetMapping::query()->orderBy('asset_kind')->get();

        $this->assertSame('apply', $syncReport['mode']);
        $this->assertSame(1, $syncReport['stats']['contents_scanned']);
        $this->assertSame(1, $syncReport['stats']['contents_with_references']);
        $this->assertSame(2, $syncReport['stats']['protected_asset_reference_blocks']);
        $this->assertSame(2, $syncReport['stats']['mapping_rows']);
        $this->assertSame(2, $syncReport['stats']['written_mappings']);
        $this->assertSame(['legacy_file' => 1, 'legacy_video' => 1], $syncReport['stats']['mapping_rows_by_kind']);
        $this->assertSame(2, $mappings->count());

        $fileMapping = $mappings->firstWhere('asset_kind', LegacyProtectedAssetMapping::ASSET_KIND_FILE);
        $videoMapping = $mappings->firstWhere('asset_kind', LegacyProtectedAssetMapping::ASSET_KIND_VIDEO);

        $this->assertInstanceOf(LegacyProtectedAssetMapping::class, $fileMapping);
        $this->assertInstanceOf(LegacyProtectedAssetMapping::class, $videoMapping);
        $this->assertSame(990001, $fileMapping->legacy_asset_id);
        $this->assertSame(550001, $videoMapping->legacy_asset_id);

        foreach ([$fileMapping, $videoMapping] as $mapping) {
            $this->assertSame($content->id, $mapping->lesson_learning_content_id);
            $this->assertSame($lesson->id, $mapping->lesson_id);
            $this->assertSame('platform_page', $mapping->source_table);
            $this->assertSame('804', $mapping->source_id);
            $this->assertSame('legacy_product', $mapping->source_scope_type);
            $this->assertSame('9401', $mapping->source_scope_id);
            $this->assertSame(LegacyProtectedAssetMapping::READINESS_NEEDS_MAPPING, $mapping->readiness_status);
            $this->assertSame(LegacyProtectedAssetMapping::VALIDATION_PENDING, $mapping->validation_status);
        }

        $this->assertReportDoesNotLeakRawPayload($syncReport, [
            'Видео',
            'Файл',
            '550001',
            '990001',
            'legacyAssetId',
            'assetKind',
            'legacy_protected_asset_reference',
            $syncReportPath,
        ]);

        $inventoryReportPath = storage_path('app/legacy-protected-assets-sync-chain-inventory-report.json');

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
        $this->assertSame('9401', $inventoryReport['items'][0]['source_scope_id']);
        $this->assertSame(2, $inventoryReport['items'][0]['references_count']);
        $this->assertReportDoesNotLeakRawPayload($inventoryReport, [
            'Видео',
            'Файл',
            '550001',
            '990001',
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

        foreach ([
            'legacy_protected_asset_reference',
            'legacyAssetId',
            'assetKind',
            'legacy_video',
            'legacy_file',
            '550001',
            '990001',
            'legacy_protected_asset_mappings',
            'readiness_status',
        ] as $forbiddenValue) {
            $this->assertStringNotContainsString($forbiddenValue, $response->getContent() ?: '');
        }
    }

    private function createLesson(string $slug): Lesson
    {
        $subject = Subject::query()->create([
            'slug' => 'subject-'.$slug,
            'title' => 'Subject '.$slug,
            'is_active' => true,
        ]);

        $course = Course::query()->create([
            'subject_id' => $subject->id,
            'slug' => 'course-'.$slug,
            'title' => 'Course '.$slug,
            'price_cents' => 100000,
            'is_active' => true,
        ]);

        return Lesson::query()->create([
            'course_id' => $course->id,
            'slug' => $slug,
            'title' => 'Lesson '.$slug,
            'sort_order' => 1,
            'is_active' => true,
        ]);
    }

    private function createLessonWithLegacyPage(int $legacyPageId, ?int $legacyProductId = null): Lesson
    {
        $lesson = $this->createLesson('legacy-'.$legacyPageId);

        $lesson->forceFill([
            'legacy_product_id' => $legacyProductId,
            'metadata' => [
                'legacy_page_id' => $legacyPageId,
            ],
        ])->save();

        return $lesson;
    }

    /**
     * @param  array<int, array<string, mixed>>  $blocks
     */
    private function createContent(
        Lesson $lesson,
        array $blocks,
        string $sourceId = '500',
        string $sourceScopeId = '9000',
    ): LessonLearningContent {
        return LessonLearningContent::query()->create([
            'lesson_id' => $lesson->id,
            'status' => LessonLearningContent::STATUS_DRAFT,
            'format' => LessonLearningContent::FORMAT_LESSON_BLOCKS_V1,
            'version' => 1,
            'body' => [
                'blocks' => $blocks,
                'toc' => [],
            ],
            'source_table' => 'platform_page',
            'source_id' => $sourceId,
            'source_fingerprint_hash' => str_repeat('c', 64),
            'import_status' => LessonLearningContent::IMPORT_STATUS_IMPORTED,
            'metadata' => [
                'legacy_source_scope_type' => 'legacy_product',
                'legacy_source_scope_id' => $sourceScopeId,
            ],
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function writeSourceJson(array $rows): string
    {
        $path = storage_path('app/legacy-lesson-body-sync-chain-source.json');

        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode(['platform_pages' => $rows], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $path;
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

    /**
     * @return array<string, mixed>
     */
    private function readReport(string $path): array
    {
        $this->assertFileExists($path);

        $payload = json_decode((string) File::get($path), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($payload);

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $report
     * @param  array<int, string>  $forbiddenValues
     */
    private function assertReportDoesNotLeakRawPayload(array $report, array $forbiddenValues): void
    {
        $payload = json_encode($report, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        foreach (array_merge($forbiddenValues, [
            '"body"',
            '"blocks"',
            'contentUrl',
            'downloadUrl',
            'fileUrl',
            'mediaUrl',
            'providerId',
            'signedUrl',
            'source_fingerprint_hash',
            'storageKey',
            'videoUrl',
            '{file::',
            '{video::',
        ]) as $forbiddenValue) {
            $this->assertStringNotContainsString($forbiddenValue, $payload);
        }
    }
}
