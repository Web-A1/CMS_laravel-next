<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\LegacyUsers\LegacyUserActivationService;
use App\Services\LegacyUsers\LegacyUserBundle;
use App\Services\LegacyUsers\LegacyUserRightsReconciler;
use App\Services\LegacyUsers\LegacyUserSourcePolicy;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\LegacyUserBundleFixtures;
use Tests\TestCase;

#[Group('pgsql')]
class LegacyUserActivationConcurrencyTest extends TestCase
{
    private string $schema;

    private LegacyUserBundleFixtures $fixtures;

    protected function setUp(): void
    {
        parent::setUp();
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Fork support required for concurrency proof.');
        }
        config(['database.default' => 'pgsql', 'database.connections.pgsql.host' => '127.0.0.1',
            'database.connections.pgsql.database' => 'cms_test', 'database.connections.pgsql.url' => null,
            'services.redsms.mode' => 'mock']);
        $this->schema = 'legacy_fixture_'.bin2hex(random_bytes(8));
        config(['database.connections.pgsql.search_path' => $this->schema]);
        DB::purge('pgsql');
        $this->assertSame('cms_test', DB::selectOne('SELECT current_database() AS name')->name);
        DB::statement('CREATE SCHEMA "'.$this->schema.'"');
        Artisan::call('migrate', ['--force' => true]);
        Http::preventStrayRequests();
        Queue::fake();
        Mail::fake();
        $this->fixtures = new LegacyUserBundleFixtures;
        $this->fixtures->import($this->fixtures->bundle(LegacyUserBundleFixtures::records()));
    }

    protected function tearDown(): void
    {
        if (isset($this->fixtures)) {
            $this->fixtures->cleanup();
        }
        if (isset($this->schema)) {
            DB::purge('pgsql');
            DB::statement('DROP SCHEMA IF EXISTS "'.$this->schema.'" CASCADE');
        }
        parent::tearDown();
    }

    private function phoneReady(string $email = 'synthetic13@example.test'): array
    {
        $service = app(LegacyUserActivationService::class);
        $start = $service->start($email);
        $service->verifyEmail($start['activation_id'], $start['capability'], $start['debug_code']);
        $service->password($start['activation_id'], $start['capability'], 'new-password-123');
        $service->startPhone($start['activation_id'], $start['capability'], '+79990000123');

        return [$start['activation_id'], $start['capability']];
    }

    /** A held row proves the concurrent process really waits on a PostgreSQL lock. */
    private function contender(string $lockTable, callable $operation, callable $winner, bool $winBeforeSignal = false): array
    {
        $path = $this->fixtures->root.'/contender.json';
        $signal = $this->fixtures->root.'/start';
        DB::disconnect('pgsql');
        $pid = pcntl_fork();
        if ($pid === -1) {
            $this->fail('Unable to fork fixture contender.');
        }
        if ($pid === 0) {
            $deadline = microtime(true) + 15;
            while (! is_file($signal) && microtime(true) < $deadline) {
                usleep(10000);
            }
            DB::purge('pgsql');
            DB::statement("SET application_name = '".$this->schema."'");
            try {
                $result = ['success' => true, 'value' => $operation()];
            } catch (\Throwable $exception) {
                $result = ['success' => false, 'exception' => $exception::class, 'message' => $exception->getMessage()];
                if ($exception instanceof HttpResponseException) {
                    $result['http_status'] = $exception->getResponse()->getStatusCode();
                    $result['code'] = json_decode($exception->getResponse()->getContent(), true)['error']['code'] ?? null;
                }
            }
            file_put_contents($path, json_encode($result, JSON_THROW_ON_ERROR));
            DB::disconnect('pgsql');
            exit(0);
        }
        DB::purge('pgsql');
        DB::beginTransaction();
        DB::table($lockTable)->lockForUpdate()->first();
        if ($winBeforeSignal) {
            $winner();
        }
        touch($signal);
        $waited = false;
        $deadline = microtime(true) + 10;
        while (microtime(true) < $deadline) {
            DB::select('SELECT pg_stat_clear_snapshot()');
            $waited = (bool) DB::selectOne("SELECT EXISTS(SELECT 1 FROM pg_stat_activity WHERE application_name = ? AND wait_event_type = 'Lock') AS waiting", [$this->schema])->waiting;
            if ($waited) {
                break;
            }
            usleep(10000);
        }
        try {
            $this->assertTrue($waited, 'Contender must visibly wait on PostgreSQL row lock. '.(is_file($path) ? file_get_contents($path) : 'No result yet'));
            if (! $winBeforeSignal) {
                $winner();
            }
            DB::commit();
        } catch (\Throwable $error) {
            DB::rollBack();
            throw $error;
        } finally {
            $deadline = microtime(true) + 15;
            do {
                $done = pcntl_waitpid($pid, $status, WNOHANG);
                if ($done === 0) {
                    usleep(10000);
                }
            } while ($done === 0 && microtime(true) < $deadline);
            if ($done === 0) {
                posix_kill($pid, SIGTERM);
                pcntl_waitpid($pid, $status);
            }
        }
        $this->assertFileExists($path);

        return json_decode(file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
    }

    public function test_postgres_finish_has_one_winner_and_replay_cannot_mutate_again(): void
    {
        $credential = $this->phoneReady();
        $result = $this->contender('legacy_user_profiles',
            fn () => app(LegacyUserActivationService::class)->complete(...$credential),
            fn () => app(LegacyUserActivationService::class)->complete(...$credential));
        $this->assertFalse($result['success']);
        $this->assertSame(1, DB::table('legacy_user_activations')->whereNotNull('completed_at')->count());
        $this->assertFalse(User::query()->sole()->blocksAuthenticationForMigration());
        $this->assertDatabaseCount('user_entitlement_grants', 0);
    }

    public function test_postgres_rights_predecessor_cas_has_one_authoritative_winner(): void
    {
        app(LegacyUserActivationService::class)->complete(...$this->phoneReady());
        $this->fixtures->mapping();
        $a = new LegacyUserBundle($this->fixtures->bundle(LegacyUserBundleFixtures::records(), 'A', str_repeat('a', 64)), app(LegacyUserSourcePolicy::class));
        $b = new LegacyUserBundle($this->fixtures->bundle([], 'B', str_repeat('b', 64)), app(LegacyUserSourcePolicy::class));
        DB::table('legacy_user_rights_authorities')->insert(['source_database' => 'main_school_db', 'generation' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $result = $this->contender('legacy_user_rights_authorities',
            fn () => app(LegacyUserRightsReconciler::class)->reconcile($b, 0, $b->manifestHash),
            fn () => app(LegacyUserRightsReconciler::class)->reconcile($a, 0, $a->manifestHash));
        $this->assertFalse($result['success']);
        $this->assertSame(\InvalidArgumentException::class, $result['exception']);
        $this->assertDatabaseCount('legacy_user_rights_generations', 1);
        $this->assertDatabaseCount('user_entitlement_grants', 1);
        $this->assertSame(1, DB::table('legacy_user_rights_authorities')->value('generation'));
    }

    public function test_postgres_same_phone_race_leaves_loser_held_and_retryable(): void
    {
        $records = LegacyUserBundleFixtures::records();
        $records['member'][] = LegacyUserBundleFixtures::envelope(14, 14, 'identity', [
            'email' => 'synthetic14@example.test', 'phone' => '+79990000014', 'firstname' => 'Second', 'isBanned' => 0, 'status' => 10,
        ]);
        $this->fixtures->import($this->fixtures->bundle($records, 'two-members', str_repeat('b', 64)));
        $first = $this->phoneReady();
        $second = $this->phoneReady('synthetic14@example.test');
        $result = $this->contender('legacy_user_profiles',
            fn () => app(LegacyUserActivationService::class)->complete(...$second),
            fn () => app(LegacyUserActivationService::class)->complete(...$first), true);
        $this->assertFalse($result['success'], json_encode($result, JSON_THROW_ON_ERROR));
        $this->assertSame('AUTH_PHONE_EXISTS', $result['code']);
        $this->assertSame(409, $result['http_status']);
        $loser = User::query()->where('email', 'synthetic14@example.test')->sole();
        $this->assertTrue($loser->blocksAuthenticationForMigration());
        $this->assertSame('+79990000014', $loser->phone);
        $this->assertSame('phone', app(LegacyUserActivationService::class)->status(...$second)['step']);
        $this->assertSame(1, User::query()->where('phone', '+79990000123')->count());
        $this->assertSame(1, DB::table('legacy_user_activations')->whereNotNull('completed_at')->count());
    }
}
