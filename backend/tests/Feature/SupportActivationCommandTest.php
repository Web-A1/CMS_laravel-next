<?php

namespace Tests\Feature;

use App\Models\SupportMailboxState;
use App\Services\Support\SupportImap;
use App\Services\Support\SupportMailbox;
use Mockery;
use Tests\Concerns\SupportDatabase;
use Tests\TestCase;

class SupportActivationCommandTest extends TestCase
{
    use SupportDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['support.imap.enabled' => true, 'support.imap.host' => 'fixture.invalid', 'support.imap.username' => 'fixture', 'support.imap.password' => 'synthetic']);
    }

    private function imap(array $metadata, int $connections = 1): void
    {
        $imap = Mockery::mock(SupportImap::class);
        $imap->shouldReceive('connect')->times($connections)->andReturn($metadata);
        $imap->shouldReceive('disconnect')->atLeast()->once();
        $this->app->instance(SupportImap::class, $imap);
    }

    public function test_default_preview_uses_real_exists_count_with_uid_gaps_and_writes_nothing(): void
    {
        $this->imap(['uidvalidity' => 99, 'uidnext' => 101, 'exists' => 3]);
        $this->artisan('support:activate')
            ->expectsOutput('Ящик: '.config('support.address'))->expectsOutput('Папка: INBOX')
            ->expectsOutput('UIDVALIDITY: 99')->expectsOutput('Граница чтения (UIDNEXT − 1): 100')
            ->expectsOutput('Писем в папке: 3')
            ->expectsOutput('Активация не выполнена. Для подтверждения: php artisan support:activate --confirm')->assertExitCode(0);
        $this->assertDatabaseCount('support_mailbox_states', 0);
        $this->assertDatabaseCount('support_mail_receipts', 0);
        $this->assertDatabaseCount('admin_support_tickets', 0);
        $this->assertDatabaseCount('support_messages', 0);
    }

    public function test_confirm_shows_current_preflight_then_saves_current_boundary_without_import_and_repeat_preserves_it(): void
    {
        $this->imap(['uidvalidity' => 100, 'uidnext' => 107, 'exists' => 4]);
        $this->artisan('support:activate', ['--confirm' => true])
            ->expectsOutput('Ящик: '.config('support.address'))->expectsOutput('Папка: INBOX')
            ->expectsOutput('UIDVALIDITY: 100')->expectsOutput('Граница чтения (UIDNEXT − 1): 106')
            ->expectsOutput('Писем в папке: 4')
            ->expectsOutput('Приём включён. Сохранена граница UID 106 при UIDVALIDITY 100. Исторические письма не импортированы.')->assertExitCode(0);
        $state = SupportMailboxState::firstOrFail();
        $this->assertSame(106, $state->cursor);
        $this->assertSame(106, $state->baseline_uid);
        $this->assertSame(100, $state->baseline_uidvalidity);
        $snapshot = $state->toArray();
        $this->travel(1)->hours();
        $this->artisan('support:activate', ['--confirm' => true])
            ->expectsOutput('Приём уже активирован. Граница UID 106 при UIDVALIDITY 100 сохранена без изменений.')->assertExitCode(0);
        $this->assertSame($snapshot, $state->fresh()->toArray());
        $this->assertDatabaseCount('support_mail_receipts', 0);
        $this->assertDatabaseCount('admin_support_tickets', 0);
    }

    public function test_confirmation_takes_a_fresh_boundary_without_cached_preview_and_prints_before_saving(): void
    {
        $imap = Mockery::mock(SupportImap::class);
        $imap->shouldReceive('connect')->twice()->andReturn(['uidvalidity' => 5, 'uidnext' => 11, 'exists' => 2], ['uidvalidity' => 5, 'uidnext' => 13, 'exists' => 4]);
        $imap->shouldReceive('disconnect')->twice();
        $this->app->instance(SupportImap::class, $imap);
        $mailbox = app(SupportMailbox::class);
        $this->assertSame(10, $mailbox->activation()['baseline_uid']);
        $confirmed = $mailbox->activation(true, function (array $preview): void {
            $this->assertDatabaseCount('support_mailbox_states', 0);
            $this->assertSame(12, $preview['baseline_uid']);
            $this->assertSame(4, $preview['existing_messages']);
        });
        $this->assertSame('active', $confirmed['status']);
        $this->assertSame(12, SupportMailboxState::firstOrFail()->baseline_uid);
    }

    public function test_disabled_and_missing_configuration_never_connect_or_persist_state(): void
    {
        $imap = Mockery::mock(SupportImap::class);
        $imap->shouldNotReceive('connect');
        $imap->shouldReceive('disconnect')->twice();
        $this->app->instance(SupportImap::class, $imap);
        config(['support.imap.enabled' => false]);
        $this->artisan('support:activate')->expectsOutput('Приём писем выключен. Активация не выполнена.')->assertExitCode(1);
        config(['support.imap.enabled' => true, 'support.imap.password' => null]);
        $this->artisan('support:activate', ['--confirm' => true])->expectsOutput('Приём писем не подключён: заполните настройки IMAP. Активация не выполнена.')->assertExitCode(1);
        $this->assertDatabaseCount('support_mailbox_states', 0);
    }

    public function test_unconfirmed_preview_preserves_existing_inactive_state_and_rejects_missing_exists_metadata(): void
    {
        $state = app(SupportMailbox::class)->state();
        $snapshot = $state->fresh()->toArray();
        $this->imap(['uidvalidity' => 6, 'uidnext' => 101]);
        $this->artisan('support:activate', ['--confirm' => true])->expectsOutput('Сервер не подтвердил границу чтения или число писем. Активация не выполнена.')->assertExitCode(1);
        $this->assertSame($snapshot, $state->fresh()->toArray());
        $this->assertDatabaseCount('support_mail_receipts', 0);
    }
}
