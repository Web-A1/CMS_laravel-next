<?php

namespace Tests\Feature;

use App\Exceptions\AdminReviewException;
use App\Models\AdminOperationEvent;
use App\Models\Review;
use App\Models\ReviewImportBatch;
use App\Models\ReviewModerationEvent;
use App\Models\User;
use App\Services\Reviews\AdminReviewDeletion;
use App\Services\Reviews\AdminReviewService;
use App\Services\Reviews\ExternalReviewImportService;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\TestCase;

#[Group('pgsql')]
class AdminReviewPostgresTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $connection = config('database.connections.pgsql');
        $this->assertSame('pgsql', config('database.default'));
        $this->assertSame('pgsql', $connection['driver'] ?? null);
        $this->assertSame('127.0.0.1', $connection['host'] ?? null);
        $this->assertSame('5432', (string) ($connection['port'] ?? ''));
        $this->assertSame('cms_test', $connection['database'] ?? null);
        $this->assertContains($connection['url'] ?? null, [null, '']);
        $this->assertSame('pgsql', DB::connection()->getDriverName());
        $this->assertSame('cms_test', DB::connection()->getDatabaseName());
        $this->assertSame('cms_test', DB::selectOne('SELECT current_database() AS name')->name);
    }

    public function test_admin_source_check_and_private_json_metadata_replay_on_postgresql(): void
    {
        $actor = $this->actor();
        $input = $this->input();
        $key = bin2hex(random_bytes(16));
        $service = app(AdminReviewService::class);
        $review = $service->execute($actor, 'create', null, $input, $key);
        $this->assertSame('admin', $review->source);
        $event = ReviewModerationEvent::query()->where('actor_user_id', $actor->id)
            ->where('metadata->idempotency_key_hash', hash('sha256', $key))->sole();
        $this->assertSame($review->id, $event->review_id);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $event->metadata['request_hash']);
        $this->assertSame($review->id, $service->execute($actor, 'create', null, $input, $key)->id);
        $this->assertSame(1, $review->moderationEvents()->count());

        try {
            DB::transaction(fn () => DB::table('reviews')->where('id', $review->id)->update(['source' => 'unsupported_admin_source']));
            $this->fail('PostgreSQL must enforce reviews_source_check.');
        } catch (QueryException $exception) {
            $this->assertSame('23514', $exception->errorInfo[0]);
            $this->assertStringContainsString('reviews_source_check', $exception->getMessage());
        }
        $this->assertSame('admin', $review->fresh()->source);
    }

    public function test_manual_screenshot_is_saved_and_unknown_media_types_remain_rejected(): void
    {
        Storage::fake('public');
        $path = UploadedFile::fake()->image('review.png', 100, 200)->store('review-screenshots', 'public');
        $review = app(AdminReviewService::class)->execute($this->actor(), 'create', null,
            $this->input() + ['screenshotPaths' => [$path]], bin2hex(random_bytes(16)));
        $this->assertSame('screenshot', $review->media->sole()->type);
        $this->assertSame('/storage/'.$path, $review->media->sole()->url);
        foreach (['attachment', 'legacy_screenshot'] as $type) {
            $this->assertSame($type, $review->media()->create(['type' => $type, 'url' => '/images/test.png'])->type);
        }
        try {
            DB::transaction(fn () => $review->media()->create(['type' => 'unsupported_type', 'url' => '/images/test.png']));
            $this->fail('Unknown media types must still be rejected.');
        } catch (QueryException $exception) {
            $this->assertSame('23514', $exception->errorInfo[0]);
            $this->assertStringContainsString('review_media_type_check', $exception->getMessage());
        }
    }

    public function test_same_actor_concurrent_create_key_returns_one_review_and_one_event(): void
    {
        $actor = $this->actor();
        $job = ['actorId' => $actor->id, 'operation' => 'create', 'id' => null, 'input' => $this->input(), 'key' => bin2hex(random_bytes(16))];
        $result = $this->concurrently([$job, $job], 'users');
        $this->assertTrue($result['lockObserved'], 'A PostgreSQL user row lock waiter must actually be observed.');
        $this->assertSame(['ok', 'ok'], array_column($result['outcomes'], 'status'));
        $this->assertSame($result['outcomes'][0]['id'], $result['outcomes'][1]['id']);
        $events = ReviewModerationEvent::query()->where('actor_user_id', $actor->id);
        $this->assertSame(1, $events->count());
        $this->assertSame(1, $events->distinct()->count('review_id'));
    }

    public function test_competing_admin_revisions_serialize_and_only_one_update_is_audited(): void
    {
        $first = $this->actor();
        $second = $this->actor();
        $input = $this->input();
        $review = app(AdminReviewService::class)->execute($first, 'create', null, $input, 'create');
        $revision = (int) $review->moderation_events_max_id;
        $jobs = [];
        foreach ([$first, $second] as $index => $actor) {
            $jobs[] = ['actorId' => $actor->id, 'operation' => 'update', 'id' => $review->id,
                'input' => array_replace($input, ['revision' => $revision, 'displayName' => 'Автор '.($index + 1)]), 'key' => 'update'];
        }
        $result = $this->concurrently($jobs, 'reviews');
        $this->assertTrue($result['lockObserved'], 'A PostgreSQL review row lock waiter must actually be observed.');
        $statuses = array_column($result['outcomes'], 'status');
        sort($statuses);
        $this->assertSame(['ADMIN_REVIEW_REVISION_CONFLICT', 'ok'], $statuses);
        $this->assertSame(2, $review->moderationEvents()->count());
        $winner = array_search('ok', array_column($result['outcomes'], 'status'), true);
        $this->assertSame($jobs[$winner]['input']['displayName'], $review->fresh()->display_name);
        $this->assertSame($jobs[$winner]['actorId'], $review->moderationEvents()->latest('id')->first()->actor_user_id);
    }

    public function test_two_admins_cannot_publish_duplicate_drafts_concurrently(): void
    {
        $first = $this->actor();
        $second = $this->actor();
        $input = $this->input();
        $jobs = [];
        $ids = [];
        foreach ([$first, $second] as $actor) {
            $review = app(AdminReviewService::class)->execute($actor, 'create', null, $input, 'create');
            $ids[] = $review->id;
            $jobs[] = ['actorId' => $actor->id, 'operation' => 'publication', 'id' => $review->id,
                'input' => ['action' => 'publish', 'revision' => (int) $review->moderation_events_max_id], 'key' => 'publish'];
        }
        $result = $this->concurrently($jobs);
        $this->assertSame(['ADMIN_REVIEW_DUPLICATE_CONFLICT', 'ADMIN_REVIEW_DUPLICATE_CONFLICT'], array_column($result['outcomes'], 'status'));
        $this->assertSame(0, Review::query()->whereIn('id', $ids)->published()->count());
        $this->assertSame(2, ReviewModerationEvent::query()->whereIn('review_id', $ids)->count());
    }

    private function actor(): User
    {
        return User::factory()->create(['role' => 'admin_full']);
    }

    private function input(): array
    {
        return ['displayName' => 'Тестовый автор', 'isAnonymous' => false,
            'text' => 'PostgreSQL fixture '.bin2hex(random_bytes(64)), 'reviewWrittenAt' => '2026-01-15',
            'scope' => 'all', 'subjectId' => null, 'courseId' => null];
    }

    /** Two independent PostgreSQL sessions, released through a socket barrier. */
    public function test_hard_delete_and_reimport_are_serialized_without_resurrecting_the_review(): void
    {
        $actor = $this->actor();
        $batch = ReviewImportBatch::create(['source_type' => 'legacy_site', 'batch_ref' => 'pg-delete-import-race', 'status' => 'pending']);
        $record = ['source_ref' => 'pg-delete-import-race', 'text' => 'Уникальный текст для гонки удаления и импорта.', 'display_name' => 'Автор', 'confidence' => 'high'];
        $first = app(ExternalReviewImportService::class)->importRecord($batch, $record);
        $id = $first['review_id'];
        $result = $this->concurrently([
            ['actorId' => $actor->id, 'operation' => 'delete', 'id' => $id, 'input' => ['revision' => 0, 'confirmation' => (string) $id], 'key' => 'pg-race-delete'],
            ['actorId' => $actor->id, 'operation' => 'import', 'batchId' => $batch->id, 'record' => $record],
        ], 'advisory');
        $this->assertSame(['ok', 'ok'], array_column($result['outcomes'], 'status'));
        $this->assertTrue($result['lockObserved']);
        $this->assertDatabaseMissing('reviews', ['id' => $id]);
        $this->assertDatabaseMissing('review_sources', ['source_ref' => $record['source_ref']]);
        $this->assertSame(1, AdminOperationEvent::where('operation', 'delete_review')->where('resource_id', $id)->count());
    }

    public function test_concurrent_bulk_retries_delete_one_group_and_return_the_same_receipt(): void
    {
        $actor = $this->actor();
        $items = [];
        foreach (range(1, 2) as $n) {
            $review = app(AdminReviewService::class)->execute($actor, 'create', null, $this->input(), 'pg-bulk-create-'.$n);
            $items[] = ['id' => $review->id, 'revision' => (int) $review->moderation_events_max_id];
        }
        $job = ['actorId' => $actor->id, 'operation' => 'bulk_delete', 'reviews' => $items, 'key' => 'pg-bulk-retry'];
        $result = $this->concurrently([$job, $job], 'advisory');
        $ids = array_column($items, 'id');
        $this->assertSame(['ok', 'ok'], array_column($result['outcomes'], 'status'));
        $this->assertTrue($result['lockObserved'], 'Concurrent batches must wait for the deletion/import lock.');
        $this->assertSame([$ids, $ids], array_column($result['outcomes'], 'id'));
        $this->assertSame(0, Review::whereIn('id', $ids)->count());
        $this->assertSame(1, AdminOperationEvent::where('actor_id', $actor->id)->where('operation', 'delete_reviews')->count());
        $this->assertSame(2, AdminOperationEvent::where('operation', 'delete_review')->whereIn('resource_id', $ids)->count());
    }

    public function test_concurrent_bulk_placement_retries_apply_each_review_once(): void
    {
        $actor = $this->actor();
        $items = [];
        foreach (range(1, 2) as $n) {
            $review = app(AdminReviewService::class)->execute($actor, 'create', null, $this->input(), 'pg-place-create-'.$n);
            $items[] = ['id' => $review->id, 'revision' => (int) $review->moderation_events_max_id];
        }
        $job = ['actorId' => $actor->id, 'operation' => 'bulk_placement', 'reviews' => $items, 'key' => 'pg-placement-retry'];
        $result = $this->concurrently([$job, $job], 'users');
        $ids = array_column($items, 'id');
        $this->assertSame(['ok', 'ok'], array_column($result['outcomes'], 'status'));
        $this->assertTrue($result['lockObserved'], 'Concurrent placement must wait for the actor lock.');
        $this->assertSame([$ids, $ids], array_column($result['outcomes'], 'id'));
        $this->assertSame(2, Review::whereIn('id', $ids)->where('show_on_home', true)->count());
        $this->assertSame(2, ReviewModerationEvent::whereIn('review_id', $ids)->where('event_type', 'admin_placement')->count());
        $this->assertSame(1, AdminOperationEvent::where('actor_id', $actor->id)->where('operation', 'place_reviews')->count());
    }

    private function concurrently(array $jobs, ?string $lockedTable = null): array
    {
        foreach (['pcntl_fork', 'pcntl_waitpid', 'pcntl_wifexited', 'pcntl_wexitstatus', 'posix_kill', 'stream_socket_pair'] as $function) {
            if (! is_callable($function)) {
                $this->markTestSkipped('PostgreSQL concurrency test requires '.$function.'.');
            }
        }
        $applicationName = 'admin-review-test-'.bin2hex(random_bytes(8));
        // Do not fork a live PDO connection: each process opens its own session.
        DB::disconnect();
        $children = [];
        $lockObserved = false;
        $outcomes = [];
        try {
            foreach ($jobs as $job) {
                $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
                if ($pair === false) {
                    throw new RuntimeException('Cannot create concurrency barrier.');
                }
                [$parentSocket, $childSocket] = $pair;
                $pid = pcntl_fork();
                if ($pid === -1) {
                    fclose($parentSocket);
                    fclose($childSocket);
                    throw new RuntimeException('Cannot fork PostgreSQL review worker.');
                }
                if ($pid === 0) {
                    foreach ($children as $child) {
                        fclose($child['socket']);
                    }
                    fclose($parentSocket);
                    try {
                        DB::reconnect();
                        DB::selectOne("SELECT set_config('application_name', ?, false)", [$applicationName]);
                        DB::statement("SET statement_timeout = '8s'");
                        DB::statement("SET lock_timeout = '5s'");
                        // Hold the real service transaction after its locks, before its audit insert.
                        ReviewModerationEvent::creating(static fn () => DB::select('SELECT pg_sleep(0.6)'));
                        if (in_array($job['operation'], ['delete', 'import', 'bulk_delete'], true)) {
                            Review::saving(static fn () => DB::select('SELECT pg_sleep(0.6)'));
                        }
                        fwrite($childSocket, 'R');
                        stream_set_timeout($childSocket, 5);
                        if (fread($childSocket, 1) !== 'G') {
                            throw new RuntimeException('Concurrency barrier was not released.');
                        }
                        if ($job['operation'] === 'delete') {
                            $deleted = app(AdminReviewDeletion::class)->delete(User::findOrFail($job['actorId']), $job['id'], $job['input'], $job['key']);
                            $id = $deleted['id'];
                        } elseif ($job['operation'] === 'bulk_delete') {
                            $deleted = app(AdminReviewDeletion::class)->deleteMany(User::findOrFail($job['actorId']), $job['reviews'], (string) count($job['reviews']), $job['key']);
                            $id = $deleted['ids'];
                        } elseif ($job['operation'] === 'bulk_placement') {
                            $placed = app(AdminReviewService::class)->placeMany(User::findOrFail($job['actorId']), $job['reviews'], true, $job['key']);
                            $id = $placed['ids'];
                        } elseif ($job['operation'] === 'import') {
                            $import = app(ExternalReviewImportService::class)->importRecord(ReviewImportBatch::findOrFail($job['batchId']), $job['record']);
                            $id = $import['review_id'];
                        } else {
                            $review = app(AdminReviewService::class)->execute(User::query()->findOrFail($job['actorId']), $job['operation'], $job['id'], $job['input'], $job['key']);
                            $id = $review->id;
                        }
                        $outcome = ['status' => 'ok', 'id' => $id];
                    } catch (AdminReviewException $exception) {
                        $outcome = ['status' => $exception->errorCode];
                    } catch (\Throwable $exception) {
                        $outcome = ['status' => 'unexpected', 'exception' => get_class($exception)];
                    }
                    fwrite($childSocket, json_encode($outcome, JSON_THROW_ON_ERROR));
                    fclose($childSocket);
                    DB::disconnect();
                    exit(0);
                }
                fclose($childSocket);
                $children[] = ['pid' => $pid, 'socket' => $parentSocket, 'status' => null];
            }
            foreach ($children as $child) {
                stream_set_timeout($child['socket'], 5);
                $this->assertSame('R', fread($child['socket'], 1), 'Worker must connect before barrier release.');
            }
            foreach ($children as $child) {
                fwrite($child['socket'], 'G');
            }
            DB::reconnect();
            $deadline = microtime(true) + 10;
            do {
                if ($lockedTable !== null && ! $lockObserved) {
                    $lockObserved = DB::selectOne(<<<'SQL'
                        SELECT 1 FROM pg_stat_activity
                        WHERE datname = current_database() AND application_name = ?
                          AND wait_event_type = 'Lock' AND query ILIKE ?
                        LIMIT 1
                        SQL, [$applicationName, $lockedTable === 'advisory' ? '%pg_advisory_xact_lock%' : '%"'.$lockedTable.'"%']) !== null;
                }
                foreach ($children as &$child) {
                    if ($child['status'] === null) {
                        $waited = pcntl_waitpid($child['pid'], $status, WNOHANG);
                        if ($waited === $child['pid']) {
                            $child['status'] = $status;
                        } elseif ($waited === -1) {
                            throw new RuntimeException('PostgreSQL review worker became unavailable.');
                        }
                    }
                }
                unset($child);
                $pending = array_filter($children, fn ($child) => $child['status'] === null);
                if ($pending !== []) {
                    usleep(10_000);
                }
            } while ($pending !== [] && microtime(true) < $deadline);
            foreach ($children as $child) {
                $this->assertNotNull($child['status'], 'PostgreSQL review worker timed out.');
                $this->assertTrue(pcntl_wifexited($child['status']));
                $this->assertSame(0, pcntl_wexitstatus($child['status']));
                stream_set_blocking($child['socket'], false);
                $outcomes[] = json_decode(stream_get_contents($child['socket']), true, flags: JSON_THROW_ON_ERROR);
            }
        } finally {
            foreach ($children as $child) {
                if ($child['status'] === null) {
                    posix_kill($child['pid'], SIGKILL);
                    pcntl_waitpid($child['pid'], $status);
                }
                fclose($child['socket']);
            }
            DB::reconnect();
        }

        return ['outcomes' => $outcomes, 'lockObserved' => $lockObserved];
    }
}
