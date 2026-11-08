<?php

namespace Tests\Feature;

use App\Jobs\DeliverSupportMessage;
use App\Models\AdminSupportTicket;
use App\Models\User;
use App\Services\Support\SupportOperations;
use App\Services\Support\SupportSmtp;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\SupportDatabase;
use Tests\TestCase;

class SupportMailpitTest extends TestCase
{
    use SupportDatabase;

    public function test_real_named_smtp_accepts_in_mailpit_with_stable_thread_headers_and_keeps_auth_mailer(): void
    {
        if (getenv('SUPPORT_MAILPIT_TEST') !== '1') {
            $this->markTestSkipped('Explicit local Mailpit integration run only.');
        }
        Queue::fake();
        config(['mail.mailers.support.host' => '127.0.0.1', 'mail.mailers.support.port' => 1025, 'mail.mailers.support.scheme' => 'smtp', 'mail.mailers.support.username' => null, 'mail.mailers.support.password' => null]);
        $default = config('mail.default');
        $operations = app(SupportOperations::class);
        $operations->submit('support-fixture@example.invalid', 'Mailpit integration fixture', 'mailpit-'.bin2hex(random_bytes(8)));
        $ticket = AdminSupportTicket::firstOrFail();
        $ticket->messages()->first()->update(['message_id' => 'fixture-original@example.invalid']);
        $message = $operations->reply(User::factory()->create(['role' => 'admin_full']), $ticket->id, 'Synthetic reply, local Mailpit only.', $ticket->revision, 'mailpit-reply-'.bin2hex(random_bytes(8)));
        (new DeliverSupportMessage($message->id))->handle(app(SupportSmtp::class));
        $this->assertSame('sent', $message->fresh()->delivery_status);
        $this->assertSame('waiting', $ticket->fresh()->status);
        $this->assertSame($default, config('mail.default'));
        $list = Http::get('http://127.0.0.1:8025/api/v1/messages')->throw()->json('messages');
        $found = collect($list)->first(fn ($mail) => str_contains($mail['Subject'], $ticket->public_number));
        $this->assertNotNull($found);
        $raw = Http::get('http://127.0.0.1:8025/api/v1/message/'.$found['ID'].'/raw')->throw()->body();
        $this->assertStringContainsString('Message-ID: <'.$message->message_id.'>', $raw);
        $this->assertStringContainsString('Reply-To: demo@example.test', $raw);
        $this->assertStringContainsString('In-Reply-To: <fixture-original@example.invalid>', $raw);
        $this->assertStringContainsString('References: <fixture-original@example.invalid>', $raw);
        $this->assertStringContainsString('Auto-Submitted: auto-generated', $raw);
        $this->assertDatabaseCount('support_messages', 2);
    }
}
