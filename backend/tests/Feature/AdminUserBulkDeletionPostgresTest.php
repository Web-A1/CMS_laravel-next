<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\Services\AdminUserBulkDeletion;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;
use Throwable;

#[Group('pgsql')]
class AdminUserBulkDeletionPostgresTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('pgsql', DB::getDriverName());
        $this->assertSame('127.0.0.1', config('database.connections.pgsql.host'));
        $this->assertSame('cms_test', DB::connection()->getDatabaseName());
        $this->assertContains(config('database.connections.pgsql.url'), [null, '']);
    }

    public function test_retained_ids_survive_real_fk_deletion_and_append_only_trigger_remains(): void
    {
        $actor = User::factory()->create(['role' => 'admin_full']);
        $user = User::factory()->create(['role' => 'user_with_purchase']);
        $order = Order::create(['user_id' => $user->id, 'amount_total' => 100, 'amount_final' => 100]);
        $id = DB::table('payment_audit_log')->insertGetId(['order_id' => $order->id, 'action' => 'bulk-test',
            'meta' => '{"retained":true}', 'created_at' => now()]);
        $before = DB::table('payment_audit_log')->find($id);
        $service = app(AdminUserBulkDeletion::class);
        $preview = $service->preview($actor, [$user->id]);
        $service->deleteMany($actor, [$user->id], $preview['fingerprint'], '1', 'pg-'.$user->id);
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertDatabaseMissing('orders', ['id' => $order->id]);
        $this->assertEquals($before, DB::table('payment_audit_log')->find($id));
        $this->assertNotContains('order_id', array_merge(...array_map(fn ($fk) => $fk['columns'], Schema::getForeignKeys('payment_audit_log'))));
        $this->assertNotEmpty(DB::select("SELECT 1 FROM pg_trigger WHERE tgname = 'lesson_assessment_review_events_append_only_trigger' AND tgenabled <> 'D'"));
        try {
            DB::table('lesson_assessment_review_events')->whereRaw('false')->delete();
            $this->fail('Append-only statement trigger must reject even a zero-row DELETE.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('append-only', $exception->getMessage());
        }
    }

    public function test_child_order_write_waits_for_user_lock_and_invalidates_preview_before_any_deletion(): void
    {
        foreach (['pcntl_fork', 'pcntl_waitpid', 'stream_socket_pair'] as $function) {
            $this->assertTrue(function_exists($function), 'Concurrency proof requires '.$function);
        }
        $actor = User::factory()->create(['role' => 'admin_full']);
        $user = User::factory()->create(['role' => 'user_no_purchase']);
        $service = app(AdminUserBulkDeletion::class);
        $preview = $service->preview($actor, [$user->id]);
        $application = 'user-delete-child-'.bin2hex(random_bytes(6));
        DB::disconnect();
        [$parent, $child] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid);
        if ($pid === 0) {
            fclose($parent);
            try {
                DB::purge();
                DB::select("SELECT set_config('application_name', ?, false)", [$application]);
                fwrite($child, "ready\n");
                fgets($child);
                Order::create(['user_id' => $user->id, 'amount_total' => 12, 'amount_final' => 12]);
                fwrite($child, "inserted\n");
                fclose($child);
                exit(0);
            } catch (Throwable $exception) {
                fwrite($child, 'error:'.$exception->getMessage()."\n");
                exit(1);
            }
        }
        fclose($child);
        stream_set_timeout($parent, 10);
        $observedWait = false;
        try {
            $this->assertSame("ready\n", fgets($parent));
            DB::beginTransaction();
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            fwrite($parent, "go\n");
            $deadline = microtime(true) + 5;
            do {
                $observedWait = DB::table('pg_stat_activity')->where('application_name', $application)->where('wait_event_type', 'Lock')->exists();
                if (! $observedWait) {
                    usleep(10000);
                }
            } while (! $observedWait && microtime(true) < $deadline);
            DB::commit();
            $this->assertSame("inserted\n", fgets($parent));
            pcntl_waitpid($pid, $status);
            $this->assertSame(0, pcntl_wexitstatus($status));
            $this->assertTrue($observedWait, 'The child FK write must actually wait on the locked user.');
            try {
                $service->deleteMany($actor, [$user->id], $preview['fingerprint'], '1', 'race-'.$user->id);
                $this->fail('New child order must invalidate the preview.');
            } catch (HttpExceptionInterface $exception) {
                $this->assertSame(409, $exception->getStatusCode());
            }
            $this->assertDatabaseHas('users', ['id' => $user->id]);
            $this->assertDatabaseHas('orders', ['user_id' => $user->id]);
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            fclose($parent);
            pcntl_waitpid($pid, $status, WNOHANG);
        }
    }

    public function test_deep_retention_graph_with_real_restrict_and_append_only_constraints(): void
    {
        $actor = User::factory()->create(['role' => 'admin_full']);
        $user = User::factory()->create(['role' => 'user_with_purchase']);
        $fixture = AdminUserBulkDeletionTest::seedHistory($actor, $user);
        $service = app(AdminUserBulkDeletion::class);
        $preview = $service->preview($actor, [$user->id]);
        $service->deleteMany($actor, [$user->id], $preview['fingerprint'], '1', 'deep-pg-'.$user->id);
        foreach ($fixture['owned'] as $table => $id) {
            $this->assertDatabaseMissing($table, ['id' => $id]);
        }
        foreach ($fixture['retained'] as $table => $row) {
            $this->assertEquals($row, DB::table($table)->find($row->id), $table);
        }
        $this->assertDatabaseHas('video_watermark_sessions', ['id' => $fixture['watermark'], 'user_id' => $user->id, 'end_reason' => 'revoked']);
        $this->assertDatabaseHas('lessons', ['id' => $fixture['lesson']]);
    }
}
