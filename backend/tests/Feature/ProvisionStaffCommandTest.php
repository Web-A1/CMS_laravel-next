<?php

namespace Tests\Feature;

use App\Console\Commands\ProvisionStaffCommand;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Concerns\SupportDatabase;
use Tests\TestCase;

class ProvisionStaffCommandTest extends TestCase
{
    use SupportDatabase;

    private function provisionOptions(array $overrides = []): array
    {
        return array_replace([
            'username' => ' POLINA ', 'name' => 'Полина', '--role' => 'polina',
            '--expect-env' => 'local', '--expect-database' => ':memory:', '--expect-host' => 'fixture-host',
        ], $overrides);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->app['env'] = 'local';
        // Database remains isolated SQLite; only the target guard host is a fixture.
        config(['database.connections.sqlite.host' => 'fixture-host']);
        DB::purge();
        $this->artisan('migrate:fresh')->assertSuccessful();
    }

    public function test_preflight_is_read_only_and_hidden_input_creates_normalized_staff_without_related_data(): void
    {
        $this->artisan('staff:provision', $this->provisionOptions())->expectsOutputToContain('status=ready')->assertSuccessful();
        $this->assertDatabaseCount('users', 0);
        $password = Str::random(24);
        $this->artisan('staff:provision', $this->provisionOptions(['--apply' => true]))
            ->expectsQuestion('Пароль нового сотрудника', $password)->expectsQuestion('Повторите пароль', $password)
            ->expectsOutputToContain('status=created')->assertSuccessful();
        $user = User::query()->sole();
        $this->assertSame('polina', $user->username);
        $this->assertNull($user->email);
        $this->assertSame('polina', $user->role);
        $this->assertTrue(Hash::check($password, $user->password));
        foreach (['orders', 'user_entitlement_grants', 'content_authors'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $before = $user->getRawOriginal();
        $this->artisan('staff:provision', $this->provisionOptions(['--apply' => true]))
            ->expectsOutputToContain('password=unchanged')->assertSuccessful();
        $this->assertSame($before, $user->fresh()->getRawOriginal());
        $this->assertDatabaseCount('users', 1);
    }

    public function test_target_mismatch_production_unknown_role_and_foreign_identity_fail_before_secret_input(): void
    {
        foreach ([
            ['--expect-env' => 'staging'], ['--expect-database' => 'foreign'], ['--expect-host' => 'foreign'],
            ['--role' => 'invented'], ['username' => '12unsafe'], ['username' => 'p'],
        ] as $override) {
            $this->artisan('staff:provision', $this->provisionOptions($override + ['--apply' => true]))->assertFailed();
        }
        $this->app['env'] = 'production';
        $this->artisan('staff:provision', $this->provisionOptions(['--expect-env' => 'production', '--apply' => true]))->assertFailed();
        $this->app['env'] = 'local';
        $foreign = User::factory()->create(['username' => 'polina', 'role' => 'user_no_purchase']);
        $before = $foreign->fresh()->getRawOriginal();
        $this->artisan('staff:provision', $this->provisionOptions(['--apply' => true]))
            ->expectsOutputToContain('reason=identity_conflict')->assertFailed();
        $this->assertSame($before, $foreign->fresh()->getRawOriginal());
    }

    public function test_duplicate_display_name_creates_a_distinct_identity_without_touching_student_or_resetting_repeat(): void
    {
        $student = User::factory()->create(['name' => 'polina', 'username' => null, 'role' => 'user_no_purchase']);
        $before = $student->fresh()->getRawOriginal();
        $password = Str::random(24);
        $options = $this->provisionOptions(['name' => 'polina', '--apply' => true]);
        $this->artisan('staff:provision', $options)
            ->expectsQuestion('Пароль нового сотрудника', $password)->expectsQuestion('Повторите пароль', $password)
            ->expectsOutputToContain('status=created')->assertSuccessful();
        $staff = User::query()->where('username', 'polina')->sole();
        $this->assertNotSame($student->id, $staff->id);
        $this->assertSame('polina', $staff->name);
        $this->assertTrue(Hash::check($password, $staff->password));
        $this->assertSame($before, $student->fresh()->getRawOriginal());
        $staffBefore = $staff->fresh()->getRawOriginal();
        $this->artisan('staff:provision', $options)->expectsOutputToContain('status=exists')->assertSuccessful();
        $this->assertSame($staffBefore, $staff->fresh()->getRawOriginal());
        $this->assertSame($before, $student->fresh()->getRawOriginal());
        $this->assertDatabaseCount('users', 2);
    }

    public function test_protected_stdin_does_not_echo_secret_and_repeat_does_not_reset_it(): void
    {
        $password = Str::random(24);
        $stream = fopen('php://memory', 'w+');
        fwrite($stream, $password."\n");
        rewind($stream);
        $command = $this->app->make(ProvisionStaffCommand::class);
        $command->setLaravel($this->app);
        $input = new ArrayInput($this->provisionOptions(['--apply' => true, '--password-stdin' => true]), $command->getDefinition());
        $input->setInteractive(false);
        $input->setStream($stream);
        $output = new BufferedOutput;
        try {
            $this->assertSame(0, $command->run($input, $output));
        } finally {
            fclose($stream);
        }
        $this->assertStringNotContainsString($password, $output->fetch());
        $this->assertTrue(Hash::check($password, User::query()->sole()->password));
    }
}
