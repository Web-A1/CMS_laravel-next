<?php

namespace Tests\Feature;

use App\Console\Commands\ProvisionAdminCommand;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class ProvisionAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_interactive_creation_hashes_password_and_allows_normal_session_admin_access(): void
    {
        $password = Str::random(24);

        $this->artisan('admin:provision', [
            'email' => ' NEW-ADMIN@example.test ',
            'name' => ' Администратор ',
        ])
            ->expectsQuestion('Пароль', $password)
            ->expectsQuestion('Повторите пароль', $password)
            ->expectsOutputToContain('status=created role=admin_full')
            ->assertSuccessful();

        $user = User::query()->sole();
        $this->assertSame('new-admin@example.test', $user->email);
        $this->assertSame('Администратор', $user->name);
        $this->assertSame('admin_full', $user->role);
        $this->assertSame('set', $user->password_state);
        $this->assertNotSame($password, $user->password);
        $this->assertTrue(Hash::check($password, $user->password));
        $this->assertNotNull($user->email_verified_at);
        $this->assertNull($user->phone);
        $this->assertNull($user->phone_verified_at);
        $this->assertFalse($user->legacy_auto_verified);

        $this->postJson('/api/v1/auth/login', [
            'login' => $user->email,
            'password' => $password,
        ])->assertOk()
            ->assertJsonPath('user.role', 'admin_full')
            ->assertJsonPath('next_step', 'admin');
        $this->assertAuthenticatedAs($user, 'web');
        $this->getJson('/api/v1/admin/me')
            ->assertOk()
            ->assertJsonPath('role', 'admin_full')
            ->assertJsonFragment(['admin.access']);
    }

    #[DataProvider('allowedEnvironments')]
    public function test_stdin_provisioning_is_allowed_only_in_named_nonproduction_environments(string $environment): void
    {
        $this->app['env'] = $environment;
        $password = Str::random(24);
        [$exitCode, $output] = $this->runWithStdin($password."\r\n");

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('status=created role=admin_full', $output);
        $this->assertStringNotContainsString($password, $output);
        $this->assertTrue(Hash::check($password, User::query()->sole()->password));
    }

    public static function allowedEnvironments(): array
    {
        return [['local'], ['testing'], ['staging']];
    }

    #[DataProvider('blockedEnvironments')]
    public function test_production_and_unknown_environments_are_rejected_before_password_input(string $environment): void
    {
        $this->app['env'] = $environment;

        $this->artisan('admin:provision', ['email' => 'new-admin@example.test', 'name' => 'Administrator'])
            ->expectsOutputToContain('reason=environment_not_allowed')
            ->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }

    public static function blockedEnvironments(): array
    {
        return [['production'], ['prod'], ['preview']];
    }

    #[DataProvider('existingRoles')]
    public function test_existing_users_are_never_updated_or_promoted(string $role): void
    {
        $user = User::factory()->create([
            'email' => 'Existing-Admin@example.test',
            'role' => $role,
            'password' => Str::random(24),
            'email_verified_at' => null,
        ]);
        $before = $user->fresh()->getRawOriginal();

        $this->artisan('admin:provision', ['email' => 'existing-admin@example.test', 'name' => 'Replacement'])
            ->expectsOutputToContain('reason=email_exists')
            ->assertFailed();

        $this->assertSame($before, $user->fresh()->getRawOriginal());
        $this->assertDatabaseCount('users', 1);
    }

    public static function existingRoles(): array
    {
        return [['admin_full'], ['teacher'], ['user_no_purchase']];
    }

    public function test_invalid_identity_is_rejected_before_password_input(): void
    {
        $this->artisan('admin:provision', ['email' => 'not-an-email', 'name' => ' '])
            ->expectsOutputToContain('reason=invalid_identity')
            ->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_password_confirmation_must_match(): void
    {
        $this->artisan('admin:provision', ['email' => 'new-admin@example.test', 'name' => 'Administrator'])
            ->expectsQuestion('Пароль', Str::random(24))
            ->expectsQuestion('Повторите пароль', Str::random(24))
            ->expectsOutputToContain('reason=password_confirmation_mismatch')
            ->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }

    #[DataProvider('invalidPasswords')]
    public function test_invalid_stdin_password_is_rejected_without_secret_output(string $password): void
    {
        [$exitCode, $output] = $this->runWithStdin($password);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('reason=invalid_password', $output);
        if (strlen($password) >= 8 && trim($password) !== '') {
            $this->assertStringNotContainsString($password, $output);
        }
        $this->assertDatabaseCount('users', 0);
    }

    public static function invalidPasswords(): array
    {
        return [
            'empty' => [''],
            'short' => ['short'],
            'blank' => ['        '],
            'bcrypt_byte_limit' => [str_repeat('a', 73)],
            'multibyte_byte_limit' => [str_repeat('я', 37)],
            'nul' => ["invalid\0secret"],
            'multiple_lines' => ["first-secret\nsecond-secret"],
        ];
    }

    public function test_password_hash_cannot_be_imported_as_a_plain_password(): void
    {
        [$exitCode, $output] = $this->runWithStdin(Hash::make(Str::random(24)));

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('reason=invalid_password', $output);
        $this->assertDatabaseCount('users', 0);
    }

    #[DataProvider('validPasswordLengths')]
    public function test_password_length_boundaries_are_usable_for_login(int $length): void
    {
        $password = Str::random($length);
        [$exitCode] = $this->runWithStdin($password);

        $this->assertSame(0, $exitCode);
        $this->assertTrue(Hash::check($password, User::query()->sole()->password));
    }

    public static function validPasswordLengths(): array
    {
        return ['minimum' => [8], 'bcrypt_maximum' => [72]];
    }

    public function test_noninteractive_execution_without_stdin_option_is_rejected(): void
    {
        $this->artisan('admin:provision', [
            'email' => 'new-admin@example.test',
            'name' => 'Administrator',
            '--no-interaction' => true,
        ])->expectsOutputToContain('reason=password_input_required')->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }

    /** @return array{int, string} */
    private function runWithStdin(#[\SensitiveParameter] string $password): array
    {
        $input = new ArrayInput([
            'email' => 'new-admin@example.test',
            'name' => 'Administrator',
            '--password-stdin' => true,
        ]);
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $password);
        rewind($stream);
        $input->setStream($stream);
        $input->setInteractive(false);
        $output = new BufferedOutput;
        $command = $this->app->make(ProvisionAdminCommand::class);
        $command->setLaravel($this->app);

        try {
            $exitCode = $command->run($input, $output);

            return [$exitCode, $output->fetch()];
        } finally {
            fclose($stream);
        }
    }
}
