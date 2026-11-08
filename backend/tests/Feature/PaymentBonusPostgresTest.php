<?php

namespace Tests\Feature;

use App\Models\CoinLedgerEntry;
use App\Models\CoinReservation;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\Order;
use App\Models\OrderSettlement;
use App\Models\PromoCode;
use App\Models\PromoReservation;
use App\Models\Subject;
use App\Models\User;
use App\Services\Bonuses\CoinLedgerService;
use App\Services\Bonuses\PromoReservationService;
use App\Services\Bonuses\PurchaseRewardService;
use App\Services\OrderItemSnapshotService;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\TestCase;

#[Group('pgsql')]
class PaymentBonusPostgresTest extends TestCase
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
        Http::preventStrayRequests();
    }

    public function test_same_user_competing_orders_cannot_reserve_more_than_available(): void
    {
        $first = $this->order();
        $second = $this->order($first->user_id);
        CoinLedgerEntry::query()->create(['user_id' => $first->user_id, 'event_type' => 'coins_accrual',
            'units' => 100, 'idempotency_key' => 'seed:'.Str::uuid()]);
        $result = $this->concurrently([
            ['operation' => 'coins', 'order' => $first->id, 'user' => $first->user_id, 'key' => 'reserve:'.Str::uuid()],
            ['operation' => 'coins', 'order' => $second->id, 'user' => $first->user_id, 'key' => 'reserve:'.Str::uuid()],
        ], 'users');
        $statuses = array_column($result, 'status');
        sort($statuses);
        $this->assertSame(['BONUS_INSUFFICIENT_COINS', 'ok'], $statuses);
        $this->assertSame(1, CoinReservation::query()->where('user_id', $first->user_id)->count());
        $this->assertSame(70, (int) CoinReservation::query()->where('user_id', $first->user_id)->sum('units'));
        $this->assertSame(30, app(CoinLedgerService::class)->balance($first->user_id));
    }

    public function test_duplicate_reward_repays_debt_exactly_once_under_order_lock(): void
    {
        $order = $this->order();
        $order->update(['payment_status' => 'paid', 'amount_paid' => 100000]);
        OrderSettlement::query()->create(['order_id' => $order->id, 'settlement_type' => 'provider_payment',
            'idempotency_key' => 'settlement:'.Str::uuid(), 'amount' => 100000, 'currency' => 'RUB', 'status' => 'confirmed',
            'confirmed_at' => now(), 'fingerprint_hash' => str_repeat('a', 64), 'metadata' => ['grant_status' => 'granted']]);
        $old = $this->order($order->user_id);
        $lot = CoinLedgerEntry::query()->create(['user_id' => $order->user_id, 'order_id' => $old->id,
            'event_type' => 'coins_accrual', 'units' => 30, 'idempotency_key' => 'seed:'.Str::uuid()]);
        foreach (['coins_revoke', 'coins_debt'] as $event) {
            CoinLedgerEntry::query()->create(['user_id' => $order->user_id, 'order_id' => $old->id,
                'lot_id' => $lot->id, 'event_type' => $event, 'units' => 30, 'idempotency_key' => 'seed:'.Str::uuid()]);
        }
        $job = ['operation' => 'reward', 'order' => $order->id];
        $result = $this->concurrently([$job, $job], 'orders');
        $this->assertSame(['ok', 'ok'], array_column($result, 'status'));
        $this->assertSame($result[0]['result'], $result[1]['result']);
        $this->assertSame(1, CoinLedgerEntry::query()->where('order_id', $order->id)->where('event_type', 'coins_accrual')->count());
        $this->assertSame(30, (int) CoinLedgerEntry::query()->where('order_id', $order->id)->where('event_type', 'coins_debt_repay')->sum('units'));
        $this->assertSame(20, app(CoinLedgerService::class)->balance($order->user_id));
    }

    public function test_last_promo_activation_cannot_be_committed_by_two_orders(): void
    {
        $first = $this->order();
        $second = $this->order();
        $code = 'PG_'.Str::random(24);
        $promo = PromoCode::query()->create(['code_hash' => PromoReservationService::codeHash($code),
            'status' => 'active', 'discount_kind' => 'fixed_minor', 'discount_minor' => 1000, 'currency' => 'RUB',
            'expires_at' => now()->addDay(), 'activation_limit' => 1, 'per_user_limit' => 1, 'rules_version' => 'pg-v1']);
        $result = $this->concurrently([
            ['operation' => 'promo', 'order' => $first->id, 'user' => $first->user_id, 'code' => $code, 'key' => 'promo:'.Str::uuid()],
            ['operation' => 'promo', 'order' => $second->id, 'user' => $second->user_id, 'code' => $code, 'key' => 'promo:'.Str::uuid()],
        ], 'promo_codes');
        $statuses = array_column($result, 'status');
        sort($statuses);
        $this->assertSame(['BONUS_PROMO_LIMIT_REACHED', 'ok'], $statuses);
        $this->assertSame(1, PromoReservation::query()->where('promo_code_id', $promo->id)->count());
        $this->assertSame('committed', PromoReservation::query()->where('promo_code_id', $promo->id)->sole()->status);
    }

    public function test_promo_expiry_preserves_its_instant_after_database_round_trip(): void
    {
        $order = $this->order();
        $code = 'PG_'.Str::random(24);
        PromoCode::query()->create(['code_hash' => PromoReservationService::codeHash($code),
            'status' => 'active', 'discount_kind' => 'fixed_minor', 'discount_minor' => 1000, 'currency' => 'RUB',
            'expires_at' => now()->addDay(), 'activation_limit' => 1, 'per_user_limit' => 1, 'rules_version' => 'pg-v1']);
        $service = app(PromoReservationService::class);
        $reservation = $service->reserve($order->user_id, $order->id, $code, 'promo:'.Str::uuid())->fresh();

        $this->assertSame('UTC', DB::selectOne('SHOW TIME ZONE')->TimeZone);
        $this->assertSame(strtotime($reservation->proof['expires_at']), $reservation->expires_at->getTimestamp());
        $this->assertTrue($reservation->expires_at->isFuture());
        $this->assertTrue(PromoReservation::query()->whereKey($reservation->id)->where('expires_at', '>', now())->exists());

        // The same instant must become expired at the exact reservation boundary.
        $this->travelTo($reservation->expires_at);
        try {
            $this->expectException(DomainException::class);
            $this->expectExceptionMessage('BONUS_RESERVATION_EXPIRED');
            $service->commit($order->user_id, $reservation->id);
        } finally {
            $this->travelBack();
        }
    }

    public function test_reconnect_restores_utc_after_a_non_utc_session(): void
    {
        $instant = now()->startOfSecond();
        $value = $instant->format('Y-m-d H:i:s');
        try {
            DB::statement("SET TIME ZONE 'Europe/Moscow'");
            $shifted = DB::selectOne('SELECT CAST(? AS timestamptz) AS instant', [$value])->instant;
            $this->assertSame($instant->getTimestamp() - 10800, strtotime($shifted));
        } finally {
            DB::disconnect();
            DB::reconnect();
        }
        $restored = DB::selectOne('SELECT CAST(? AS timestamptz) AS instant', [$value])->instant;
        $this->assertSame('UTC', DB::selectOne('SHOW TIME ZONE')->TimeZone);
        $this->assertSame($instant->getTimestamp(), strtotime($restored));
    }

    private function order(?int $userId = null): Order
    {
        $subject = Subject::query()->create(['slug' => 'pgbonus-'.Str::uuid(), 'title' => 'Subject', 'sort_order' => 1, 'is_active' => true]);
        $course = Course::query()->create(['subject_id' => $subject->id, 'slug' => 'pgbonus-'.Str::uuid(), 'title' => 'Course',
            'short_title' => 'Course', 'price_cents' => 100000, 'sort_order' => 1, 'is_active' => true]);
        Lesson::query()->create(['course_id' => $course->id, 'slug' => 'lesson', 'title' => 'Lesson', 'sort_order' => 1, 'is_active' => true]);
        $order = Order::query()->create(['user_id' => $userId ?? User::factory()->create()->id, 'course_id' => $course->id,
            'payment_status' => 'pending_payment', 'currency' => 'RUB', 'amount_total' => 100000, 'amount_final' => 100000]);
        app(OrderItemSnapshotService::class)->createCourseItem($order, $course);

        return $order;
    }

    /** The winner holds its real locks until pg_stat_activity proves the competing waiter. */
    private function concurrently(array $jobs, string $table): array
    {
        foreach (['pcntl_fork', 'pcntl_waitpid', 'pcntl_wifexited', 'pcntl_wexitstatus', 'posix_kill', 'stream_socket_pair'] as $function) {
            if (! is_callable($function)) {
                $this->markTestSkipped('PostgreSQL bonus concurrency requires '.$function.'.');
            }
        }
        $application = 'payment-bonus-test-'.bin2hex(random_bytes(8));
        DB::disconnect();
        $children = [];
        try {
            foreach ($jobs as $job) {
                $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
                if ($pair === false) {
                    throw new RuntimeException('Cannot create bonus worker barrier.');
                }
                [$parent, $worker] = $pair;
                $pid = pcntl_fork();
                if ($pid === -1) {
                    fclose($parent);
                    fclose($worker);
                    throw new RuntimeException('Cannot fork bonus worker.');
                }
                if ($pid === 0) {
                    foreach ($children as $child) {
                        fclose($child['socket']);
                    }
                    fclose($parent);
                    try {
                        DB::reconnect();
                        DB::selectOne("SELECT set_config('application_name', ?, false)", [$application]);
                        DB::statement("SET statement_timeout = '8s'");
                        DB::statement("SET lock_timeout = '5s'");
                        Http::preventStrayRequests();
                        stream_set_timeout($worker, 8);
                        fwrite($worker, 'R');
                        if (fread($worker, 1) !== 'G') {
                            throw new RuntimeException('Start barrier timed out.');
                        }
                        $outcome = DB::transaction(function () use ($job, $worker): array {
                            try {
                                $result = match ($job['operation']) {
                                    'coins' => app(CoinLedgerService::class)->reserve($job['user'], $job['order'], 70, $job['key'])->id,
                                    'reward' => app(PurchaseRewardService::class)->apply($job['order']),
                                    'promo' => app(PromoReservationService::class)->commit($job['user'],
                                        app(PromoReservationService::class)->reserve($job['user'], $job['order'], $job['code'], $job['key'])->id)->id,
                                };
                                $outcome = ['status' => 'ok', 'result' => $result];
                            } catch (DomainException $exception) {
                                $outcome = ['status' => $exception->getMessage()];
                            }
                            fwrite($worker, 'H');
                            if (fread($worker, 1) !== 'C') {
                                throw new RuntimeException('Commit barrier timed out.');
                            }

                            return $outcome;
                        });
                    } catch (\Throwable $exception) {
                        $outcome = ['status' => 'unexpected', 'exception' => get_class($exception)];
                    }
                    fwrite($worker, json_encode($outcome, JSON_THROW_ON_ERROR));
                    fclose($worker);
                    DB::disconnect();
                    exit(0);
                }
                fclose($worker);
                $children[] = ['pid' => $pid, 'socket' => $parent, 'held' => false, 'released' => false, 'status' => null];
            }
            foreach ($children as $child) {
                stream_set_timeout($child['socket'], 8);
                $this->assertSame('R', fread($child['socket'], 1));
            }
            foreach ($children as $child) {
                fwrite($child['socket'], 'G');
            }
            DB::reconnect();
            $observed = false;
            $deadline = microtime(true) + 7;
            do {
                $read = array_column(array_filter($children, fn ($child) => ! $child['held']), 'socket');
                $write = $except = [];
                if ($read !== [] && stream_select($read, $write, $except, 0, 10000) > 0) {
                    foreach ($children as &$child) {
                        if (in_array($child['socket'], $read, true)) {
                            $this->assertSame('H', fread($child['socket'], 1), 'Worker must reach the commit barrier.');
                            $child['held'] = true;
                        }
                    }
                    unset($child);
                }
                $observed = $observed || DB::selectOne("SELECT 1 FROM pg_stat_activity WHERE datname = current_database() AND application_name = ? AND wait_event_type = 'Lock' AND query ILIKE ? LIMIT 1",
                    [$application, '%"'.$table.'"%']) !== null;
                foreach ($children as &$child) {
                    if ($observed && $child['held'] && ! $child['released']) {
                        fwrite($child['socket'], 'C');
                        $child['released'] = true;
                    }
                }
                unset($child);
            } while (count(array_filter($children, fn ($child) => $child['released'])) !== count($children) && microtime(true) < $deadline);
            $this->assertTrue($observed, 'A real PostgreSQL '.$table.' lock waiter must be observed before releasing commit.');
            $outcomes = [];
            foreach ($children as &$child) {
                $this->assertTrue($child['released'], 'Worker did not reach commit barrier.');
                do {
                    $waited = pcntl_waitpid($child['pid'], $status, WNOHANG);
                    if ($waited === $child['pid']) {
                        $child['status'] = $status;
                        break;
                    }
                    usleep(10000);
                } while (microtime(true) < $deadline);
                $this->assertNotNull($child['status'], 'Worker did not exit before deadline.');
                $this->assertTrue(pcntl_wifexited($child['status']));
                $this->assertSame(0, pcntl_wexitstatus($child['status']));
                $outcomes[] = json_decode(stream_get_contents($child['socket']), true, flags: JSON_THROW_ON_ERROR);
            }
            unset($child);

            return $outcomes;
        } finally {
            unset($child);
            foreach ($children as $child) {
                if ($child['status'] === null) {
                    posix_kill($child['pid'], SIGKILL);
                    pcntl_waitpid($child['pid'], $status);
                }
                fclose($child['socket']);
            }
            DB::reconnect();
        }
    }
}
