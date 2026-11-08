<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\PaymentAuditLog;
use App\Models\Refund;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\TestCase;

#[Group('pgsql')]
class PaymentRefundPostgresTest extends TestCase
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
        config([
            'services.payments.providers.cloudpayments.public_id' => 'pk_pg_refund_fixture',
            'services.payments.providers.cloudpayments.webhook_secret' => 'pg-refund-fixture-secret',
        ]);
    }

    public function test_same_order_same_key_returns_one_refund_and_dispatches_once(): void
    {
        $order = $this->paidOrder();
        $key = bin2hex(random_bytes(16));
        $result = $this->concurrently([
            ['orderId' => $order->id, 'key' => $key],
            ['orderId' => $order->id, 'key' => $key],
        ]);

        $this->assertTrue($result['lockObserved'], 'Observe a real PostgreSQL order lock waiter.');
        $this->assertSame([202, 202], array_column($result['outcomes'], 'status'));
        $this->assertSame($result['outcomes'][0]['refund_id'], $result['outcomes'][1]['refund_id']);
        $this->assertOneDispatchAndUnchangedMoney([$order->id]);
    }

    public function test_same_order_different_keys_reserve_only_one_refund(): void
    {
        $order = $this->paidOrder();
        $result = $this->concurrently([
            ['orderId' => $order->id, 'key' => bin2hex(random_bytes(16))],
            ['orderId' => $order->id, 'key' => bin2hex(random_bytes(16))],
        ]);

        $this->assertTrue($result['lockObserved'], 'Observe a real PostgreSQL order lock waiter.');
        $statuses = array_column($result['outcomes'], 'status');
        sort($statuses);
        $this->assertSame([202, 409], $statuses);
        $conflict = array_values(array_filter($result['outcomes'], fn ($outcome) => $outcome['status'] === 409))[0];
        $this->assertSame('PAYMENT_REFUND_CONFIRMATION_PENDING', $conflict['error']);
        $this->assertOneDispatchAndUnchangedMoney([$order->id]);
    }

    public function test_different_orders_same_key_conflict_without_server_error_or_second_dispatch(): void
    {
        $first = $this->paidOrder();
        $second = $this->paidOrder();
        $key = bin2hex(random_bytes(16));
        $result = $this->concurrently([
            ['orderId' => $first->id, 'key' => $key],
            ['orderId' => $second->id, 'key' => $key],
        ], crossOrder: true);

        $this->assertTrue($result['lockObserved'], 'Observe contention on the unique refund key insert.');
        $statuses = array_column($result['outcomes'], 'status');
        sort($statuses);
        $this->assertSame([202, 409], $statuses);
        $conflict = array_values(array_filter($result['outcomes'], fn ($outcome) => $outcome['status'] === 409))[0];
        $this->assertSame('PAYMENT_REFUND_IDEMPOTENCY_CONFLICT', $conflict['error']);
        $this->assertOneDispatchAndUnchangedMoney([$first->id, $second->id]);
    }

    private function paidOrder(): Order
    {
        $order = Order::query()->create([
            'user_id' => User::factory()->create()->id,
            'currency' => 'RUB', 'amount_total' => 10000, 'amount_final' => 10000,
            'amount_paid' => 10000, 'amount_refunded' => 0,
            'payment_status' => 'paid', 'access_status' => 'granted',
        ]);
        $attempt = PaymentAttempt::query()->create([
            'order_id' => $order->id, 'provider' => 'cloudpayments',
            'provider_payment_id' => (string) (700000000 + $order->id),
            'status' => 'completed', 'amount' => 10000, 'currency' => 'RUB',
        ]);
        $order->update(['active_attempt_id' => $attempt->id]);

        return $order;
    }

    private function assertOneDispatchAndUnchangedMoney(array $orderIds): void
    {
        $this->assertSame(1, Refund::query()->whereIn('order_id', $orderIds)->count());
        $this->assertSame('submitted', Refund::query()->whereIn('order_id', $orderIds)->sole()->status);
        $this->assertSame(1, PaymentAuditLog::query()->whereIn('order_id', $orderIds)->where('action', 'pg_test_provider_dispatch')->count());
        $this->assertSame(1, PaymentAuditLog::query()->whereIn('order_id', $orderIds)->where('action', 'refund_requested')->count());
        foreach (Order::query()->whereIn('id', $orderIds)->get() as $order) {
            $this->assertSame(0, (int) $order->amount_refunded);
            $this->assertSame('paid', $order->payment_status);
            $this->assertSame('granted', $order->access_status);
        }
    }

    /** Independent sessions and API requests; cross-order workers also rendezvous before INSERT. */
    private function concurrently(array $jobs, bool $crossOrder = false): array
    {
        foreach (['pcntl_fork', 'pcntl_waitpid', 'pcntl_wifexited', 'pcntl_wexitstatus', 'posix_kill', 'stream_socket_pair'] as $function) {
            if (! is_callable($function)) {
                $this->markTestSkipped('PostgreSQL refund concurrency requires '.$function.'.');
            }
        }
        $actor = User::factory()->create(['role' => 'admin_full']);
        $applicationName = 'payment-refund-test-'.bin2hex(random_bytes(8));
        DB::disconnect();
        $children = [];
        $outcomes = [];
        $lockObserved = false;
        try {
            foreach ($jobs as $job) {
                $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
                if ($pair === false) {
                    throw new RuntimeException('Cannot create refund concurrency barrier.');
                }
                [$parentSocket, $childSocket] = $pair;
                $pid = pcntl_fork();
                if ($pid === -1) {
                    fclose($parentSocket);
                    fclose($childSocket);
                    throw new RuntimeException('Cannot fork refund worker.');
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
                        stream_set_timeout($childSocket, 5);
                        if ($crossOrder) {
                            Refund::creating(static function () use ($childSocket): void {
                                fwrite($childSocket, 'I');
                                if (fread($childSocket, 1) !== 'C') {
                                    throw new RuntimeException('Refund insert barrier was not released.');
                                }
                            });
                        }
                        // Hold the winner's real transaction so parent can observe the loser waiting.
                        PaymentAuditLog::creating(static function (PaymentAuditLog $log): void {
                            if ($log->action === 'refund_requested') {
                                DB::select('SELECT pg_sleep(0.6)');
                            }
                        });
                        Http::preventStrayRequests();
                        Http::fake(['api.cloudpayments.ru/payments/refund' => static function () use ($job) {
                            if (DB::transactionLevel() !== 0) {
                                throw new RuntimeException('Provider request ran inside a transaction.');
                            }
                            PaymentAuditLog::query()->create([
                                'order_id' => $job['orderId'], 'action' => 'pg_test_provider_dispatch', 'actor_type' => 'system',
                            ]);

                            return Http::response(['Success' => true, 'Model' => ['TransactionId' => 800000000 + $job['orderId']]]);
                        }]);
                        Sanctum::actingAs(User::query()->findOrFail($actor->id));
                        fwrite($childSocket, 'R');
                        if (fread($childSocket, 1) !== 'G') {
                            throw new RuntimeException('Refund worker barrier was not released.');
                        }
                        $response = $this->withHeader('Idempotency-Key', $job['key'])
                            ->postJson('/api/v1/payments/orders/'.$job['orderId'].'/refund', [
                                'refund_type' => 'full', 'reason' => 'PostgreSQL concurrency fixture', 'policy_access_action' => 'revoke',
                            ]);
                        $outcome = ['status' => $response->status(), 'refund_id' => $response->json('refund_id'), 'error' => $response->json('error.code')];
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
                $this->assertSame('R', fread($child['socket'], 1));
            }
            foreach ($children as $child) {
                fwrite($child['socket'], 'G');
            }
            if ($crossOrder) {
                foreach ($children as $child) {
                    $this->assertSame('I', fread($child['socket'], 1), 'Both orders must pass missing-key lookup before inserting.');
                }
                foreach ($children as $child) {
                    fwrite($child['socket'], 'C');
                }
            }
            DB::reconnect();
            $deadline = microtime(true) + 10;
            do {
                if (! $lockObserved) {
                    $lockObserved = DB::selectOne(<<<'SQL'
                        SELECT 1 FROM pg_stat_activity
                        WHERE datname = current_database() AND application_name = ?
                          AND wait_event_type = 'Lock' AND query ILIKE ?
                        LIMIT 1
                        SQL, [$applicationName, '%"'.($crossOrder ? 'refunds' : 'orders').'"%']) !== null;
                }
                foreach ($children as &$child) {
                    if ($child['status'] === null) {
                        $waited = pcntl_waitpid($child['pid'], $status, WNOHANG);
                        if ($waited === $child['pid']) {
                            $child['status'] = $status;
                        } elseif ($waited === -1) {
                            throw new RuntimeException('Refund worker became unavailable.');
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
                $this->assertNotNull($child['status'], 'Refund worker timed out.');
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
