<?php

namespace Tests\Feature;

use App\Models\AdminOperationEvent;
use App\Models\AdminSupportTicket;
use App\Models\SupportMailboxState;
use App\Models\SupportMailReceipt;
use App\Models\SupportMediaDeletion;
use App\Models\SupportMessage;
use App\Models\User;
use App\Services\Support\SupportMedia;
use App\Services\Support\SupportOperations;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

#[Group('pgsql')]
class SupportPostgresTest extends TestCase
{
    private string $schema;

    private array $children = [];

    protected function setUp(): void
    {
        parent::setUp();
        $database = getenv('SUPPORT_PG_DATABASE') ?: '';
        $this->assertMatchesRegularExpression('/^cms_support_test_[a-f0-9]{16}$/D', $database, 'Only the explicitly created disposable support database may be used.');
        config(['database.default' => 'pgsql', 'database.connections.pgsql.database' => $database, 'database.connections.pgsql.host' => '127.0.0.1', 'database.connections.pgsql.port' => '5432', 'database.connections.pgsql.url' => null, 'database.connections.pgsql.search_path' => 'public']);
        DB::purge('pgsql');
        $this->assertSame($database, DB::selectOne('SELECT current_database() AS name')->name);
        $this->assertSame('support-concurrency-fixture', DB::selectOne("SELECT shobj_description(oid,'pg_database') AS provenance FROM pg_database WHERE datname=current_database()")->provenance);
        $this->schema = 'support_fixture_'.bin2hex(random_bytes(8));
        DB::statement('CREATE SCHEMA "'.$this->schema.'"');
        config(['database.connections.pgsql.search_path' => $this->schema]);
        DB::purge('pgsql');
        Artisan::call('migrate', ['--force' => true]);
        Queue::fake();
        Storage::fake('support-private');
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
            $this->assertMatchesRegularExpression('/^support_fixture_[a-f0-9]{16}$/D', $this->schema);
            DB::purge('pgsql');
            DB::statement('DROP SCHEMA "'.$this->schema.'" CASCADE');
        }
        parent::tearDown();
    }

    public function test_concurrent_guest_key_creates_one_ticket_and_first_message(): void
    {
        $work = fn () => app(SupportOperations::class)->submit('fixture@example.test', 'Concurrent request', 'race-guest-key');
        $first = $this->spawn($work);
        $second = $this->spawn($work);
        $results = $this->startAndFinish($first, $second);
        $this->assertSame('ok', $results[0]['status']);
        $this->assertSame($results[0]['value'], $results[1]['value']);
        $this->assertSame(1, AdminSupportTicket::count());
        $this->assertSame(1, SupportMessage::count());
    }

    public function test_concurrent_reply_key_creates_one_logical_outgoing_and_stale_revision_conflicts(): void
    {
        $operations = app(SupportOperations::class);
        $operations->submit('fixture@example.test', 'Question', 'reply-race-ticket');
        $ticket = AdminSupportTicket::firstOrFail();
        $actor = User::factory()->create(['role' => 'admin_full']);
        $work = fn () => app(SupportOperations::class)->reply($actor, $ticket->id, 'One logical reply', $ticket->revision, 'reply-race-key')->id;
        $results = $this->startAndFinish($this->spawn($work), $this->spawn($work));
        $this->assertSame('ok', $results[0]['status']);
        $this->assertSame($results[0]['value'], $results[1]['value']);
        $this->assertSame(1, SupportMessage::where('direction', 'outbound')->count());
        $this->expectException(HttpException::class);
        $operations->change($actor, $ticket->id, ['revision' => $ticket->revision, 'status' => 'closed'], 'stale-status-key');
    }

    public function test_backfill_keeps_old_tickets_and_audit_and_legacy_description_is_append_only(): void
    {
        $mediaMigration = require database_path('migrations/2026_10_29_180000_track_support_media_lifecycle.php');
        $mediaMigration->down();
        $migration = require database_path('migrations/2026_10_28_120000_extend_support_conversations.php');
        $migration->down();
        $actor = User::factory()->create(['role' => 'admin_full']);
        $id = DB::table('admin_support_tickets')->insertGetId(['subject' => 'Old question', 'description' => 'Legacy body', 'status' => 'pending', 'priority' => 'normal', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('admin_operation_events')->insert(['actor_id' => $actor->id, 'resource' => 'AdminSupportTicket', 'resource_id' => $id, 'operation' => 'create', 'idempotency_key' => 'old-audit-key', 'created_at' => now(), 'updated_at' => now()]);
        $migration->up();
        $mediaMigration->up();
        $this->assertSame('waiting', AdminSupportTicket::findOrFail($id)->status);
        $this->assertDatabaseHas('support_messages', ['ticket_id' => $id, 'body' => 'Legacy body', 'logical_key' => 'backfill:'.$id]);
        $this->assertDatabaseHas('admin_operation_events', ['idempotency_key' => 'old-audit-key', 'actor_id' => $actor->id]);
        Artisan::call('migrate', ['--force' => true]);
        $this->assertSame(1, SupportMessage::where('ticket_id', $id)->count());
        $this->assertNull(AdminSupportTicket::findOrFail($id)->contact_email);
    }

    public function test_additive_intake_migration_preserves_pending_original_and_precise_activation_interval(): void
    {
        $migration = require database_path('migrations/2026_10_28_150000_guard_support_intake_boundaries.php');
        $migration->down();
        $state = SupportMailboxState::create(['mailbox' => 'fixture@example.test', 'folder' => 'INBOX', 'activated_at' => now(), 'baseline_uid' => 100, 'baseline_uidvalidity' => 1, 'uidvalidity' => 1, 'cursor' => 100]);
        $receipt = SupportMailReceipt::create(['mailbox' => 'fixture@example.test', 'folder' => 'INBOX', 'uidvalidity' => 2, 'uid' => 1, 'disk' => 'support-private', 'original_key' => 'mail/preserved.eml', 'outcome' => 'pending']);
        $migration->up();
        $this->assertSame('unclassified', $receipt->fresh()->intake_status);
        $this->assertSame('mail/preserved.eml', $receipt->fresh()->original_key);
        $this->assertSame(100, $state->fresh()->baseline_uid);
        $this->assertNull($state->fresh()->activation_window_started_at);
        $start = Carbon::parse('2026-10-28 12:00:00.123456', 'UTC');
        $finish = Carbon::parse('2026-10-28 12:00:02.654321', 'UTC');
        $state->update(['activation_window_started_at' => $start, 'activation_window_finished_at' => $finish]);
        $this->assertSame('12:00:00.123456', $state->fresh()->activation_window_started_at->format('H:i:s.u'));
        $this->assertSame('12:00:02.654321', $state->fresh()->activation_window_finished_at->format('H:i:s.u'));
        $this->assertSame('pending', $receipt->fresh()->outcome);
        $this->assertSame(0, AdminSupportTicket::count());
    }

    public function test_concurrent_close_has_one_snapshot_and_one_audit_event(): void
    {
        $operations = app(SupportOperations::class);
        $operations->submit('fixture@example.test', 'Close race', 'close-race-ticket');
        $ticket = AdminSupportTicket::sole();
        $actor = User::factory()->create(['role' => 'admin_full']);
        $work = function () use ($actor, $ticket) {
            try {
                return app(SupportOperations::class)->close($actor, $ticket->id, $ticket->revision, 'same-close-key')->status;
            } catch (HttpException $error) {
                return $error->getStatusCode();
            }
        };
        $results = $this->startAndFinish($this->spawn($work), $this->spawn($work));
        $this->assertSame(['ok', 'ok'], array_column($results, 'status'));
        $values = array_column($results, 'value');
        $this->assertContains('closed', $values);
        foreach ($values as $value) {
            $this->assertContains($value, ['closed', 409]);
        }
        $this->assertSame(1, SupportMediaDeletion::count());
        $this->assertSame(1, AdminOperationEvent::where('operation', 'support_close')->count());
        $this->assertSame('closed', $operations->close($actor, $ticket->id, $ticket->revision, 'same-close-key')->status);
    }

    public function test_close_conflicts_with_active_writer_then_tombstone_rejects_late_writer(): void
    {
        $operations = app(SupportOperations::class);
        $operations->submit('fixture@example.test', 'Writer race', 'writer-race-ticket');
        $ticket = AdminSupportTicket::sole();
        $actor = User::factory()->create(['role' => 'admin_full']);
        $reservation = app(SupportMedia::class)->reserve('writer-race', 'hash', 'image', $ticket->id);
        [$parent, $worker] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        stream_set_timeout($parent, 20);
        stream_set_timeout($worker, 20);
        try {
            $child = $this->spawn(fn () => app(SupportMedia::class)->guard($reservation->id, function () use ($worker) {
                fwrite($worker, 'L');
                if (fread($worker, 1) !== 'G') {
                    throw new \RuntimeException('Fixture writer was not released');
                }

                return 'writer-finished';
            }));
            $this->assertSame('R', fread($child['socket'], 1));
            fwrite($child['socket'], 'G');
            $this->assertSame('L', fread($parent, 1));
            try {
                $operations->close($actor, $ticket->id, $ticket->revision, 'writer-close-key');
                $this->fail('Active writer must prevent close');
            } catch (HttpException $error) {
                $this->assertSame(409, $error->getStatusCode());
            }
            $this->assertSame(0, SupportMediaDeletion::count());
            fwrite($parent, 'G');
            $result = json_decode(fgets($child['socket']), true, flags: JSON_THROW_ON_ERROR);
            pcntl_waitpid($child['pid'], $status);
            $this->assertSame('writer-finished', $result['value']);
            $operations->close($actor, $ticket->id, $ticket->revision, 'writer-close-key');
            try {
                app(SupportMedia::class)->guard($reservation->id, fn () => $this->fail('Late writer must not run'));
                $this->fail('Tombstone must reject the writer');
            } catch (HttpException $error) {
                $this->assertSame(409, $error->getStatusCode());
            }
        } finally {
            fclose($parent);
            fclose($worker);
        }
    }

    private function spawn(callable $work): array
    {
        DB::disconnect('pgsql');
        [$parent, $child] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $pid = pcntl_fork();
        if ($pid === 0) {
            fclose($parent);
            foreach ($this->children as $existing) {
                fclose($existing['socket']);
            }
            DB::purge('pgsql');
            DB::statement("SET statement_timeout='15s'");
            fwrite($child, 'R');
            fread($child, 1);
            try {
                $result = ['status' => 'ok', 'value' => $work()];
            } catch (\Throwable $e) {
                $result = ['status' => 'error', 'exception' => $e::class, 'message' => $e->getMessage()];
            }
            fwrite($child, json_encode($result)."\n");
            fclose($child);
            DB::disconnect('pgsql');
            pcntl_exec('/usr/bin/true');
            exit(0);
        }
        $this->assertGreaterThan(0, $pid);
        fclose($child);
        stream_set_timeout($parent, 20);
        $entry = ['pid' => $pid, 'socket' => $parent];
        $this->children[] = $entry;

        return $entry;
    }

    private function startAndFinish(array ...$children): array
    {
        foreach ($children as $child) {
            $this->assertSame('R', fread($child['socket'], 1));
        }
        foreach ($children as $child) {
            fwrite($child['socket'], 'G');
        }
        $results = [];
        foreach ($children as $child) {
            $result = fgets($child['socket']);
            $this->assertNotFalse($result);
            pcntl_waitpid($child['pid'], $status);
            $this->assertSame(0, pcntl_wexitstatus($status));
            $results[] = json_decode($result, true, flags: JSON_THROW_ON_ERROR);
        }

        return $results;
    }
}
