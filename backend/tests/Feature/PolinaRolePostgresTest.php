<?php

namespace Tests\Feature;

use App\Models\AdminTask;
use App\Models\User;
use App\Services\AdminOperationsService;
use App\Services\Bonuses\PromoReservationService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\PromoDeletionFixtures;
use Tests\Concerns\StaffIdentitySchema;
use Tests\TestCase;

#[Group('pgsql')]
class PolinaRolePostgresTest extends TestCase
{
    use PromoDeletionFixtures, StaffIdentitySchema;

    private string $schema;

    private array $children = [];

    protected function setUp(): void
    {
        parent::setUp();
        $database = getenv('POLINA_PG_DATABASE') ?: '';
        $this->assertMatchesRegularExpression('/^cms_polina_test_[a-f0-9]{16}$/D', $database);
        config([
            'database.default' => 'pgsql', 'database.connections.pgsql.host' => '127.0.0.1',
            'database.connections.pgsql.port' => '5432', 'database.connections.pgsql.database' => $database,
            'database.connections.pgsql.url' => null, 'database.connections.pgsql.search_path' => 'public',
        ]);
        DB::purge('pgsql');
        $this->assertSame($database, DB::selectOne('SELECT current_database() AS name')->name);
        $this->assertSame('polina-role-fixture', DB::selectOne("SELECT shobj_description(oid,'pg_database') AS provenance FROM pg_database WHERE datname=current_database()")->provenance);
        $this->schema = 'polina_fixture_'.bin2hex(random_bytes(8));
        DB::statement('CREATE SCHEMA "'.$this->schema.'"');
        config(['database.connections.pgsql.search_path' => $this->schema]);
        DB::purge('pgsql');
        Artisan::call('migrate', ['--force' => true]);
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
            $this->assertMatchesRegularExpression('/^polina_fixture_[a-f0-9]{16}$/D', $this->schema);
            DB::purge('pgsql');
            DB::statement('DROP SCHEMA "'.$this->schema.'" CASCADE');
        }
        parent::tearDown();
    }

    public function test_reserve_waits_for_delete_and_cannot_create_a_new_reservation_afterwards(): void
    {
        $owner = User::factory()->create(['role' => 'polina']);
        $promo = $this->ownedPromo($owner);
        $order = $this->promoOrder();
        $child = $this->spawn(fn () => app(PromoReservationService::class)->reserve($order->user_id, $order->id, 'OWN-PROMO', 'race-new')->id);
        DB::beginTransaction();
        try {
            app(AdminOperationsService::class)->deleteOwnPromo($owner, $promo->id, key: 'race-delete-first');
            $this->start($child);
            $this->assertPromoWaiter($child);
            DB::commit();
        } finally {
            if (DB::transactionLevel()) {
                DB::rollBack();
            }
        }
        $result = $this->finish($child);
        $this->assertSame('error', $result['status']);
        $this->assertSame('BONUS_PROMO_INVALID', $result['message']);
        $this->assertDatabaseCount('promo_reservations', 0);
    }

    public function test_delete_waits_for_reserve_and_preserves_original_reservation_for_commit(): void
    {
        $owner = User::factory()->create(['role' => 'polina']);
        $promo = $this->ownedPromo($owner);
        $order = $this->promoOrder();
        $child = $this->spawn(fn () => app(AdminOperationsService::class)->deleteOwnPromo($owner, $promo->id, key: 'race-delete-after')['data']['deleted']);
        DB::beginTransaction();
        try {
            $reservation = app(PromoReservationService::class)->reserve($order->user_id, $order->id, 'OWN-PROMO', 'race-reserve-first');
            $this->start($child);
            $this->assertPromoWaiter($child);
            DB::commit();
        } finally {
            if (DB::transactionLevel()) {
                DB::rollBack();
            }
        }
        $this->assertSame('ok', $this->finish($child)['status']);
        app(PromoReservationService::class)->assertReserved($order, $reservation->id);
        $this->assertSame('committed', app(PromoReservationService::class)->commit($order->user_id, $reservation->id)->status);
        $this->assertSame($reservation->expires_at->toISOString(), $reservation->fresh()->expires_at->toISOString());
    }

    public function test_existing_commit_waits_for_delete_and_honors_the_tombstone(): void
    {
        $owner = User::factory()->create(['role' => 'polina']);
        $promo = $this->ownedPromo($owner);
        $order = $this->promoOrder();
        $reservation = app(PromoReservationService::class)->reserve($order->user_id, $order->id, 'OWN-PROMO', 'race-before-delete');
        $child = $this->spawn(fn () => app(PromoReservationService::class)->commit($order->user_id, $reservation->id)->status);
        DB::beginTransaction();
        try {
            app(AdminOperationsService::class)->deleteOwnPromo($owner, $promo->id, key: 'race-delete-before-commit');
            $this->start($child);
            $this->assertPromoWaiter($child);
            DB::commit();
        } finally {
            if (DB::transactionLevel()) {
                DB::rollBack();
            }
        }
        $result = $this->finish($child);
        $this->assertSame('ok', $result['status']);
        $this->assertSame('committed', $result['value']);
    }

    public function test_admin_update_waits_on_users_before_task_and_cannot_overwrite_concurrent_completion(): void
    {
        foreach ([true, false] as $adminFirst) {
            $first = User::factory()->create(['role' => $adminFirst ? 'admin_full' : 'polina']);
            $second = User::factory()->create(['role' => $adminFirst ? 'polina' : 'admin_full']);
            [$admin, $polina] = $adminFirst ? [$first, $second] : [$second, $first];
            $task = AdminTask::create(['title' => 'Original', 'description' => 'Keep', 'status' => 'open',
                'assignee_id' => $polina->id, 'created_by' => $admin->id]);
            $snapshot = $task->fresh()->only(['title', 'description', 'assignee_id', 'status']);
            $child = $this->spawn(function () use ($admin, $polina, $task, $snapshot): int {
                try {
                    app(AdminOperationsService::class)->tasks($admin, 'update', [
                        'title' => 'Stale update', 'description' => 'Keep', 'status' => 'open', 'assignee_id' => $polina->id,
                    ], $task->id, 'task-update-'.$task->id, expectedSnapshot: $snapshot);

                    return 200;
                } catch (HttpException $exception) {
                    return $exception->getStatusCode();
                }
            });
            DB::beginTransaction();
            try {
                // Pause completion at its first lock. A writer must wait here, before locking the task.
                User::query()->whereKey($polina->id)->lockForUpdate()->firstOrFail();
                $this->start($child);
                $this->assertPromoWaiter($child, 'users');
                app(AdminOperationsService::class)->completeAssignedTask($polina, $task->id, key: 'task-complete-'.$task->id);
                DB::commit();
            } finally {
                if (DB::transactionLevel()) {
                    DB::rollBack();
                }
            }
            $result = $this->finish($child);
            $this->assertSame('ok', $result['status']);
            $this->assertSame(409, $result['value']);
            $this->assertSame('done', $task->fresh()->status);
            $this->assertSame('Original', $task->fresh()->title);
            $this->assertSame(1, DB::table('admin_operation_events')->where('resource', 'AdminTask')->where('resource_id', $task->id)->count());
        }
    }

    private function spawn(callable $work): array
    {
        $this->assertTrue(function_exists('pcntl_fork') && function_exists('stream_socket_pair'));
        DB::disconnect('pgsql');
        [$parent, $child] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $name = 'polina-race-'.bin2hex(random_bytes(8));
        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new \RuntimeException('Cannot fork fixture worker.');
        }
        if ($pid === 0) {
            fclose($parent);
            stream_set_timeout($child, 15);
            DB::purge('pgsql');
            DB::selectOne("SELECT set_config('application_name',?,false)", [$name]);
            DB::statement("SET statement_timeout='15s'");
            fwrite($child, 'R');
            try {
                if (fread($child, 1) !== 'G') {
                    throw new \RuntimeException('Fixture barrier failed.');
                }
                $result = ['status' => 'ok', 'value' => $work()];
            } catch (\Throwable $exception) {
                $result = ['status' => 'error', 'exception' => $exception::class, 'message' => $exception->getMessage()];
            }
            fwrite($child, json_encode($result, JSON_THROW_ON_ERROR)."\n");
            fclose($child);
            DB::disconnect('pgsql');
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
        $this->assertSame('R', fread($child['socket'], 1));
        fwrite($child['socket'], 'G');
    }

    private function assertPromoWaiter(array $child, string $table = 'promo_codes'): void
    {
        $deadline = microtime(true) + 5;
        do {
            DB::select('SELECT pg_stat_clear_snapshot()');
            $waiter = DB::selectOne("SELECT 1 AS found FROM pg_stat_activity WHERE datname=current_database() AND application_name=? AND wait_event_type='Lock' AND query LIKE ?", [$child['name'], '%'.$table.'%']);
            if ($waiter) {
                break;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        $this->assertNotNull($waiter, 'Independent PostgreSQL session must wait on the expected row lock.');
    }

    private function finish(array $child): array
    {
        $result = fgets($child['socket']);
        $this->assertNotFalse($result);
        pcntl_waitpid($child['pid'], $status);
        $this->assertTrue(pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0);

        return json_decode($result, true, flags: JSON_THROW_ON_ERROR);
    }
}
