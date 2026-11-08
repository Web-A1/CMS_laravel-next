<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Lesson;
use App\Models\LessonAssessment;
use App\Models\LessonAssessmentAsset;
use App\Models\LessonAssessmentVersion;
use App\Models\Subject;
use App\Services\Learning\LessonAssessmentContentValidator;
use App\Services\Learning\LessonAssessmentPublisher;
use Database\Seeders\LessonAssessmentSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\TestCase;

#[Group('pgsql')]
class LessonAssessmentPublisherPostgresTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('pgsql', DB::connection()->getDriverName());
        $this->assertSame('cms_test', DB::connection()->getDatabaseName());

        config()->set('protected_files.source_disk', 'lesson-assessment-pgsql-assets');
        config()->set('filesystems.disks.lesson-assessment-pgsql-assets', [
            'driver' => 'local',
            'root' => storage_path('framework/testing/lesson-assessment-pgsql-assets'),
            'throw' => true,
        ]);
        Storage::fake('lesson-assessment-pgsql-assets');
    }

    public function test_postgresql_jsonb_round_trip_with_reordered_objects_publishes_the_canonical_draft(): void
    {
        $fixture = LessonAssessmentSeeder::validatedFixture();
        $draft = $this->createDraft('pgsql-jsonb-roundtrip', $this->reverseObjectKeys($fixture));
        $draft->refresh();

        app(LessonAssessmentPublisher::class)->publish($draft, $fixture);

        $draft->refresh();
        $this->assertSame(LessonAssessmentVersion::STATUS_PUBLISHED, $draft->status);
        $this->assertEquals($fixture['sections'], $draft->sections);
        $this->assertDatabaseCount('lesson_assessment_assets', 9);
    }

    public function test_postgresql_registry_write_failure_rolls_back_retirement_version_state_and_assets(): void
    {
        $fixture = LessonAssessmentSeeder::validatedFixture();
        $draft = $this->createDraft('pgsql-registry-rollback', $fixture);
        $previousPublished = $this->createPreviousPublishedVersion($draft->assessment);
        $function = 'lesson_assessment_registry_failure';
        $trigger = 'lesson_assessment_registry_failure_trigger';

        DB::unprepared(<<<SQL
            CREATE FUNCTION {$function}() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'lesson assessment registry failure';
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER {$trigger}
            BEFORE INSERT OR UPDATE ON lesson_assessment_assets
            FOR EACH ROW EXECUTE FUNCTION {$function}();
            SQL);

        try {
            app(LessonAssessmentPublisher::class)->publish($draft, $fixture);
            $this->fail('The registry write failure did not abort publication.');
        } catch (QueryException) {
        } finally {
            DB::unprepared("DROP TRIGGER IF EXISTS {$trigger} ON lesson_assessment_assets");
            DB::unprepared("DROP FUNCTION IF EXISTS {$function}()");
        }

        $draft->refresh();
        $previousPublished->refresh();
        $this->assertSame(LessonAssessmentVersion::STATUS_DRAFT, $draft->status);
        $this->assertSame(LessonAssessmentVersion::STATUS_PUBLISHED, $previousPublished->status);
        $this->assertSame(0, $draft->assets()->count());
        $this->assertCount(9, Storage::disk('lesson-assessment-pgsql-assets')->allFiles());
    }

    public function test_competing_postgresql_publication_processes_preserve_the_exactly_one_published_invariant(): void
    {
        $this->requireConcurrentProcessSupport();

        $fixtureV1 = LessonAssessmentSeeder::validatedFixture();
        $fixtureV2 = $fixtureV1;
        $fixtureV2['contentVersion'] = 2;
        $fixtureV2 = app(LessonAssessmentContentValidator::class)->validate($fixtureV2);
        $assessment = $this->createAssessment('pgsql-concurrent-publication');
        $draftV1 = $this->createDraftForAssessment($assessment, $fixtureV1);
        $draftV2 = $this->createDraftForAssessment($assessment, $fixtureV2);
        $this->prepopulateAssets($draftV1, $fixtureV1);
        $this->prepopulateAssets($draftV2, $fixtureV2);
        $function = 'lesson_assessment_publish_delay';
        $trigger = 'lesson_assessment_publish_delay_trigger';
        DB::unprepared(<<<SQL
            CREATE FUNCTION {$function}() RETURNS trigger AS $$
            BEGIN
                IF NEW.status = 'published' THEN
                    PERFORM pg_sleep(0.5);
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER {$trigger}
            BEFORE UPDATE OF status ON lesson_assessment_versions
            FOR EACH ROW EXECUTE FUNCTION {$function}();
            SQL);

        try {
            $result = $this->publishConcurrently([
                ['versionId' => $draftV1->id, 'fixture' => $fixtureV1],
                ['versionId' => $draftV2->id, 'fixture' => $fixtureV2],
            ]);
        } finally {
            DB::unprepared("DROP TRIGGER IF EXISTS {$trigger} ON lesson_assessment_versions");
            DB::unprepared("DROP FUNCTION IF EXISTS {$function}()");
        }

        $draftV1->refresh();
        $draftV2->refresh();
        $this->assertSame([0, 0], $result['statuses']);
        $this->assertTrue($result['overlapObserved'], 'The competing PostgreSQL publication attempts did not overlap on a row lock.');
        $this->assertSame(1, LessonAssessmentVersion::query()
            ->where('lesson_assessment_id', $assessment->id)
            ->where('status', LessonAssessmentVersion::STATUS_PUBLISHED)
            ->count());
        $this->assertSameCanonicalStatuses($draftV1, $draftV2);
        $this->assertExactRegistry($draftV1, $fixtureV1);
        $this->assertExactRegistry($draftV2, $fixtureV2);
    }

    private function createDraft(string $slug, array $fixture): LessonAssessmentVersion
    {
        return $this->createDraftForAssessment($this->createAssessment($slug), $fixture);
    }

    private function createAssessment(string $slug): LessonAssessment
    {
        $slug .= '-'.bin2hex(random_bytes(4));
        $subject = Subject::query()->create([
            'slug' => "{$slug}-subject",
            'title' => 'PostgreSQL subject',
            'sort_order' => 1,
            'is_active' => true,
        ]);
        $course = Course::query()->create([
            'subject_id' => $subject->id,
            'slug' => "{$slug}-course",
            'title' => 'PostgreSQL course',
            'price_cents' => 0,
            'sort_order' => 1,
            'is_active' => true,
        ]);
        $lesson = Lesson::query()->create([
            'course_id' => $course->id,
            'slug' => "{$slug}-lesson",
            'title' => 'PostgreSQL lesson',
            'sort_order' => 1,
            'is_active' => true,
            'is_free_preview' => false,
        ]);

        return LessonAssessment::query()->create(['lesson_id' => $lesson->id]);
    }

    private function createDraftForAssessment(LessonAssessment $assessment, array $fixture): LessonAssessmentVersion
    {
        $draft = new LessonAssessmentVersion([
            'lesson_assessment_id' => $assessment->id,
            'schema_version' => $fixture['schemaVersion'],
            'content_version' => $fixture['contentVersion'],
            'scoring_policy' => $fixture['scoringPolicy'],
            'source_assignment_sha256' => $fixture['sources']['assignmentSha256'],
            'source_key_sha256' => $fixture['sources']['keySha256'],
            'sections' => $fixture['sections'],
            'questions' => $fixture['questions'],
            'answer_key' => $fixture['answerKey'],
        ]);
        $draft->status = LessonAssessmentVersion::STATUS_DRAFT;
        $draft->save();

        return $draft;
    }

    private function createPreviousPublishedVersion(LessonAssessment $assessment): LessonAssessmentVersion
    {
        $version = new LessonAssessmentVersion([
            'lesson_assessment_id' => $assessment->id,
            'schema_version' => 2,
            'content_version' => 2,
            'scoring_policy' => LessonAssessmentSeeder::validatedFixture()['scoringPolicy'],
            'source_assignment_sha256' => str_repeat('c', 64),
            'source_key_sha256' => str_repeat('d', 64),
            'sections' => [['id' => 'previous', 'title' => 'Previous published snapshot']],
            'questions' => [],
            'answer_key' => ['previous' => ['response' => [], 'source' => ['page' => 1, 'item' => '1']]],
            'published_at' => now(),
        ]);
        $version->status = LessonAssessmentVersion::STATUS_PUBLISHED;
        $version->save();

        return $version;
    }

    /** @param array<string, mixed> $value */
    private function reverseObjectKeys(array $value): array
    {
        if (array_is_list($value)) {
            return array_map(
                fn (mixed $item): mixed => is_array($item) ? $this->reverseObjectKeys($item) : $item,
                $value,
            );
        }

        $reversed = [];
        foreach (array_reverse($value, true) as $key => $item) {
            $reversed[$key] = is_array($item) ? $this->reverseObjectKeys($item) : $item;
        }

        return $reversed;
    }

    private function requireConcurrentProcessSupport(): void
    {
        $requirements = [
            'ext-pcntl' => extension_loaded('pcntl'),
            'pcntl_fork' => is_callable('pcntl_fork'),
            'pcntl_waitpid' => is_callable('pcntl_waitpid'),
            'pcntl_wifexited' => is_callable('pcntl_wifexited'),
            'pcntl_wexitstatus' => is_callable('pcntl_wexitstatus'),
            'ext-posix' => extension_loaded('posix'),
            'posix_kill' => is_callable('posix_kill'),
        ];
        $missingRequirements = array_keys(array_filter(
            $requirements,
            fn (bool $available): bool => ! $available,
        ));

        if ($missingRequirements !== []) {
            $this->markTestSkipped(
                'Concurrent PostgreSQL publication test requires '.implode(', ', $missingRequirements).'.',
            );
        }
    }

    /**
     * @param  list<array{versionId: int, fixture: array<string, mixed>}>  $attempts
     * @return array{statuses: list<int>, overlapObserved: bool}
     */
    private function publishConcurrently(array $attempts): array
    {
        $children = [];
        $overlapObserved = false;
        $outcomes = [];

        try {
            foreach ($attempts as $attempt) {
                [$parentSocket, $childSocket] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
                $pid = pcntl_fork();
                if ($pid === -1) {
                    fclose($parentSocket);
                    fclose($childSocket);
                    throw new RuntimeException('Unable to fork the PostgreSQL publication process.');
                }

                if ($pid === 0) {
                    foreach ($children as $child) {
                        fclose($child['socket']);
                    }
                    fclose($parentSocket);
                    stream_get_contents($childSocket, 1);
                    DB::disconnect();
                    DB::reconnect();

                    try {
                        app(LessonAssessmentPublisher::class)->publish(
                            LessonAssessmentVersion::query()->findOrFail($attempt['versionId']),
                            $attempt['fixture'],
                        );
                        fwrite($childSocket, '0');
                        fclose($childSocket);
                        exit(0);
                    } catch (\Throwable $exception) {
                        fwrite($childSocket, '1'.get_class($exception).': '.$exception->getMessage());
                        fclose($childSocket);
                        exit(1);
                    }
                }

                fclose($childSocket);
                $children[] = [
                    'pid' => $pid,
                    'socket' => $parentSocket,
                    'status' => null,
                    'unavailable' => false,
                ];
            }

            foreach ($children as $child) {
                fwrite($child['socket'], 'g');
            }

            $overlapObserved = $this->observeConcurrentAssessmentLock();
        } finally {
            $outcomes = $this->settleChildren($children);
        }

        $failures = array_filter($outcomes, fn (array $outcome): bool => $outcome['exitCode'] !== 0 || $outcome['message'] !== '0');
        if ($failures !== []) {
            throw new RuntimeException('A PostgreSQL publication process failed after all child outcomes were collected.');
        }

        $statuses = array_column($outcomes, 'exitCode');
        sort($statuses);

        return [
            'statuses' => $statuses,
            'overlapObserved' => $overlapObserved,
        ];
    }

    /**
     * @param  list<array{pid: int, socket: resource, status: int|null, unavailable: bool}>  $children
     * @return list<array{exitCode: int|null, message: string}>
     */
    private function settleChildren(array &$children): array
    {
        $this->reapChildrenUntil($children, microtime(true) + 3);
        $this->signalUnreapedChildren($children, SIGTERM);
        $this->reapChildrenUntil($children, microtime(true) + 0.5);
        $this->signalUnreapedChildren($children, SIGKILL);
        $this->reapChildrenUntil($children, microtime(true) + 0.5);

        $outcomes = [];
        foreach ($children as $child) {
            $message = '';
            if (is_resource($child['socket'])) {
                stream_set_blocking($child['socket'], false);
                $message = (string) stream_get_contents($child['socket']);
                fclose($child['socket']);
            }

            $outcomes[] = [
                'exitCode' => $child['status'] !== null && pcntl_wifexited($child['status'])
                    ? pcntl_wexitstatus($child['status'])
                    : null,
                'message' => $message,
            ];
        }

        return $outcomes;
    }

    /** @param list<array{pid: int, socket: resource, status: int|null, unavailable: bool}> $children */
    private function reapChildrenUntil(array &$children, float $deadline): void
    {
        while (microtime(true) < $deadline && ! $this->allChildrenSettled($children)) {
            foreach ($children as &$child) {
                if ($child['status'] !== null || $child['unavailable']) {
                    continue;
                }

                $waited = pcntl_waitpid($child['pid'], $status, WNOHANG);
                if ($waited === $child['pid']) {
                    $child['status'] = $status;
                } elseif ($waited === -1) {
                    $child['unavailable'] = true;
                }
            }
            unset($child);

            if (! $this->allChildrenSettled($children)) {
                usleep(10_000);
            }
        }
    }

    /** @param list<array{pid: int, socket: resource, status: int|null, unavailable: bool}> $children */
    private function signalUnreapedChildren(array $children, int $signal): void
    {
        foreach ($children as $child) {
            if ($child['status'] === null && ! $child['unavailable']) {
                posix_kill($child['pid'], $signal);
            }
        }
    }

    /** @param list<array{pid: int, socket: resource, status: int|null, unavailable: bool}> $children */
    private function allChildrenSettled(array $children): bool
    {
        foreach ($children as $child) {
            if ($child['status'] === null && ! $child['unavailable']) {
                return false;
            }
        }

        return true;
    }

    /** @param array{contentVersion: int, assets: list<array{sourcePath: string, sha256: string}>} $fixture */
    private function prepopulateAssets(LessonAssessmentVersion $version, array $fixture): void
    {
        foreach ($fixture['assets'] as $asset) {
            $source = database_path('seeders/assets/'.$asset['sourcePath']);
            $objectKey = sprintf(
                'lesson-assessments/%d/v%d/%s.%s',
                $version->lesson_assessment_id,
                $fixture['contentVersion'],
                $asset['sha256'],
                pathinfo($asset['sourcePath'], PATHINFO_EXTENSION),
            );
            Storage::disk('lesson-assessment-pgsql-assets')->put($objectKey, file_get_contents($source));
        }
    }

    private function observeConcurrentAssessmentLock(): bool
    {
        $deadline = microtime(true) + 2;

        while (microtime(true) < $deadline) {
            $waiter = DB::selectOne(<<<'SQL'
                SELECT 1 AS locked
                FROM pg_stat_activity
                WHERE datname = current_database()
                  AND pid <> pg_backend_pid()
                  AND wait_event_type = 'Lock'
                  AND query ILIKE '%lesson_assessment%'
                LIMIT 1
                SQL);

            if ($waiter !== null) {
                return true;
            }

            usleep(10_000);
        }

        return false;
    }

    private function assertSameCanonicalStatuses(
        LessonAssessmentVersion $first,
        LessonAssessmentVersion $second,
    ): void {
        $this->assertContains($first->status, [
            LessonAssessmentVersion::STATUS_PUBLISHED,
            LessonAssessmentVersion::STATUS_RETIRED,
        ]);
        $this->assertContains($second->status, [
            LessonAssessmentVersion::STATUS_PUBLISHED,
            LessonAssessmentVersion::STATUS_RETIRED,
        ]);
        $this->assertNotSame($first->status, $second->status);
    }

    /**
     * @param  array{contentVersion: int, assets: list<array{assetKey: string, sha256: string, mimeType: string, width: int, height: int}>}  $fixture
     */
    private function assertExactRegistry(LessonAssessmentVersion $version, array $fixture): void
    {
        $assets = $version->assets()->orderBy('asset_key')->get()->keyBy('asset_key');

        $this->assertCount(9, $assets);
        foreach ($fixture['assets'] as $sortOrder => $fixtureAsset) {
            $asset = $assets->get($fixtureAsset['assetKey']);
            $this->assertInstanceOf(LessonAssessmentAsset::class, $asset);
            $this->assertSame('lesson-assessment-pgsql-assets', $asset->disk);
            $extension = match ($fixtureAsset['mimeType']) {
                'image/png' => 'png',
                'image/webp' => 'webp',
            };
            $this->assertSame(
                "lesson-assessments/{$version->lesson_assessment_id}/v{$fixture['contentVersion']}/{$fixtureAsset['sha256']}.{$extension}",
                $asset->object_key,
            );
            $this->assertSame($fixtureAsset['sha256'], $asset->sha256);
            $this->assertSame($fixtureAsset['mimeType'], $asset->mime_type);
            $this->assertSame($fixtureAsset['width'], $asset->width);
            $this->assertSame($fixtureAsset['height'], $asset->height);
            $this->assertSame($sortOrder + 1, $asset->sort_order);
        }
    }
}
