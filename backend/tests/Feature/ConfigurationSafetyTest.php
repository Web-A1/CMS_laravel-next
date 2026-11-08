<?php

namespace Tests\Feature;

use App\Models\User;
use App\Providers\AppServiceProvider;
use App\Services\SmtpBzApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class ConfigurationSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_production_debug_fails_closed(): void
    {
        config([
            'app.env' => 'production',
            'app.debug' => true,
            'services.redsms.mode' => 'live',
        ]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('APP_DEBUG must be disabled in production.');

        try {
            (new AppServiceProvider($this->app))->boot();
        } finally {
            $this->assertFalse(config('app.debug'));
        }
    }

    public function test_production_boots_with_debug_disabled(): void
    {
        config([
            'app.env' => 'production',
            'app.debug' => false,
            'services.redsms.mode' => 'live',
        ]);

        (new AppServiceProvider($this->app))->boot();

        $this->assertFalse(config('app.debug'));
    }

    public function test_staging_debug_remains_allowed(): void
    {
        config([
            'app.env' => 'staging',
            'app.debug' => true,
            'services.redsms.mode' => 'live',
        ]);

        (new AppServiceProvider($this->app))->boot();

        $this->assertTrue(config('app.debug'));
    }

    public function test_redsms_mock_mode_boots_in_local_environment(): void
    {
        config([
            'app.env' => 'local',
            'services.redsms.mode' => 'mock',
        ]);

        (new AppServiceProvider($this->app))->boot();

        $this->assertTrue(true);
    }

    public function test_redsms_mock_mode_fails_closed_outside_local_and_testing(): void
    {
        config([
            'app.env' => 'staging',
            'services.redsms.mode' => 'mock',
        ]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('REDSMS mock mode is allowed only in local and testing environments.');

        (new AppServiceProvider($this->app))->boot();
    }

    public function test_password_reset_url_uses_loaded_config_when_environment_drifts(): void
    {
        $user = User::factory()->create([
            'email' => 'reset-config@example.test',
        ]);
        $smtpClient = new class extends SmtpBzApiClient
        {
            public ?string $html = null;

            public function sendHtmlMessage(
                string $toEmail,
                string $subject,
                string $html,
                ?string $toName = null
            ): bool {
                $this->html = $html;

                return true;
            }
        };
        $this->app->instance(SmtpBzApiClient::class, $smtpClient);
        config(['app.frontend_url' => 'https://cached-frontend.example']);

        $previousFrontendUrl = getenv('FRONTEND_URL');
        putenv('FRONTEND_URL=https://runtime-drift.example');

        try {
            $this->postJson('/api/v1/auth/password/forgot', [
                'email' => $user->email,
            ])->assertAccepted();
        } finally {
            $previousFrontendUrl === false
                ? putenv('FRONTEND_URL')
                : putenv('FRONTEND_URL='.$previousFrontendUrl);
        }

        $this->assertNotNull($smtpClient->html);
        $this->assertStringContainsString('https://cached-frontend.example/auth?mode=reset', $smtpClient->html);
        $this->assertStringNotContainsString('runtime-drift.example', $smtpClient->html);
    }
}
