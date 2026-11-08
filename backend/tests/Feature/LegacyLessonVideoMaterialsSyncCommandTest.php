<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\KinescopeLegacyVideoAllowlistBatch;
use App\Models\KinescopeLegacyVideoAllowlistEntry;
use App\Models\LegacyImportMapping;
use App\Models\Lesson;
use App\Models\LessonMaterial;
use App\Models\LessonMaterialKinescopeVideo;
use App\Models\Subject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

class LegacyLessonVideoMaterialsSyncCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_plans_video_materials_without_database_writes_or_raw_locator_leakage(): void
    {
        $lesson = $this->createLesson();
        $this->createActiveAllowlist([
            ['legacy-video-a', 'provider-video-a'],
        ]);
        $sourceReport = $this->writeDryRunReport([
            $this->transferableItem($lesson, platformPageId: 701, videoId: 8801, occurrenceIndex: 0),
        ]);
        $reportPath = storage_path('app/legacy-video-materials-sync-dry-run-report.json');

        $this->artisan('legacy:learning:video-materials:sync', [
            '--source-report' => $sourceReport,
            '--report' => $reportPath,
        ])
            ->expectsOutputToContain('Dry-run completed: considered=1 material_create=1 material_update=0 material_no_change=0 video_create=1 video_update=0 video_no_change=0 manual_review=0')
            ->assertSuccessful();

        $report = $this->readReport($reportPath);
        $item = $report['items'][0];
        $encodedReport = json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        $this->assertSame('dry-run', $report['mode']);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $report['source_report_sha256']);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $report['plan_sha256']);
        $this->assertSame('create', $item['material_action']);
        $this->assertSame('create', $item['video_action']);
        $this->assertSame($this->pairSha256('legacy-video-a', 'provider-video-a'), $item['kinescope_pair_sha256']);
        $this->assertStringNotContainsString('legacy-video-a', $encodedReport);
        $this->assertStringNotContainsString('provider-video-a', $encodedReport);
        $this->assertSame(0, LessonMaterial::query()->count());
        $this->assertSame(0, LessonMaterialKinescopeVideo::query()->count());
        $this->assertSame(0, LegacyImportMapping::query()->where('batch_key', 'lesson_video_material_v1')->count());
    }

    public function test_apply_creates_unpublished_video_material_locator_and_mapping_idempotently(): void
    {
        $lesson = $this->createLesson();
        $this->createActiveAllowlist([
            ['legacy-video-a', 'provider-video-a'],
        ]);
        $sourceReport = $this->writeDryRunReport([
            $this->transferableItem($lesson, platformPageId: 702, videoId: 8802, occurrenceIndex: 2),
        ]);
        $reportPath = storage_path('app/legacy-video-materials-sync-apply-report.json');

        $this->artisan('legacy:learning:video-materials:sync', array_merge([
            '--source-report' => $sourceReport,
            '--apply' => true,
            '--report' => $reportPath,
        ], $this->applyOptions($sourceReport)))
            ->expectsOutputToContain('Apply completed: considered=1 material_create=1 material_update=0 material_no_change=0 video_create=1 video_update=0 video_no_change=0 manual_review=0')
            ->assertSuccessful();

        $material = LessonMaterial::query()->sole();
        $video = LessonMaterialKinescopeVideo::query()->sole();
        $mapping = LegacyImportMapping::query()->where('batch_key', 'lesson_video_material_v1')->sole();

        $this->assertSame($lesson->id, $material->lesson_id);
        $this->assertSame(LessonMaterial::KIND_VIDEO, $material->kind);
        $this->assertSame('Видео к уроку', $material->title);
        $this->assertSame(1002, $material->sort_order);
        $this->assertFalse((bool) $material->is_published);
        $this->assertSame($material->id, $video->lesson_material_id);
        $this->assertSame('legacy-video-a', $video->embed_locator);
        $this->assertSame('provider-video-a', $video->provider_video_id);
        $this->assertSame('platform_page_video_shortcode', $mapping->source_table);
        $this->assertSame('learning_lesson_material', $mapping->target_domain);
        $this->assertSame('lesson_material', $mapping->target_type);
        $this->assertSame($material->id, $mapping->target_id);
        $this->assertSame(LegacyImportMapping::MAPPING_MAPPED, $mapping->mapping_status);
        $this->assertSame($this->pairSha256('legacy-video-a', 'provider-video-a'), $mapping->metadata['kinescope_pair_sha256']);
        $this->assertArrayNotHasKey('embed_locator', $mapping->metadata);
        $this->assertArrayNotHasKey('provider_video_id', $mapping->metadata);

        $this->artisan('legacy:learning:video-materials:sync', array_merge([
            '--source-report' => $sourceReport,
            '--apply' => true,
            '--report' => $reportPath,
        ], $this->applyOptions($sourceReport)))
            ->expectsOutputToContain('Apply completed: considered=1 material_create=0 material_update=0 material_no_change=1 video_create=0 video_update=0 video_no_change=1 manual_review=0')
            ->assertSuccessful();

        $this->assertSame(1, LessonMaterial::query()->count());
        $this->assertSame(1, LessonMaterialKinescopeVideo::query()->count());
        $this->assertSame(1, LegacyImportMapping::query()->where('batch_key', 'lesson_video_material_v1')->count());
    }

    public function test_apply_preserves_repeated_video_occurrences_as_distinct_lesson_materials(): void
    {
        $lesson = $this->createLesson();
        $this->createActiveAllowlist([
            ['legacy-video-a', 'provider-video-a'],
        ]);
        $sourceReport = $this->writeDryRunReport([
            $this->transferableItem($lesson, platformPageId: 703, videoId: 8803, occurrenceIndex: 0),
            $this->transferableItem($lesson, platformPageId: 703, videoId: 8803, occurrenceIndex: 1, byteOffset: 220),
        ]);
        $reportPath = storage_path('app/legacy-video-materials-sync-repeated-report.json');

        $this->artisan('legacy:learning:video-materials:sync', array_merge([
            '--source-report' => $sourceReport,
            '--apply' => true,
            '--report' => $reportPath,
        ], $this->applyOptions($sourceReport)))
            ->expectsOutputToContain('Apply completed: considered=2 material_create=2 material_update=0 material_no_change=0 video_create=2 video_update=0 video_no_change=0 manual_review=0')
            ->assertSuccessful();

        $this->assertSame(2, LessonMaterial::query()->where('kind', LessonMaterial::KIND_VIDEO)->count());
        $this->assertSame(2, LessonMaterialKinescopeVideo::query()
            ->where('embed_locator', 'legacy-video-a')
            ->where('provider_video_id', 'provider-video-a')
            ->count());
        $this->assertSame([1000, 1001], LessonMaterial::query()->orderBy('sort_order')->pluck('sort_order')->all());
        $this->assertSame(2, LegacyImportMapping::query()->where('batch_key', 'lesson_video_material_v1')->count());
    }

    public function test_apply_links_existing_published_video_material_without_creating_duplicate(): void
    {
        $lesson = $this->createLesson(lessonSlug: 'mrt-i-mskt');
        $this->createActiveAllowlist([
            ['legacy-video-a', 'provider-video-a'],
        ]);
        $existingMaterial = LessonMaterial::query()->create([
            'lesson_id' => $lesson->id,
            'kind' => LessonMaterial::KIND_VIDEO,
            'title' => 'Видео к уроку',
            'sort_order' => 0,
            'is_published' => true,
        ]);
        LessonMaterialKinescopeVideo::query()->create([
            'lesson_material_id' => $existingMaterial->id,
            'embed_locator' => 'legacy-video-a',
            'provider_video_id' => 'provider-video-a',
        ]);
        $sourceReport = $this->writeDryRunReport([
            $this->transferableItem($lesson, platformPageId: 506, videoId: 1823, occurrenceIndex: 0, sortOrder: 0),
        ]);
        $reportPath = storage_path('app/legacy-video-materials-sync-existing-published-report.json');

        $this->artisan('legacy:learning:video-materials:sync', array_merge([
            '--source-report' => $sourceReport,
            '--apply' => true,
            '--report' => $reportPath,
        ], $this->applyOptions($sourceReport)))
            ->expectsOutputToContain('Apply completed: considered=1 material_create=0 material_update=0 material_no_change=1 video_create=0 video_update=0 video_no_change=1 manual_review=0')
            ->assertSuccessful();

        $report = $this->readReport($reportPath);
        $mapping = LegacyImportMapping::query()->where('batch_key', 'lesson_video_material_v1')->sole();

        $this->assertSame(1, LessonMaterial::query()->count());
        $this->assertSame(1, LessonMaterialKinescopeVideo::query()->count());
        $this->assertSame($existingMaterial->id, $mapping->target_id);
        $this->assertSame($existingMaterial->id, $report['items'][0]['lesson_material_id']);
        $this->assertSame('no_change', $report['items'][0]['material_action']);
        $this->assertSame('no_change', $report['items'][0]['video_action']);
        $this->assertSame(1, $report['items'][0]['mapping_rows_written']);
        $this->assertTrue((bool) $existingMaterial->refresh()->is_published);
    }

    public function test_allowlist_miss_is_manual_review_and_apply_writes_nothing(): void
    {
        $lesson = $this->createLesson();
        $this->createActiveAllowlist([
            ['another-video', 'another-provider'],
        ]);
        $sourceReport = $this->writeDryRunReport([
            $this->transferableItem($lesson, platformPageId: 704, videoId: 8804, occurrenceIndex: 0),
        ]);
        $reportPath = storage_path('app/legacy-video-materials-sync-allowlist-miss-report.json');

        $this->artisan('legacy:learning:video-materials:sync', array_merge([
            '--source-report' => $sourceReport,
            '--apply' => true,
            '--report' => $reportPath,
        ], $this->applyOptions($sourceReport)))
            ->expectsOutputToContain('Apply completed: considered=1 material_create=0 material_update=0 material_no_change=0 video_create=0 video_update=0 video_no_change=0 manual_review=1')
            ->assertSuccessful();

        $report = $this->readReport($reportPath);

        $this->assertSame(['kinescope_allowlist_missing_pair'], $report['items'][0]['manual_review_reasons']);
        $this->assertSame(0, LessonMaterial::query()->count());
        $this->assertSame(0, LessonMaterialKinescopeVideo::query()->count());
        $this->assertSame(0, LegacyImportMapping::query()->where('batch_key', 'lesson_video_material_v1')->count());
    }

    public function test_apply_requires_matching_source_and_plan_hashes_before_writes(): void
    {
        $lesson = $this->createLesson();
        $this->createActiveAllowlist([
            ['legacy-video-a', 'provider-video-a'],
        ]);
        $sourceReport = $this->writeDryRunReport([
            $this->transferableItem($lesson, platformPageId: 705, videoId: 8805, occurrenceIndex: 0),
        ]);
        $reportPath = storage_path('app/legacy-video-materials-sync-confirmation-required-report.json');

        $this->artisan('legacy:learning:video-materials:sync', [
            '--source-report' => $sourceReport,
            '--apply' => true,
            '--report' => $reportPath,
        ])
            ->expectsOutputToContain('Missing or invalid --confirm-source-sha256')
            ->assertFailed();

        $options = $this->applyOptions($sourceReport);
        $options['--confirm-plan-sha256'] = str_repeat('0', 64);

        $this->artisan('legacy:learning:video-materials:sync', array_merge([
            '--source-report' => $sourceReport,
            '--apply' => true,
            '--report' => $reportPath,
        ], $options))
            ->expectsOutputToContain('Apply confirmation failed: --confirm-plan-sha256 does not match the current dry-run sync plan.')
            ->assertFailed();

        $this->assertSame(0, LessonMaterial::query()->count());
        $this->assertSame(0, LessonMaterialKinescopeVideo::query()->count());
        $this->assertSame(0, LegacyImportMapping::query()->where('batch_key', 'lesson_video_material_v1')->count());
    }

    public function test_occurrence_key_filter_processes_exact_video_subset_in_requested_order(): void
    {
        $lesson = $this->createLesson();
        $this->createActiveAllowlist([
            ['legacy-video-a', 'provider-video-a'],
            ['legacy-video-b', 'provider-video-b'],
        ]);
        $first = $this->transferableItem(
            $lesson,
            platformPageId: 706,
            videoId: 8806,
            occurrenceIndex: 0,
            embedLocator: 'legacy-video-a',
            providerVideoId: 'provider-video-a',
        );
        $second = $this->transferableItem(
            $lesson,
            platformPageId: 706,
            videoId: 8807,
            occurrenceIndex: 1,
            embedLocator: 'legacy-video-b',
            providerVideoId: 'provider-video-b',
        );
        $sourceReport = $this->writeDryRunReport([$first, $second]);
        $reportPath = storage_path('app/legacy-video-materials-sync-occurrence-filter-report.json');

        $this->artisan('legacy:learning:video-materials:sync', [
            '--source-report' => $sourceReport,
            '--occurrence-key' => [$second['occurrence_key'], $first['occurrence_key']],
            '--report' => $reportPath,
        ])
            ->expectsOutputToContain('Dry-run completed: considered=2 material_create=2 material_update=0 material_no_change=0 video_create=2 video_update=0 video_no_change=0 manual_review=0')
            ->assertSuccessful();

        $report = $this->readReport($reportPath);

        $this->assertSame([$second['occurrence_key'], $first['occurrence_key']], $report['selection']['occurrence_keys']);
        $this->assertSame(
            [$second['occurrence_key'], $first['occurrence_key']],
            array_map(fn (array $item): string => $item['metadata']['dry_run_occurrence_key'], $report['items']),
        );
        $this->assertSame(0, LessonMaterial::query()->count());
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
     * @param  array<int, array{0: string, 1: string}>  $pairs
     */
    private function createActiveAllowlist(array $pairs): void
    {
        $batch = KinescopeLegacyVideoAllowlistBatch::query()->create([
            'source_count' => count($pairs),
            'source_sha256' => hash('sha256', json_encode($pairs, JSON_THROW_ON_ERROR)),
            'is_active' => true,
            'activated_at' => now(),
        ]);

        foreach ($pairs as [$embedLocator, $providerVideoId]) {
            KinescopeLegacyVideoAllowlistEntry::query()->create([
                'batch_id' => $batch->id,
                'embed_locator' => $embedLocator,
                'provider_video_id' => $providerVideoId,
            ]);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    private function writeDryRunReport(array $items): string
    {
        $path = storage_path('app/legacy-video-materials-dry-run-'.Str::uuid().'.json');
        File::put($path, json_encode([
            'mode' => 'dry-run',
            'source_table' => 'platform_page',
            'target_tables' => [
                'lesson_materials',
                'lesson_material_kinescope_videos',
            ],
            'stats' => [
                'occurrences' => count($items),
            ],
            'items' => $items,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $canonicalPath = realpath($path);
        $this->assertIsString($canonicalPath);

        return $canonicalPath;
    }

    /**
     * @return array<string, string>
     */
    private function applyOptions(string $sourceReport): array
    {
        $reportPath = storage_path('app/legacy-video-materials-sync-confirm-'.Str::uuid().'.json');

        $this->artisan('legacy:learning:video-materials:sync', [
            '--source-report' => $sourceReport,
            '--report' => $reportPath,
        ])->assertSuccessful();

        $report = $this->readReport($reportPath);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $report['source_report_sha256']);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $report['plan_sha256']);

        return [
            '--confirm-source-sha256' => $report['source_report_sha256'],
            '--confirm-plan-sha256' => $report['plan_sha256'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function transferableItem(
        Lesson $lesson,
        int $platformPageId,
        int $videoId,
        int $occurrenceIndex,
        int $byteOffset = 100,
        ?int $productId = null,
        string $embedLocator = 'legacy-video-a',
        string $providerVideoId = 'provider-video-a',
        ?int $sortOrder = null,
    ): array {
        $item = [
            'occurrence_key' => hash('sha256', "{$platformPageId}:{$videoId}:{$occurrenceIndex}:{$byteOffset}"),
            'platform_page_id' => $platformPageId,
            'product_id' => $productId,
            'occurrence_index' => $occurrenceIndex,
            'byte_offset' => $byteOffset,
            'legacy_video_id' => $videoId,
            'lesson_id' => $lesson->id,
            'subject_slug' => 'anatomy',
            'course_slug' => 'osteology',
            'lesson_slug' => 'bones',
            'title' => 'Видео к уроку',
            'embed_locator' => $embedLocator,
            'provider_video_id' => $providerVideoId,
            'kinescope_pair_sha256' => $this->pairSha256($embedLocator, $providerVideoId),
            'technical_format' => 'kinescope',
            'learning_role' => 'video',
            'lesson_material_kind_candidate' => 'video',
            'result' => 'transferable',
            'reasons' => [],
            'warnings' => [],
            'manual_review' => false,
            'checks' => [
                'lesson_mapping' => 'matched',
                'kinescope_allowlist' => 'matched',
                'provider_identity' => 'matched',
            ],
        ];

        if ($sortOrder !== null) {
            $item['sort_order'] = $sortOrder;
        }

        return $item;
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
