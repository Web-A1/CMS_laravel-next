<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Lesson;
use App\Models\LessonLearningContent;
use App\Models\Subject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class LegacyProtectedAssetsReportCommandTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @var array<int, string>
     */
    private const FORBIDDEN_REPORT_FRAGMENTS = [
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
    ];

    public function test_report_aggregates_internal_references_without_raw_asset_details(): void
    {
        $lessonA = $this->createLesson('asset-a');
        $lessonB = $this->createLesson('asset-b');
        $lessonC = $this->createLesson('asset-c');
        $contentA = $this->createContent($lessonA, [
            [
                'type' => 'paragraph',
                'text' => 'Текст перед защищёнными материалами.',
            ],
            [
                'type' => 'legacy_protected_asset_reference',
                'assetKind' => 'legacy_video',
                'legacyAssetId' => 987654,
                'origin' => 'legacy_shortcode',
            ],
            [
                'type' => 'legacy_protected_asset_reference',
                'assetKind' => 'legacy_file',
                'legacyAssetId' => 123456,
                'origin' => 'legacy_shortcode',
            ],
            [
                'type' => 'legacy_protected_asset_reference',
                'assetKind' => 'legacy_video',
                'legacyAssetId' => 987654,
                'origin' => 'legacy_shortcode',
            ],
        ], sourceId: '501', sourceScopeId: '9001');
        $before = $this->contentSnapshot($contentA);
        $this->createContent($lessonB, [
            [
                'type' => 'legacy_protected_asset_reference',
                'assetKind' => 'legacy_file',
                'legacyAssetId' => 654321,
                'origin' => 'legacy_shortcode',
            ],
        ], sourceId: '502', sourceScopeId: '9002');
        $this->createContent($lessonC, [
            [
                'type' => 'legacy_protected_asset_reference',
                'assetKind' => 'legacy_file',
                'legacyAssetId' => 777888,
                'origin' => 'legacy_shortcode',
            ],
        ], status: LessonLearningContent::STATUS_PUBLISHED, importStatus: LessonLearningContent::IMPORT_STATUS_MANUAL);
        $this->createContent($this->createLesson('plain'), [
            [
                'type' => 'paragraph',
                'text' => 'Обычный текст без protected references.',
            ],
        ], importStatus: LessonLearningContent::IMPORT_STATUS_MANUAL);
        $reportPath = storage_path('app/legacy-protected-assets-report.json');

        $this->artisan('legacy:learning:protected-assets:report', [
            '--report' => $reportPath,
        ])
            ->expectsOutputToContain('Protected asset inventory completed: scanned=4 contents_with_references=3 references=5')
            ->assertSuccessful();

        $report = $this->readReport($reportPath);

        $this->assertSame('local-read-only', $report['mode']);
        $this->assertSame('lesson_learning_contents', $report['source_table']);
        $this->assertSame(4, $report['stats']['contents_scanned']);
        $this->assertSame(3, $report['stats']['contents_with_references']);
        $this->assertSame(5, $report['stats']['protected_asset_reference_blocks']);
        $this->assertSame(['legacy_file' => 3, 'legacy_video' => 2, 'unknown' => 0], $report['stats']['protected_asset_reference_blocks_by_kind']);
        $this->assertSame(['draft' => 2, 'published' => 1], $report['stats']['contents_with_references_by_status']);
        $this->assertSame(['imported' => 2, 'manual' => 1], $report['stats']['contents_with_references_by_import_status']);
        $this->assertSame(['legacy_file' => 3, 'legacy_video' => 1, 'unknown' => 0], $report['stats']['contents_with_references_by_kind']);
        $this->assertSame(['legacy_file' => 3, 'legacy_video' => 1, 'unknown' => 0], $report['stats']['distinct_protected_assets_by_kind']);
        $this->assertCount(3, $report['items']);
        $this->assertSame($lessonA->id, $report['items'][0]['lesson_id']);
        $this->assertSame('asset-a', $report['items'][0]['lesson_slug']);
        $this->assertSame('501', $report['items'][0]['source_id']);
        $this->assertSame('legacy_product', $report['items'][0]['source_scope_type']);
        $this->assertSame('9001', $report['items'][0]['source_scope_id']);
        $this->assertSame(3, $report['items'][0]['references_count']);
        $this->assertSame(['legacy_file' => 1, 'legacy_video' => 2, 'unknown' => 0], $report['items'][0]['references_by_kind']);
        $this->assertSame(4, LessonLearningContent::query()->count());
        $this->assertReportDoesNotLeakProtectedPayload($report, [
            'Текст перед защищёнными материалами',
            'Обычный текст без protected references',
            '987654',
            '123456',
            '654321',
            '777888',
            'legacyAssetId',
            'assetKind',
            'legacy_protected_asset_reference',
            'origin',
            $reportPath,
        ]);
        $this->assertSame($before, $this->contentSnapshot($contentA));
    }

    public function test_report_is_read_only_and_handles_empty_inventory(): void
    {
        $content = $this->createContent($this->createLesson('plain-only'), [
            [
                'type' => 'paragraph',
                'text' => 'Только текст.',
            ],
        ]);
        $before = $this->contentSnapshot($content);
        $reportPath = storage_path('app/legacy-protected-assets-empty-report.json');

        $this->artisan('legacy:learning:protected-assets:report', [
            '--report' => $reportPath,
        ])
            ->expectsOutputToContain('Protected asset inventory completed: scanned=1 contents_with_references=0 references=0')
            ->assertSuccessful();

        $report = $this->readReport($reportPath);

        $this->assertSame(1, $report['stats']['contents_scanned']);
        $this->assertSame(0, $report['stats']['contents_with_references']);
        $this->assertSame(0, $report['stats']['protected_asset_reference_blocks']);
        $this->assertSame(['legacy_file' => 0, 'legacy_video' => 0, 'unknown' => 0], $report['stats']['protected_asset_reference_blocks_by_kind']);
        $this->assertSame([], $report['items']);
        $this->assertSame(1, LessonLearningContent::query()->count());
        $this->assertReportDoesNotLeakProtectedPayload($report, [
            'Только текст',
            $reportPath,
        ]);
        $this->assertSame($before, $this->contentSnapshot($content));
    }

    public function test_report_counts_unknown_internal_reference_kind_without_raw_payload(): void
    {
        $this->createContent($this->createLesson('unknown-kind'), [
            [
                'type' => 'legacy_protected_asset_reference',
                'assetKind' => 'legacy_audio',
                'legacyAssetId' => 112233,
                'origin' => 'legacy_shortcode',
            ],
        ]);
        $reportPath = storage_path('app/legacy-protected-assets-unknown-report.json');

        $this->artisan('legacy:learning:protected-assets:report', [
            '--report' => $reportPath,
        ])->assertSuccessful();

        $report = $this->readReport($reportPath);

        $this->assertSame(1, $report['stats']['contents_with_references']);
        $this->assertSame(['legacy_file' => 0, 'legacy_video' => 0, 'unknown' => 1], $report['stats']['protected_asset_reference_blocks_by_kind']);
        $this->assertSame(['legacy_file' => 0, 'legacy_video' => 0, 'unknown' => 1], $report['items'][0]['references_by_kind']);
        $this->assertReportDoesNotLeakProtectedPayload($report, [
            'legacy_audio',
            '112233',
            'legacyAssetId',
            'assetKind',
            'legacy_protected_asset_reference',
            $reportPath,
        ]);
    }

    public function test_stdout_report_does_not_leak_raw_payload(): void
    {
        $this->createContent($this->createLesson('stdout'), [
            [
                'type' => 'paragraph',
                'text' => 'Текст для stdout.',
            ],
            [
                'type' => 'legacy_protected_asset_reference',
                'assetKind' => 'legacy_video',
                'legacyAssetId' => 445566,
                'origin' => 'legacy_shortcode',
            ],
        ]);

        $exitCode = Artisan::call('legacy:learning:protected-assets:report');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Protected asset inventory completed: scanned=1 contents_with_references=1 references=1', $output);
        $this->assertOutputDoesNotLeakProtectedPayload($output, [
            'Текст для stdout',
            '445566',
            'legacyAssetId',
            'assetKind',
            'legacy_protected_asset_reference',
            'origin',
        ]);
    }

    public function test_report_ignores_malformed_body_without_raw_payload(): void
    {
        $lesson = $this->createLesson('malformed');
        $content = LessonLearningContent::query()->create([
            'lesson_id' => $lesson->id,
            'status' => LessonLearningContent::STATUS_DRAFT,
            'format' => LessonLearningContent::FORMAT_LESSON_BLOCKS_V1,
            'version' => 1,
            'body' => [
                'text' => 'Сырой текст без blocks.',
                'signedUrl' => 'https://cdn.example.test/private.mp4',
            ],
            'source_table' => 'platform_page',
            'source_id' => '777',
            'source_fingerprint_hash' => str_repeat('b', 64),
            'import_status' => LessonLearningContent::IMPORT_STATUS_IMPORTED,
            'manual_review_reason' => null,
            'metadata' => [],
        ]);
        $before = $this->contentSnapshot($content);
        $reportPath = storage_path('app/legacy-protected-assets-malformed-report.json');

        $this->artisan('legacy:learning:protected-assets:report', [
            '--report' => $reportPath,
        ])
            ->expectsOutputToContain('Protected asset inventory completed: scanned=1 contents_with_references=0 references=0')
            ->assertSuccessful();

        $report = $this->readReport($reportPath);

        $this->assertSame(1, $report['stats']['contents_scanned']);
        $this->assertSame(0, $report['stats']['contents_with_references']);
        $this->assertSame([], $report['items']);
        $this->assertSame($before, $this->contentSnapshot($content));
        $this->assertReportDoesNotLeakProtectedPayload($report, [
            'Сырой текст без blocks',
            'https://cdn.example.test/private.mp4',
            'signedUrl',
            $reportPath,
        ]);
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

    /**
     * @param  array<int, array<string, mixed>>  $blocks
     */
    private function createContent(
        Lesson $lesson,
        array $blocks,
        string $status = LessonLearningContent::STATUS_DRAFT,
        string $importStatus = LessonLearningContent::IMPORT_STATUS_IMPORTED,
        ?string $sourceId = '500',
        ?string $sourceScopeId = '9000',
    ): LessonLearningContent {
        return LessonLearningContent::query()->create([
            'lesson_id' => $lesson->id,
            'status' => $status,
            'format' => LessonLearningContent::FORMAT_LESSON_BLOCKS_V1,
            'version' => 1,
            'body' => [
                'blocks' => $blocks,
                'toc' => [],
            ],
            'source_table' => 'platform_page',
            'source_id' => $sourceId,
            'source_fingerprint_hash' => str_repeat('a', 64),
            'import_status' => $importStatus,
            'manual_review_reason' => null,
            'metadata' => [
                'legacy_source_scope_type' => 'legacy_product',
                'legacy_source_scope_id' => $sourceScopeId,
            ],
        ]);
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
     * @return array<string, mixed>
     */
    private function contentSnapshot(LessonLearningContent $content): array
    {
        $content = $content->fresh();
        $this->assertInstanceOf(LessonLearningContent::class, $content);

        return [
            'status' => $content->status,
            'format' => $content->format,
            'version' => $content->version,
            'body' => $content->body,
            'published_at' => $content->published_at?->toJSON(),
            'source_table' => $content->source_table,
            'source_id' => $content->source_id,
            'source_fingerprint_hash' => $content->source_fingerprint_hash,
            'import_status' => $content->import_status,
            'manual_review_reason' => $content->manual_review_reason,
            'metadata' => $content->metadata,
            'updated_at' => $content->updated_at?->toJSON(),
        ];
    }

    /**
     * @param  array<string, mixed>  $report
     * @param  array<int, string>  $forbiddenValues
     */
    private function assertReportDoesNotLeakProtectedPayload(array $report, array $forbiddenValues): void
    {
        $payload = json_encode($report, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $this->assertOutputDoesNotLeakProtectedPayload($payload, $forbiddenValues);
    }

    /**
     * @param  array<int, string>  $forbiddenValues
     */
    private function assertOutputDoesNotLeakProtectedPayload(string $payload, array $forbiddenValues): void
    {
        foreach (self::FORBIDDEN_REPORT_FRAGMENTS as $forbiddenFragment) {
            $this->assertStringNotContainsString($forbiddenFragment, $payload);
        }

        foreach ($forbiddenValues as $forbiddenValue) {
            $this->assertStringNotContainsString($forbiddenValue, $payload);
        }
    }
}
