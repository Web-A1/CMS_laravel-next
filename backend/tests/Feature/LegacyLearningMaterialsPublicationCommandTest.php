<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\LegacyImportMapping;
use App\Models\Lesson;
use App\Models\LessonMaterial;
use App\Models\LessonMaterialFile;
use App\Models\LessonMaterialKinescopeVideo;
use App\Models\Subject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

class LegacyLearningMaterialsPublicationCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_reports_ready_to_publish_for_complete_unpublished_video_and_pdf_without_writes_or_leaks(): void
    {
        $lesson = $this->createLesson();
        $videoItem = $this->videoReportItem($lesson, sortOrder: 1000);
        $pdfItem = $this->pdfReportItem($lesson, sortOrder: 1001);
        $this->createMaterialGraph($lesson, $videoItem);
        $this->createMaterialGraph($lesson, $pdfItem);
        $videoReport = $this->writeSourceReport('platform_page_video_shortcode', [$videoItem]);
        $pdfReport = $this->writeSourceReport('platform_page_file_shortcode', [$pdfItem]);
        $reportPath = storage_path('app/legacy-materials-publication-ready-report.json');

        $this->artisan('legacy:learning:materials:publish', [
            '--source-report' => [$videoReport, $pdfReport],
            '--report' => $reportPath,
        ])
            ->expectsOutputToContain('Dry-run completed: lessons=1 ready_to_publish=1 already_published=0 blocked=0 expected_materials=2')
            ->assertSuccessful();

        $report = $this->readReport($reportPath);
        $encodedReport = json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        $this->assertSame('ready_to_publish', $report['lessons'][0]['verdict']);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $report['plan_sha256']);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $report['lessons'][0]['precondition_sha256']);
        $this->assertSame(['matched', 'matched'], array_column($report['lessons'][0]['materials'], 'child_state'));
        $this->assertStringNotContainsString('legacy-video-a', $encodedReport);
        $this->assertStringNotContainsString('provider-video-a', $encodedReport);
        $this->assertStringNotContainsString('protected-sources/private-note.pdf', $encodedReport);
        $this->assertStringNotContainsString(str_repeat('a', 64), $encodedReport);
        $this->assertFalse((bool) LessonMaterial::query()->where('kind', LessonMaterial::KIND_VIDEO)->sole()->is_published);
        $this->assertFalse((bool) LessonMaterial::query()->where('kind', LessonMaterial::KIND_PDF)->sole()->is_published);
    }

    public function test_dry_run_reports_already_published_when_complete_set_is_published(): void
    {
        $lesson = $this->createLesson();
        $videoItem = $this->videoReportItem($lesson);
        $pdfItem = $this->pdfReportItem($lesson, sortOrder: 1001);
        $this->createMaterialGraph($lesson, $videoItem, published: true);
        $this->createMaterialGraph($lesson, $pdfItem, published: true);
        $videoReport = $this->writeSourceReport('platform_page_video_shortcode', [$videoItem]);
        $pdfReport = $this->writeSourceReport('platform_page_file_shortcode', [$pdfItem]);
        $reportPath = storage_path('app/legacy-materials-publication-published-report.json');

        $this->artisan('legacy:learning:materials:publish', [
            '--source-report' => [$videoReport, $pdfReport],
            '--report' => $reportPath,
        ])
            ->expectsOutputToContain('Dry-run completed: lessons=1 ready_to_publish=0 already_published=1 blocked=0 expected_materials=2')
            ->assertSuccessful();

        $report = $this->readReport($reportPath);

        $this->assertSame('already_published', $report['lessons'][0]['verdict']);
    }

    public function test_dry_run_blocks_missing_child_rows_and_extra_published_materials(): void
    {
        $lesson = $this->createLesson();
        $videoItem = $this->videoReportItem($lesson);
        $pdfItem = $this->pdfReportItem($lesson, sortOrder: 1001);
        $this->createMaterialGraph($lesson, $videoItem);
        $this->createMaterialGraph($lesson, $pdfItem, createChild: false);
        LessonMaterial::query()->create([
            'lesson_id' => $lesson->id,
            'kind' => LessonMaterial::KIND_VIDEO,
            'title' => 'Лишнее видео',
            'sort_order' => 1999,
            'is_published' => true,
        ]);
        $videoReport = $this->writeSourceReport('platform_page_video_shortcode', [$videoItem]);
        $pdfReport = $this->writeSourceReport('platform_page_file_shortcode', [$pdfItem]);
        $reportPath = storage_path('app/legacy-materials-publication-blocked-report.json');

        $this->artisan('legacy:learning:materials:publish', [
            '--source-report' => [$videoReport, $pdfReport],
            '--report' => $reportPath,
        ])
            ->expectsOutputToContain('Dry-run completed: lessons=1 ready_to_publish=0 already_published=0 blocked=1 expected_materials=2')
            ->assertSuccessful();

        $report = $this->readReport($reportPath);

        $this->assertSame('blocked', $report['lessons'][0]['verdict']);
        $this->assertContains('missing_protected_file', $report['lessons'][0]['blocked_reasons']);
        $this->assertContains('extra_published_material', $report['lessons'][0]['blocked_reasons']);
    }

    public function test_dry_run_blocks_pdf_source_object_key_drift(): void
    {
        $lesson = $this->createLesson();
        $pdfItem = [
            ...$this->pdfReportItem($lesson),
            'source_object_key' => 'protected-sources/replaced-private-note.pdf',
        ];
        $this->createMaterialGraph($lesson, $pdfItem);
        $pdfReport = $this->writeSourceReport('platform_page_file_shortcode', [$pdfItem]);
        $reportPath = storage_path('app/legacy-materials-publication-source-key-drift-report.json');

        $this->artisan('legacy:learning:materials:publish', [
            '--source-report' => [$pdfReport],
            '--report' => $reportPath,
        ])
            ->expectsOutputToContain('Dry-run completed: lessons=1 ready_to_publish=0 already_published=0 blocked=1 expected_materials=1')
            ->assertSuccessful();

        $report = $this->readReport($reportPath);

        $this->assertSame('blocked', $report['lessons'][0]['verdict']);
        $this->assertContains('protected_file_source_drift', $report['lessons'][0]['blocked_reasons']);
        $this->assertSame('drift', $report['lessons'][0]['materials'][0]['child_state']);
    }

    public function test_dry_run_reads_pdf_source_object_key_from_metadata_for_legacy_reports(): void
    {
        $lesson = $this->createLesson();
        $pdfItem = $this->pdfReportItem($lesson);
        $this->createMaterialGraph($lesson, $pdfItem);

        $legacyReportItem = $pdfItem;
        $legacyReportItem['metadata']['source_object_key'] = $pdfItem['source_object_key'];
        unset($legacyReportItem['source_object_key']);

        $pdfReport = $this->writeSourceReport('platform_page_file_shortcode', [$legacyReportItem]);
        $reportPath = storage_path('app/legacy-materials-publication-metadata-source-key-report.json');

        $this->artisan('legacy:learning:materials:publish', [
            '--source-report' => [$pdfReport],
            '--report' => $reportPath,
        ])
            ->expectsOutputToContain('Dry-run completed: lessons=1 ready_to_publish=1 already_published=0 blocked=0 expected_materials=1')
            ->assertSuccessful();

        $report = $this->readReport($reportPath);

        $this->assertSame('ready_to_publish', $report['lessons'][0]['verdict']);
        $this->assertSame('matched', $report['lessons'][0]['materials'][0]['child_state']);
    }

    public function test_dry_run_blocks_manual_review_items_and_source_fingerprint_drift(): void
    {
        $lesson = $this->createLesson();
        $videoItem = [
            ...$this->videoReportItem($lesson),
            'manual_review' => true,
            'manual_review_reasons' => ['kinescope_allowlist_missing_pair'],
        ];
        $material = $this->createMaterialGraph($lesson, $videoItem);
        LegacyImportMapping::query()
            ->where('target_id', $material->id)
            ->sole()
            ->forceFill(['source_fingerprint_hash' => hash('sha256', 'stale')])
            ->save();
        $videoReport = $this->writeSourceReport('platform_page_video_shortcode', [$videoItem]);
        $reportPath = storage_path('app/legacy-materials-publication-manual-review-report.json');

        $this->artisan('legacy:learning:materials:publish', [
            '--source-report' => [$videoReport],
            '--report' => $reportPath,
        ])->assertSuccessful();

        $report = $this->readReport($reportPath);

        $this->assertSame('blocked', $report['lessons'][0]['verdict']);
        $this->assertContains('source_item_manual_review', $report['lessons'][0]['blocked_reasons']);
        $this->assertContains('source_fingerprint_drift', $report['lessons'][0]['blocked_reasons']);
    }

    public function test_apply_requires_confirmed_plan_sha256_before_writes(): void
    {
        $lesson = $this->createLesson();
        $videoItem = $this->videoReportItem($lesson);
        $this->createMaterialGraph($lesson, $videoItem);
        $videoReport = $this->writeSourceReport('platform_page_video_shortcode', [$videoItem]);

        $this->artisan('legacy:learning:materials:publish', [
            '--source-report' => [$videoReport],
            '--apply' => true,
        ])
            ->expectsOutputToContain('Field confirm-plan-sha256 must be a lowercase SHA-256 hex string.')
            ->assertFailed();

        $this->assertFalse((bool) LessonMaterial::query()->sole()->is_published);
    }

    public function test_apply_publishes_complete_unpublished_materials_with_hash_bound_report(): void
    {
        $lesson = $this->createLesson();
        $videoItem = $this->videoReportItem($lesson, sortOrder: 1000);
        $pdfItem = $this->pdfReportItem($lesson, sortOrder: 1001);
        $this->createMaterialGraph($lesson, $videoItem);
        $this->createMaterialGraph($lesson, $pdfItem);
        $videoReport = $this->writeSourceReport('platform_page_video_shortcode', [$videoItem]);
        $pdfReport = $this->writeSourceReport('platform_page_file_shortcode', [$pdfItem]);
        $dryRunReportPath = storage_path('app/legacy-materials-publication-apply-dry-run-report.json');
        $applyReportPath = storage_path('app/legacy-materials-publication-apply-report.json');

        $this->artisan('legacy:learning:materials:publish', [
            '--source-report' => [$videoReport, $pdfReport],
            '--report' => $dryRunReportPath,
        ])->assertSuccessful();

        $dryRunReport = $this->readReport($dryRunReportPath);

        $this->artisan('legacy:learning:materials:publish', [
            '--source-report' => [$videoReport, $pdfReport],
            '--apply' => true,
            '--confirm-plan-sha256' => $dryRunReport['plan_sha256'],
            '--report' => $applyReportPath,
        ])
            ->expectsOutputToContain('Apply completed: lessons=1 published_lessons=1 already_published=0 blocked=0 expected_materials=2')
            ->assertSuccessful();

        $report = $this->readReport($applyReportPath);
        $encodedReport = json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        $this->assertSame('apply', $report['mode']);
        $this->assertSame($dryRunReport['plan_sha256'], $report['plan_sha256']);
        $this->assertSame($dryRunReport['plan_sha256'], $report['confirmed_plan_sha256']);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $report['post_apply_plan_sha256']);
        $this->assertSame(1, $report['pre_apply_stats']['ready_to_publish']);
        $this->assertSame(1, $report['stats']['already_published']);
        $this->assertSame(1, $report['apply']['published_lessons']);
        $this->assertSame(2, $report['apply']['materials_published']);
        $this->assertCount(2, $report['apply']['rollback_manifest']['items']);
        $this->assertSame('published', $report['lessons'][0]['materials'][0]['publication_state']);
        $this->assertSame('published', $report['lessons'][0]['materials'][1]['publication_state']);
        $this->assertStringNotContainsString('legacy-video-a', $encodedReport);
        $this->assertStringNotContainsString('provider-video-a', $encodedReport);
        $this->assertStringNotContainsString('protected-sources/private-note.pdf', $encodedReport);
        $this->assertSame(0, LessonMaterial::query()->where('is_published', false)->count());
    }

    public function test_apply_rejects_stale_plan_sha256_before_publication(): void
    {
        $lesson = $this->createLesson();
        $videoItem = $this->videoReportItem($lesson);
        $this->createMaterialGraph($lesson, $videoItem);
        $videoReport = $this->writeSourceReport('platform_page_video_shortcode', [$videoItem]);
        $dryRunReportPath = storage_path('app/legacy-materials-publication-stale-dry-run-report.json');

        $this->artisan('legacy:learning:materials:publish', [
            '--source-report' => [$videoReport],
            '--report' => $dryRunReportPath,
        ])->assertSuccessful();

        $dryRunReport = $this->readReport($dryRunReportPath);
        LessonMaterial::query()->sole()->update(['title' => 'Измененный заголовок']);

        $this->artisan('legacy:learning:materials:publish', [
            '--source-report' => [$videoReport],
            '--apply' => true,
            '--confirm-plan-sha256' => $dryRunReport['plan_sha256'],
        ])
            ->expectsOutputToContain('Confirmed plan SHA-256 does not match the current dry-run plan. Re-run dry-run and use the fresh plan_sha256.')
            ->assertFailed();

        $this->assertFalse((bool) LessonMaterial::query()->sole()->is_published);
    }

    public function test_apply_rolls_back_all_lessons_when_late_precondition_drift_is_detected(): void
    {
        $firstLesson = $this->createLesson();
        $secondLesson = $this->createLesson('anatomy-2', 'osteology-2', 'bones-2');
        $firstVideoItem = $this->videoReportItem($firstLesson, sortOrder: 1000);
        $secondVideoItem = $this->videoReportItem($secondLesson, sortOrder: 1001);
        $firstMaterial = $this->createMaterialGraph($firstLesson, $firstVideoItem);
        $secondMaterial = $this->createMaterialGraph($secondLesson, $secondVideoItem);
        $videoReport = $this->writeSourceReport('platform_page_video_shortcode', [
            $firstVideoItem,
            $secondVideoItem,
        ]);
        $dryRunReportPath = storage_path('app/legacy-materials-publication-atomic-dry-run-report.json');

        $this->artisan('legacy:learning:materials:publish', [
            '--source-report' => [$videoReport],
            '--report' => $dryRunReportPath,
        ])->assertSuccessful();

        $dryRunReport = $this->readReport($dryRunReportPath);
        $drifted = false;
        DB::listen(function (object $query) use (&$drifted, $firstMaterial, $secondMaterial): void {
            if (
                $drifted
                || ! is_string($query->sql)
                || ! str_starts_with($query->sql, 'update "lesson_materials"')
                || ! in_array((int) $firstMaterial->id, array_map('intval', $query->bindings), true)
            ) {
                return;
            }

            $drifted = true;
            LessonMaterial::query()
                ->whereKey($secondMaterial->id)
                ->update(['title' => 'Late drift title']);
        });

        $this->artisan('legacy:learning:materials:publish', [
            '--source-report' => [$videoReport],
            '--apply' => true,
            '--confirm-plan-sha256' => $dryRunReport['plan_sha256'],
        ])
            ->expectsOutputToContain("Publication precondition drift for lesson {$secondLesson->id}. Re-run dry-run before apply.")
            ->assertFailed();

        $this->assertTrue($drifted);
        $this->assertSame(0, LessonMaterial::query()->where('is_published', true)->count());
        $this->assertSame($secondVideoItem['title'], $secondMaterial->refresh()->title);
    }

    private function createLesson(
        string $subjectSlug = 'anatomy',
        string $courseSlug = 'osteology',
        string $lessonSlug = 'bones',
    ): Lesson {
        $subject = Subject::query()->create([
            'slug' => $subjectSlug,
            'title' => 'Анатомия',
        ]);
        $course = Course::query()->create([
            'subject_id' => $subject->id,
            'slug' => $courseSlug,
            'title' => 'Остеология',
            'price_cents' => 100000,
        ]);

        return Lesson::query()->create([
            'course_id' => $course->id,
            'slug' => $lessonSlug,
            'title' => 'Кости',
        ]);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function createMaterialGraph(
        Lesson $lesson,
        array $item,
        bool $published = false,
        bool $createChild = true,
    ): LessonMaterial {
        $material = LessonMaterial::query()->create([
            'lesson_id' => $lesson->id,
            'kind' => $item['kind'],
            'title' => $item['title'],
            'sort_order' => $item['sort_order'],
            'is_published' => $published,
        ]);

        if ($createChild && $item['kind'] === LessonMaterial::KIND_VIDEO) {
            LessonMaterialKinescopeVideo::query()->create([
                'lesson_material_id' => $material->id,
                'embed_locator' => $item['test_embed_locator'],
                'provider_video_id' => $item['test_provider_video_id'],
            ]);
        }
        if ($createChild && $item['kind'] === LessonMaterial::KIND_PDF) {
            LessonMaterialFile::query()->create([
                'lesson_material_id' => $material->id,
                'source_object_key' => 'protected-sources/private-note.pdf',
                'source_sha256' => $item['source_sha256'],
                'source_size_bytes' => $item['source_size_bytes'],
                'source_mime_type' => $item['source_mime_type'],
                'download_filename' => $item['download_filename'],
            ]);
        }

        LegacyImportMapping::query()->create([
            'batch_key' => $item['kind'] === LessonMaterial::KIND_PDF
                ? 'lesson_file_material_v1'
                : 'lesson_video_material_v1',
            'source_table' => $item['kind'] === LessonMaterial::KIND_PDF
                ? 'platform_page_file_shortcode'
                : 'platform_page_video_shortcode',
            'source_id' => $item['import_source_key'],
            'source_scope_type' => $item['source_scope_type'],
            'source_scope_id' => $item['source_scope_id'],
            'target_domain' => 'learning_lesson_material',
            'target_type' => 'lesson_material',
            'target_id' => $material->id,
            'mapping_status' => LegacyImportMapping::MAPPING_MAPPED,
            'validation_status' => LegacyImportMapping::VALIDATION_PENDING,
            'idempotency_key' => hash('sha256', 'mapping-'.$item['metadata']['dry_run_occurrence_key']),
            'source_fingerprint_hash' => $item['source_fingerprint_hash'],
            'metadata' => [
                'dry_run_occurrence_key' => $item['metadata']['dry_run_occurrence_key'],
            ],
        ]);

        return $material;
    }

    /**
     * @return array<string, mixed>
     */
    private function videoReportItem(Lesson $lesson, int $sortOrder = 1000): array
    {
        $embedLocator = 'legacy-video-a';
        $providerVideoId = 'provider-video-a';
        $occurrenceKey = hash('sha256', 'video-occurrence-'.$sortOrder);

        return [
            'kind' => LessonMaterial::KIND_VIDEO,
            'lesson_id' => $lesson->id,
            'import_source_key' => hash('sha256', 'video-source-'.$sortOrder),
            'source_scope_type' => LegacyImportMapping::SOURCE_SCOPE_ID_ONLY,
            'source_scope_id' => LegacyImportMapping::SOURCE_SCOPE_ID_ONLY,
            'title' => 'Видео к уроку',
            'sort_order' => $sortOrder,
            'source_fingerprint_hash' => hash('sha256', 'video-fingerprint-'.$sortOrder),
            'manual_review' => false,
            'manual_review_reasons' => [],
            'kinescope_pair_sha256' => $this->pairSha256($embedLocator, $providerVideoId),
            'metadata' => [
                'dry_run_occurrence_key' => $occurrenceKey,
            ],
            'test_embed_locator' => $embedLocator,
            'test_provider_video_id' => $providerVideoId,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function pdfReportItem(Lesson $lesson, int $sortOrder = 1000): array
    {
        return [
            'kind' => LessonMaterial::KIND_PDF,
            'lesson_id' => $lesson->id,
            'import_source_key' => hash('sha256', 'pdf-source-'.$sortOrder),
            'source_scope_type' => LegacyImportMapping::SOURCE_SCOPE_ID_ONLY,
            'source_scope_id' => LegacyImportMapping::SOURCE_SCOPE_ID_ONLY,
            'title' => 'Конспект к уроку',
            'sort_order' => $sortOrder,
            'source_fingerprint_hash' => hash('sha256', 'pdf-fingerprint-'.$sortOrder),
            'manual_review' => false,
            'manual_review_reasons' => [],
            'source_sha256' => str_repeat('a', 64),
            'source_size_bytes' => 512,
            'source_mime_type' => 'application/pdf',
            'download_filename' => 'konspekt.pdf',
            'source_object_key' => 'protected-sources/private-note.pdf',
            'metadata' => [
                'dry_run_occurrence_key' => hash('sha256', 'pdf-occurrence-'.$sortOrder),
            ],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    private function writeSourceReport(string $sourceTable, array $items): string
    {
        $items = array_map(function (array $item): array {
            unset($item['test_embed_locator'], $item['test_provider_video_id']);

            return $item;
        }, $items);
        $path = storage_path('app/legacy-materials-publication-source-'.Str::uuid().'.json');
        File::put($path, json_encode([
            'mode' => 'dry-run',
            'source_table' => $sourceTable,
            'items' => $items,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $canonicalPath = realpath($path);
        $this->assertIsString($canonicalPath);

        return $canonicalPath;
    }

    private function pairSha256(string $embedLocator, string $providerVideoId): string
    {
        return hash('sha256', json_encode([
            'provider' => 'kinescope',
            'embed_locator' => $embedLocator,
            'provider_video_id' => $providerVideoId,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @return array<string, mixed>
     */
    private function readReport(string $path): array
    {
        $report = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($report);

        return $report;
    }
}
