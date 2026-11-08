<?php

namespace Tests\Feature;

use App\Models\AdminUserEvent;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\AdminUserManagement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

#[Group('pgsql')]
class AdminUserManagementPostgresTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('pgsql', DB::connection()->getDriverName());
        $this->assertSame('127.0.0.1', config('database.connections.pgsql.host'));
        $this->assertSame('cms_test', DB::connection()->getDatabaseName());
        $this->assertContains(config('database.connections.pgsql.url'), [null, '']);
    }

    public function test_concurrent_profile_updates_have_one_winner_and_one_audit_event(): void
    {
        $actor = User::factory()->create(['role' => 'admin_full']);
        $user = User::factory()->create(['name' => 'Before']);
        $revision = AdminUserManagement::revisionFor($user, 0);
        $jobs = [
            ['operation' => 'profile', 'data' => ['revision' => $revision, 'name' => 'First']],
            ['operation' => 'profile', 'data' => ['revision' => $revision, 'name' => 'Second']],
        ];
        $results = $this->concurrent($actor->id, $user->id, $jobs);
        $statuses = array_column($results, 'status');
        sort($statuses);
        $this->assertSame([200, 409], $statuses);
        $this->assertSame(1, UserProfile::query()->findOrFail($user->id)->revision);
        $event = AdminUserEvent::query()->where('user_id', $user->id)->sole();
        $this->assertSame($user->fresh()->name, $event->changes['name']['after']);
    }

    public function test_concurrent_comment_retries_create_one_event(): void
    {
        $actor = User::factory()->create(['role' => 'admin_full']);
        $user = User::factory()->create();
        $job = ['operation' => 'comment', 'data' => ['comment' => 'Один комментарий', 'idempotency_key' => (string) Str::uuid()]];
        $results = $this->concurrent($actor->id, $user->id, [$job, $job]);
        $this->assertSame([200, 200], array_column($results, 'status'));
        $this->assertSame($results[0]['result']['event_id'], $results[1]['result']['event_id']);
        $this->assertSame(1, AdminUserEvent::query()->where('user_id', $user->id)->count());
    }

    private function concurrent(int $actorId, int $userId, array $jobs): array
    {
        foreach (['pcntl_fork', 'pcntl_waitpid', 'stream_socket_pair', 'posix_kill'] as $function) {
            $this->assertTrue(function_exists($function), "Concurrency proof requires {$function}");
        }
        $application = 'admin-users-test-'.bin2hex(random_bytes(8));
        DB::disconnect();
        $children = [];
        $observed = false;
        $results = [];
        try {
            foreach ($jobs as $job) {
                [$parent, $child] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
                $pid = pcntl_fork();
                $this->assertNotSame(-1, $pid);
                if ($pid === 0) {
                    fclose($parent);
                    foreach ($children as $previous) {
                        fclose($previous['socket']);
                    }
                    try {
                        DB::reconnect();
                        DB::selectOne("SELECT set_config('application_name', ?, false)", [$application]);
                        DB::statement("SET statement_timeout = '8s'");
                        DB::statement("SET lock_timeout = '5s'");
                        AdminUserEvent::creating(static fn () => DB::select('SELECT pg_sleep(0.4)'));
                        fwrite($child, 'R');
                        stream_set_timeout($child, 5);
                        if (fread($child, 1) !== 'G') {
                            throw new \RuntimeException('Barrier not released');
                        }
                        $result = app(AdminUserManagement::class)->mutate(User::query()->findOrFail($actorId), $userId, $job['operation'], $job['data']);
                        $outcome = ['status' => 200, 'result' => $result];
                    } catch (HttpExceptionInterface $exception) {
                        $outcome = ['status' => $exception->getStatusCode()];
                    } catch (\Throwable $exception) {
                        $outcome = ['status' => 500, 'error' => get_class($exception)];
                    }
                    fwrite($child, json_encode($outcome, JSON_THROW_ON_ERROR));
                    fclose($child);
                    DB::disconnect();
                    exit(0);
                }
                fclose($child);
                $children[] = ['pid' => $pid, 'socket' => $parent, 'done' => false];
            }
            foreach ($children as $child) {
                stream_set_timeout($child['socket'], 5);
                $this->assertSame('R', fread($child['socket'], 1));
            }
            foreach ($children as $child) {
                fwrite($child['socket'], 'G');
            }
            DB::reconnect();
            $deadline = microtime(true) + 10;
            do {
                $observed = $observed || DB::selectOne(
                    "SELECT 1 FROM pg_stat_activity WHERE datname=current_database() AND application_name=? AND wait_event_type='Lock' LIMIT 1",
                    [$application]
                ) !== null;
                foreach ($children as &$child) {
                    if (! $child['done'] && pcntl_waitpid($child['pid'], $status, WNOHANG) === $child['pid']) {
                        $this->assertTrue(pcntl_wifexited($status));
                        $this->assertSame(0, pcntl_wexitstatus($status));
                        $child['done'] = true;
                    }
                }
                unset($child);
                $pending = array_filter($children, fn ($child) => ! $child['done']);
                if ($pending !== []) {
                    usleep(10000);
                }
            } while ($pending !== [] && microtime(true) < $deadline);
            $this->assertSame([], $pending, 'Workers must finish before timeout');
            $this->assertTrue($observed, 'The test must observe an actual PostgreSQL lock waiter');
            foreach ($children as $child) {
                $results[] = json_decode(stream_get_contents($child['socket']), true, flags: JSON_THROW_ON_ERROR);
            }
        } finally {
            foreach ($children as $child) {
                if (! $child['done']) {
                    posix_kill($child['pid'], SIGKILL);
                    pcntl_waitpid($child['pid'], $status);
                }
                fclose($child['socket']);
            }
            DB::reconnect();
        }

        return $results;
    }
}
