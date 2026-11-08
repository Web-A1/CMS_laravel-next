<?php

namespace Tests\Feature;

use App\Jobs\SendPreRegistrationEmailCodeJob;
use App\Models\User;
use App\Services\RedsmsApiClient;
use App\Services\SmtpBzApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class AuthLoggingPrivacyTest extends TestCase
{
    use RefreshDatabase;

    public function test_smtpbz_failure_log_does_not_include_provider_body_or_recipient(): void
    {
        $email = 'private-recipient@example.test';
        $providerBody = 'phone=+79991112233&token=reset-secret&url=https://secret.example/reset';
        config([
            'services.smtpbz.enabled' => true,
            'services.smtpbz.api_key' => 'test-api-key',
            'services.smtpbz.from_email' => 'sender@example.test',
        ]);
        Http::fake(['*' => Http::response($providerBody, 502)]);
        Log::spy();

        $sent = app(SmtpBzApiClient::class)->sendHtmlMessage(
            $email,
            'Subject',
            '<p>reset-secret</p>'
        );

        $this->assertFalse($sent);
        Log::shouldHaveReceived('warning')
            ->once()
            ->with('smtpbz.send_failed', Mockery::on(
                fn (array $context): bool => $this->logContextExcludes($context, [$email, $providerBody, 'reset-secret', '+79991112233'])
            ));
    }

    public function test_pre_registration_mail_failure_log_does_not_include_email_or_code(): void
    {
        $email = 'pre-register-private@example.test';
        $code = '843921';
        $exceptionMessage = "mail failure for {$email} with code {$code}";
        $smtpClient = Mockery::mock(SmtpBzApiClient::class);
        $smtpClient->shouldReceive('sendHtmlMessage')->once()->andReturnFalse();
        Mail::shouldReceive('raw')->once()->andThrow(new RuntimeException($exceptionMessage));
        Log::spy();

        (new SendPreRegistrationEmailCodeJob($email, $code, 10))->handle($smtpClient);

        Log::shouldHaveReceived('warning')
            ->once()
            ->with('mail.pre_registration_email_code.dispatch_failed', Mockery::on(
                fn (array $context): bool => $this->logContextExcludes($context, [$email, $code, $exceptionMessage])
            ));
    }

    public function test_redsms_failure_log_does_not_include_phone_or_provider_message(): void
    {
        $phone = '+79995554433';
        $exceptionMessage = "provider failure for {$phone} token reset-secret";
        $user = User::factory()->create([
            'phone' => null,
            'phone_verified_at' => null,
        ]);
        $redsmsClient = Mockery::mock(RedsmsApiClient::class);
        $redsmsClient->shouldReceive('startWaitCall')
            ->once()
            ->andThrow(new RuntimeException($exceptionMessage));
        $this->app->instance(RedsmsApiClient::class, $redsmsClient);
        config(['services.redsms.mode' => 'live']);
        Sanctum::actingAs($user);
        Log::spy();

        $this->postJson('/api/v1/auth/phone-verification/start', [
            'phone' => $phone,
        ])
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'VERIFY_PROVIDER_FAILED');

        Log::shouldHaveReceived('warning')
            ->once()
            ->with('redsms.phone_verify.start_failed', Mockery::on(
                fn (array $context): bool => $this->logContextExcludes($context, [$phone, $exceptionMessage, 'reset-secret'])
            ));
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  list<string>  $sensitiveValues
     */
    private function logContextExcludes(array $context, array $sensitiveValues): bool
    {
        $encodedContext = json_encode($context, JSON_THROW_ON_ERROR);

        foreach ($sensitiveValues as $sensitiveValue) {
            $this->assertStringNotContainsString($sensitiveValue, $encodedContext);
        }

        return true;
    }
}
