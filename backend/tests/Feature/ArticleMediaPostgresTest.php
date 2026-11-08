<?php

namespace Tests\Feature;

use App\Models\ArticleMediaAsset;
use App\Models\ContentEntry;
use App\Models\User;
use App\Services\Articles\ArticleEditor;
use App\Services\Articles\ArticleImageProcessor;
use App\Services\Articles\ArticleMedia;
use App\Services\Articles\ArticleMediaGuard;
use App\Services\Articles\ArticleMediaLifecycle;
use App\Services\Articles\ArticleMediaVariants;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process;
use Tests\TestCase;

#[Group('pgsql')]
class ArticleMediaPostgresTest extends TestCase
{
    private string $schema;

    private array $children = [];

    private string $source;

    private string $mediaRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $database = getenv('ARTICLE_MEDIA_PG_DATABASE') ?: '';
        $this->assertMatchesRegularExpression('/^cms_article_media_test_[a-f0-9]{16}$/D', $database, 'Only an explicitly created disposable database is permitted.');
        $this->assertTrue(function_exists('pcntl_fork') && function_exists('stream_socket_pair'));
        config(['database.default' => 'pgsql', 'database.connections.pgsql.host' => '127.0.0.1', 'database.connections.pgsql.port' => '5432', 'database.connections.pgsql.database' => $database, 'database.connections.pgsql.url' => null, 'database.connections.pgsql.search_path' => 'public']);
        DB::purge('pgsql');
        $this->assertSame($database, DB::selectOne('SELECT current_database() AS name')->name);
        $this->assertSame('article-media-concurrency-fixture', DB::selectOne("SELECT shobj_description(oid,'pg_database') AS provenance FROM pg_database WHERE datname=current_database()")->provenance);
        $this->schema = 'article_fixture_'.bin2hex(random_bytes(8));
        DB::statement('CREATE SCHEMA "'.$this->schema.'"');
        config(['database.connections.pgsql.search_path' => $this->schema]);
        DB::purge('pgsql');
        Artisan::call('migrate', ['--force' => true]);
        Queue::fake();
        $this->mediaRoot = storage_path('framework/testing/article-pg/'.$this->schema);
        Storage::set('public', Storage::build(array_replace(config('filesystems.disks.public'), ['root' => $this->mediaRoot])));
        $this->actingAs(User::factory()->create(['role' => 'admin_full']), 'web');
        $this->source = tempnam(sys_get_temp_dir(), 'article-pg-');
        (new Process(['magick', '-size', '80x40', 'xc:red', 'PNG:'.$this->source]))->mustRun();
    }

    protected function tearDown(): void
    {
        foreach ($this->children as $child) {
            if (pcntl_waitpid($child['pid'], $status, WNOHANG) === 0) {
                posix_kill($child['pid'], SIGTERM);
                pcntl_waitpid($child['pid'], $status);
            }
            if (is_resource($child['socket'])) {
                fclose($child['socket']);
            }
        }
        if (isset($this->schema)) {
            $this->assertMatchesRegularExpression('/^article_fixture_[a-f0-9]{16}$/D', $this->schema);
            DB::purge('pgsql');
            DB::statement('DROP SCHEMA "'.$this->schema.'" CASCADE');
        }
        if (isset($this->source) && is_file($this->source)) {
            unlink($this->source);
        }
        if (isset($this->mediaRoot)) {
            (new Filesystem)->deleteDirectory($this->mediaRoot);
        }
        parent::tearDown();
    }

    public function test_attach_waits_for_physical_delete_then_fails_without_committing_a_broken_reference(): void
    {
        $asset = $this->asset();
        $deletionId = $this->schedule($asset);
        $deleter = $this->pausedDelete($deletionId);
        $this->start($deleter);
        $this->expectSignal($deleter, 'D');
        $attacher = $this->spawn(function () use ($asset) {
            return app(ArticleEditor::class)->save(null, ['title' => 'Concurrent draft', 'slug' => 'concurrent-draft', 'body' => '<p>Text</p>', 'status' => 'draft', 'cover_path' => $asset->key])->id;
        });
        $this->start($attacher);
        $this->assertAdvisoryWaiter($attacher);
        fwrite($deleter['socket'], 'C');
        $this->assertSame('ok', $this->finish($deleter)['status']);
        $result = $this->finish($attacher);
        $this->assertSame(ValidationException::class, $result['exception']);
        $this->assertSame(0, ContentEntry::count());
        $this->assertSame('deleted', $asset->fresh()->status);
        Storage::disk('public')->assertMissing($asset->key);
        app(ArticleMediaLifecycle::class)->delete($deletionId);
        $this->assertSame(1, DB::table('article_media_events')->where('event', 'deleted')->count());
    }

    public function test_partial_attach_waits_for_delete_and_rejects_stale_asset_without_changing_article(): void
    {
        $asset = $this->asset();
        $entry = app(ArticleEditor::class)->save(null, ['title' => 'Partial draft', 'slug' => 'partial-draft', 'body' => '<p>Text</p>', 'status' => 'draft']);
        $deletionId = $this->schedule($asset);
        $deleter = $this->pausedDelete($deletionId);
        $this->start($deleter);
        $this->expectSignal($deleter, 'D');
        $attacher = $this->spawn(fn () => app(ArticleEditor::class)->saveParameters($entry->id, ['cover_path' => $asset->key], 1)->revision);
        $this->start($attacher);
        $this->assertAdvisoryWaiter($attacher);
        fwrite($deleter['socket'], 'C');
        $this->assertSame('ok', $this->finish($deleter)['status']);
        $this->assertSame(ValidationException::class, $this->finish($attacher)['exception']);
        $this->assertNull($entry->fresh()->cover_path);
        $this->assertSame(1, $entry->fresh()->revision);
        $this->assertSame(1, DB::table('article_audit_events')->count());
    }

    public function test_partial_clear_and_competing_writer_share_lock_and_revision(): void
    {
        $asset = $this->asset();
        $entry = app(ArticleEditor::class)->save(null, ['title' => 'Clear draft', 'slug' => 'clear-draft', 'body' => '<p>Text</p>', 'status' => 'draft', 'cover_path' => $asset->key]);
        $first = $this->spawn(function ($socket) use ($entry) {
            return app(ArticleMediaGuard::class)->run(function () use ($socket, $entry) {
                $saved = app(ArticleEditor::class)->saveParameters($entry->id, ['cover_path' => null], 1);
                fwrite($socket, 'S');
                if (fread($socket, 1) !== 'C') {
                    throw new \RuntimeException('Save barrier failed');
                }

                return $saved->revision;
            });
        });
        $this->start($first);
        $this->expectSignal($first, 'S');
        $second = $this->spawn(fn () => app(ArticleEditor::class)->saveParameters($entry->id, ['status' => 'hidden'], 1)->revision);
        $this->start($second);
        $this->assertAdvisoryWaiter($second);
        fwrite($first['socket'], 'C');
        $this->assertSame(['status' => 'ok', 'value' => 2], $this->finish($first));
        $this->assertSame(ValidationException::class, $this->finish($second)['exception']);
        $this->assertSame('draft', $entry->fresh()->status);
        $this->assertNull($entry->fresh()->cover_path);
        $this->assertSame('deleting', $asset->fresh()->status);
        app(ArticleMediaLifecycle::class)->delete(DB::table('article_media_deletions')->sole()->id);
        Storage::disk('public')->assertMissing($asset->key);
    }

    public function test_fresh_upload_waits_for_physical_delete_then_revives_new_generation_once(): void
    {
        $asset = $this->asset();
        $deletionId = $this->schedule($asset);
        $deleter = $this->pausedDelete($deletionId);
        $this->start($deleter);
        $this->expectSignal($deleter, 'D');
        $uploader = $this->spawn(fn () => app(ArticleMedia::class)->ingest($this->source, ['kind' => 'editor', 'actorId' => auth('web')->id()])->generation);
        $this->start($uploader);
        $this->assertAdvisoryWaiter($uploader);
        fwrite($deleter['socket'], 'C');
        $this->assertSame('ok', $this->finish($deleter)['status']);
        $this->assertSame(['status' => 'ok', 'value' => 2], $this->finish($uploader));
        $this->assertSame('active', $asset->fresh()->status);
        $this->assertSame(2, $asset->fresh()->generation);
        app(ArticleMediaLifecycle::class)->delete($deletionId);
        $this->assertSame($asset->sha256, hash('sha256', Storage::disk('public')->get($asset->key)));
    }

    public function test_late_encoder_cannot_publish_over_deleted_and_reuploaded_generation(): void
    {
        $asset = $this->asset();
        $encoder = $this->spawn(function ($socket) use ($asset) {
            $real = app(ArticleImageProcessor::class);
            $proxy = \Mockery::mock($real);
            $proxy->shouldReceive('variants')->once()->andReturnUsing(function (...$arguments) use ($real, $socket) {
                $output = $real->variants(...$arguments);
                fwrite($socket, 'E');
                if (fread($socket, 1) !== 'C') {
                    throw new \RuntimeException('Encoder barrier failed.');
                }

                return $output;
            });
            app()->instance(ArticleImageProcessor::class, $proxy);

            return app(ArticleMediaVariants::class)->process($asset->id, 1);
        });
        $this->start($encoder);
        $this->expectSignal($encoder, 'E');
        $deletionId = $this->schedule($asset);
        app(ArticleMediaLifecycle::class)->delete($deletionId);
        $revived = app(ArticleMedia::class)->ingest($this->source, ['kind' => 'editor', 'actorId' => auth('web')->id()]);
        $this->assertSame(2, $revived->generation);
        $this->assertTrue(app(ArticleMediaVariants::class)->process($asset->id, 2));
        $manifest = $asset->fresh()->variants;
        fwrite($encoder['socket'], 'C');
        $this->assertSame(['status' => 'ok', 'value' => false], $this->finish($encoder));
        $this->assertSame($manifest, $asset->fresh()->variants);
        $this->assertSame(2, $asset->fresh()->generation);
        $this->assertFalse(app(ArticleMediaVariants::class)->process($asset->id, 2));
        app(ArticleMediaVariants::class)->verify($asset->fresh());
        $this->assertSame(1, DB::table('article_media_events')->where('event', 'variants_ready')->count());
    }

    private function asset(): ArticleMediaAsset
    {
        return app(ArticleMedia::class)->ingest($this->source, ['kind' => 'editor', 'actorId' => auth('web')->id()]);
    }

    private function schedule(ArticleMediaAsset $asset): int
    {
        app(ArticleMediaGuard::class)->run(fn () => app(ArticleMediaLifecycle::class)->scheduleUnused([$asset->sha256]));

        return DB::table('article_media_deletions')->where('asset_id', $asset->id)->where('generation', $asset->fresh()->generation)->value('id');
    }

    private function pausedDelete(int $id): array
    {
        return $this->spawn(function ($socket) use ($id) {
            $real = Storage::disk('public');
            $proxy = \Mockery::mock($real);
            $proxy->shouldReceive('delete')->once()->andReturnUsing(function ($path) use ($real, $socket) {
                fwrite($socket, 'D');
                if (fread($socket, 1) !== 'C') {
                    throw new \RuntimeException('Delete barrier failed.');
                }

                return $real->delete($path);
            });
            Storage::set('public', $proxy);
            app(ArticleMediaLifecycle::class)->delete($id);

            return 'deleted';
        });
    }

    private function spawn(callable $work): array
    {
        DB::disconnect('pgsql');
        [$parent,$child] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $name = 'article-race-'.bin2hex(random_bytes(8));
        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new \RuntimeException('Cannot fork test worker.');
        }
        if ($pid === 0) {
            fclose($parent);
            foreach ($this->children as $existing) {
                if (is_resource($existing['socket'])) {
                    fclose($existing['socket']);
                }
            }
            stream_set_timeout($child, 15);
            DB::purge('pgsql');
            DB::selectOne("SELECT set_config('application_name',?,false)", [$name]);
            DB::statement("SET statement_timeout='15s'");
            fwrite($child, 'R');
            try {
                if (fread($child, 1) !== 'G') {
                    throw new \RuntimeException('Start barrier failed.');
                }
                $result = ['status' => 'ok', 'value' => $work($child)];
            } catch (\Throwable $e) {
                $result = ['status' => 'error', 'exception' => $e::class, 'message' => $e->getMessage()];
            }
            fwrite($child, json_encode($result, JSON_THROW_ON_ERROR)."\n");
            fclose($child);
            DB::disconnect('pgsql');
            // Replace the child before PHP shutdown to avoid inherited PHPUnit reporters.
            pcntl_exec('/usr/bin/true');
            exit(0);
        }
        fclose($child);
        stream_set_timeout($parent, 15);
        $entry = ['pid' => $pid, 'socket' => $parent, 'name' => $name];
        $this->children[] = $entry;

        return $entry;
    }

    private function start(array $child): void
    {
        $this->expectSignal($child, 'R');
        fwrite($child['socket'], 'G');
    }

    private function expectSignal(array $child, string $signal): void
    {
        $this->assertSame($signal, fread($child['socket'], 1), 'Concurrent process barrier');
    }

    private function assertAdvisoryWaiter(array $child): void
    {
        $deadline = microtime(true) + 5;
        do {
            DB::select('SELECT pg_stat_clear_snapshot()');
            $waiter = DB::selectOne("SELECT 1 AS found FROM pg_stat_activity WHERE datname=current_database() AND application_name=? AND wait_event_type='Lock' AND query LIKE '%pg_advisory_xact_lock%'", [$child['name']]);
            if ($waiter) {
                break;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        $this->assertNotNull($waiter, 'A real independent PostgreSQL session must wait on the service advisory lock during physical DELETE.');
    }

    private function finish(array $child): array
    {
        $result = fgets($child['socket']);
        $this->assertNotFalse($result, 'Child process must return its observed outcome.');
        pcntl_waitpid($child['pid'], $status);
        $this->assertTrue(pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0);

        return json_decode($result, true, flags: JSON_THROW_ON_ERROR);
    }
}
