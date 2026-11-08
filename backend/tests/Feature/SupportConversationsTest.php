<?php

namespace Tests\Feature;

use App\Jobs\DeliverSupportMessage;
use App\Jobs\ProcessSupportImage;
use App\Models\AdminOperationEvent;
use App\Models\AdminSupportTicket;
use App\Models\SupportMailboxState;
use App\Models\SupportMailReceipt;
use App\Models\SupportMessage;
use App\Models\User;
use App\Services\Support\SupportImap;
use App\Services\Support\SupportInbound;
use App\Services\Support\SupportMailbox;
use App\Services\Support\SupportOperations;
use App\Services\Support\SupportRecovery;
use App\Services\Support\SupportSmtp;
use App\Services\Support\SupportSmtpFailure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\SupportDatabase;
use Tests\TestCase;

class SupportConversationsTest extends TestCase
{
    use SupportDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('support-private');
        config(['support.ip_per_hour' => 1000, 'support.email_per_hour' => 1000]);
    }

    private function submit(string $key = 'support-submit-001', array $extra = [])
    {
        return $this->withHeader('Idempotency-Key', $key)->postJson('/api/v1/support-tickets', ['email' => 'Guest@EXAMPLE.test', 'description' => 'Помогите с уроком', ...$extra]);
    }

    public function test_public_submission_is_private_idempotent_and_does_not_claim_account(): void
    {
        User::factory()->create(['email' => 'guest@example.test']);
        $first = $this->submit()->assertCreated()->assertJsonStructure(['data' => ['number', 'warnings']]);
        $this->assertStringContainsString('no-store', $first->headers->get('Cache-Control'));
        $this->submit()->assertCreated()->assertExactJson($first->json());
        $this->submit(extra: ['description' => 'другой текст'])->assertConflict();
        $this->assertDatabaseCount('admin_support_tickets', 1);
        $this->assertDatabaseCount('support_messages', 1);
        $this->assertNull(AdminSupportTicket::first()->user_id);
        $this->assertSame('guest@example.test', AdminSupportTicket::first()->contact_email);
        $this->assertMatchesRegularExpression('/^SUP-[ABCDEFGHJKLMNPQRSTUVWXYZ23456789]{7}$/', $first->json('data.number'));
        $this->getJson('/api/v1/admin/support-tickets/1')->assertUnauthorized();
        $this->getJson('/api/v1/support-tickets/1')->assertNotFound();
    }

    public function test_required_fields_honeypot_admin_fields_and_throttling(): void
    {
        $this->submit(extra: ['email' => 'bad'])->assertUnprocessable()->assertJsonPath('error.code', 'SUPPORT_VALIDATION_FAILED');
        $this->submit(extra: ['website' => 'spam'])->assertUnprocessable();
        foreach (['user_id', 'assignee_id', 'status', 'delivery_status', 'metadata'] as $field) {
            $this->submit(extra: [$field => 'forged'])->assertUnprocessable();
        }
        $this->assertDatabaseCount('admin_support_tickets', 0);
        config(['support.email_per_hour' => 1]);
        $this->submit('rate-limit-key1', ['email' => 'fresh@example.test'])->assertCreated();
        $this->submit('rate-limit-key2', ['email' => 'FRESH@example.test'])->assertStatus(429)->assertHeader('Retry-After')->assertJsonPath('error.code', 'SUPPORT_RATE_LIMIT');
    }

    public function test_bad_image_does_not_lose_text_and_repeat_does_not_duplicate(): void
    {
        $file = UploadedFile::fake()->createWithContent('photo.jpg', '<svg onload="alert(1)"></svg>');
        $payload = ['email' => 'guest@example.test', 'description' => 'Текст остаётся', 'images' => [$file]];
        $response = $this->withHeader('Idempotency-Key', 'bad-image-key')->post('/api/v1/support-tickets', $payload, ['Accept' => 'application/json'])->assertCreated()->assertJsonCount(1, 'data.warnings');
        $payload['images'] = [UploadedFile::fake()->createWithContent('photo.jpg', '<svg onload="alert(1)"></svg>')];
        $this->withHeader('Idempotency-Key', 'bad-image-key')->post('/api/v1/support-tickets', $payload, ['Accept' => 'application/json'])->assertExactJson($response->json());
        $this->assertDatabaseCount('support_attachments', 0);
        $this->assertDatabaseHas('support_messages', ['body' => 'Текст остаётся']);
    }

    public function test_php_per_file_upload_failure_keeps_text_and_returns_web_warning(): void
    {
        $file = UploadedFile::fake()->image('image.png', 20, 20);
        $invalid = new UploadedFile($file->getRealPath(), 'too-large.png', 'image/png', UPLOAD_ERR_INI_SIZE, true);
        $this->withHeader('Idempotency-Key', 'php-upload-error-key')->post('/api/v1/support-tickets', ['email' => 'guest@example.test', 'description' => 'Keep text after PHP limit', 'images' => [$invalid]], ['Accept' => 'application/json'])->assertCreated()->assertJsonCount(1, 'data.warnings');
        $this->assertDatabaseHas('support_messages', ['body' => 'Keep text after PHP limit']);
        $this->assertDatabaseCount('support_attachments', 0);
    }

    public function test_delete_is_405_and_history_files_and_audit_remain(): void
    {
        $this->submit()->assertCreated();
        $ticket = AdminSupportTicket::first();
        $admin = User::factory()->create(['role' => 'admin_full']);
        app(SupportOperations::class)->change($admin, $ticket->id, ['revision' => $ticket->revision, 'status' => 'in_progress'], 'status-key');
        $attachment = $ticket->messages->first()->attachments()->create(['disk' => 'support-private', 'source_key' => 'source', 'mime' => 'image/png', 'bytes' => 5]);
        Storage::disk('support-private')->put('source', 'bytes');
        Sanctum::actingAs($admin);
        $this->withHeader('Idempotency-Key', 'delete-key')->deleteJson('/api/v1/admin/support-tickets/'.$ticket->id)->assertStatus(405);
        $this->assertDatabaseCount('admin_support_tickets', 1);
        $this->assertDatabaseCount('support_messages', 1);
        $this->assertDatabaseCount('support_attachments', 1);
        $this->assertDatabaseCount('admin_operation_events', 1);
        Storage::disk('support-private')->assertExists('source');
        $this->assertNotNull($attachment->fresh());
        Queue::assertNothingPushed();
    }

    public function test_revision_idempotent_reply_and_late_smtp_do_not_overwrite_manual_status(): void
    {
        $this->submit();
        $ticket = AdminSupportTicket::first();
        $actor = User::factory()->create(['role' => 'admin_full']);
        $operations = app(SupportOperations::class);
        $message = $operations->reply($actor, $ticket->id, 'Ответ оператора', $ticket->revision, 'operator-key');
        $again = $operations->reply($actor, $ticket->id, 'Ответ оператора', $ticket->revision, 'operator-key');
        $this->assertSame($message->id, $again->id);
        $operations->change($actor, $ticket->id, ['revision' => $ticket->fresh()->revision, 'status' => 'closed'], 'close-key');
        $smtp = Mockery::mock(SupportSmtp::class)->shouldReceive('send')->once()->getMock();
        (new DeliverSupportMessage($message->id))->handle($smtp);
        (new DeliverSupportMessage($message->id))->handle($smtp);
        $this->assertSame('sent', $message->fresh()->delivery_status);
        $this->assertSame('closed', $ticket->fresh()->status);
        DB::transaction(fn () => $operations->inbound($ticket, 'Есть ещё вопрос', 'guest@example.test'));
        $this->assertSame('new', $ticket->fresh()->status);
        $this->assertDatabaseHas('admin_operation_events', ['operation' => 'inbound_reopen', 'actor_id' => null]);
        Sanctum::actingAs($actor);
        $this->withHeader('Idempotency-Key', 'stale-reply-key')->postJson('/api/v1/admin/support-tickets/'.$ticket->id.'/reply', ['body' => 'Не потерять текст', 'revision' => 1])->assertConflict();
        $this->withHeader('Idempotency-Key', 'edit-body-key')->patchJson('/api/v1/admin/support-tickets/'.$ticket->id, ['description' => 'Подмена', 'revision' => $ticket->fresh()->revision])->assertUnprocessable();
        $this->assertSame('Помогите с уроком', $ticket->fresh()->description);
    }

    public function test_status_parameters_preserve_metadata_noop_and_never_enter_media_close(): void
    {
        $actor = User::factory()->create(['role' => 'admin_full']);
        $operations = app(SupportOperations::class);
        $ticket = $operations->createTicket('guest@example.test', 'Stored body', 'admin');
        $first = $operations->parameters($actor, $ticket->id, ['status' => 'in_progress'], 1, 'status-parameter-key');
        $this->assertSame(2, $first->revision);
        $this->assertSame('guest@example.test', $first->contact_email);
        $this->assertSame('Stored body', $first->description);
        $operations->parameters($actor, $ticket->id, ['status' => 'in_progress'], 1, 'status-parameter-key');
        $operations->parameters($actor, $ticket->id, ['status' => 'in_progress'], 2, 'status-noop-key');
        $operations->change($actor, $ticket->id, ['revision' => 2, 'contact_email' => 'GUEST@EXAMPLE.TEST'], 'email-noop-key');
        $this->assertSame(2, $ticket->fresh()->revision);
        $this->assertSame(1, AdminOperationEvent::count());
        foreach ([['status' => 'closed'], ['status' => 'waiting', 'contact_email' => 'draft@example.test'], ['status' => 'waiting', 'replyBody' => 'Do not send']] as $patch) {
            try {
                $operations->parameters($actor, $ticket->id, $patch, 2, 'refused-parameter-key');
                $this->fail('Invalid parameter patch must be refused.');
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
            }
        }
        try {
            $operations->parameters($actor, $ticket->id, ['status' => 'waiting'], 1, 'stale-status-key');
            $this->fail('Stale CAS must be refused.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        $this->assertDatabaseCount('support_media_deletions', 0);
        $this->assertDatabaseCount('support_messages', 0);
        Queue::assertNothingPushed();
    }

    public function test_smtp_rejections_unknown_outcome_lost_dispatch_and_expired_lease(): void
    {
        $this->submit();
        $ticket = AdminSupportTicket::first();
        $actor = User::factory()->create(['role' => 'admin_full']);
        $message = app(SupportOperations::class)->reply($actor, $ticket->id, 'Ответ', $ticket->revision, 'smtp-retry-key');
        $smtp = Mockery::mock(SupportSmtp::class)->shouldReceive('send')->once()->andThrow(new SupportSmtpFailure('SMTP_TEMPORARY_REJECTION', true))->getMock();
        (new DeliverSupportMessage($message->id))->handle($smtp);
        $this->assertSame('pending', $message->fresh()->delivery_status);
        $this->assertTrue($message->fresh()->next_attempt_at->isFuture());
        $message->update(['next_attempt_at' => now()->subSecond()]);
        app(SupportRecovery::class)->run();
        Queue::assertPushed(DeliverSupportMessage::class);
        $smtp = Mockery::mock(SupportSmtp::class)->shouldReceive('send')->once()->andThrow(new SupportSmtpFailure('SMTP_OUTCOME_UNKNOWN', false, true))->getMock();
        (new DeliverSupportMessage($message->id))->handle($smtp);
        $this->assertSame('delivery_unknown', $message->fresh()->delivery_status);
        app(SupportOperations::class)->retry($actor, $message->id, 'explicit-retry-key');
        $message->update(['delivery_status' => 'sending', 'claimed_at' => now()->subHour()]);
        app(SupportRecovery::class)->run();
        $this->assertSame('delivery_unknown', $message->fresh()->delivery_status);
    }

    public function test_terminal_image_failure_creates_one_notice_without_changing_status(): void
    {
        $this->submit();
        $ticket = AdminSupportTicket::first();
        $source = $ticket->messages->first();
        $bad = $source->attachments()->create(['disk' => 'support-private', 'source_key' => 'bad', 'mime' => 'image/png', 'bytes' => 3]);
        $source->attachments()->create(['disk' => 'support-private', 'preview_key' => 'ready', 'mime' => 'image/png', 'bytes' => 3, 'status' => 'ready']);
        $job = new ProcessSupportImage($bad->id);
        $job->failed(new \RuntimeException('private stack'));
        $job->failed(new \RuntimeException('repeat'));
        app(SupportRecovery::class)->run();
        $this->assertDatabaseCount('support_messages', 2);
        $notice = SupportMessage::where('direction', 'notice')->firstOrFail();
        $this->assertSame('image-failure:'.$source->id, $notice->logical_key);
        $this->assertSame('pending', $notice->delivery_status);
        $this->assertSame('new', $ticket->fresh()->status);
        $this->assertSame('Помогите с уроком', $source->fresh()->body);
        $this->assertDatabaseCount('support_attachments', 2);
    }

    public function test_private_image_requires_current_permission_and_ticket_relationship(): void
    {
        $this->submit();
        $ticket = AdminSupportTicket::first();
        $attachment = $ticket->messages->first()->attachments()->create(['disk' => 'support-private', 'preview_key' => 'ready', 'mime' => 'image/png', 'bytes' => 3, 'status' => 'ready']);
        Storage::disk('support-private')->put('ready', 'safe webp bytes');
        $url = '/api/v1/admin/support-tickets/'.$ticket->id.'/attachments/'.$attachment->id;
        $this->getJson($url)->assertUnauthorized();
        foreach (['user', 'admin_limited', 'manager', 'teacher'] as $role) {
            Sanctum::actingAs(User::factory()->create(['role' => $role]));
            $this->getJson($url)->assertForbidden();
        }
        $admin = User::factory()->create(['role' => 'admin_full']);
        Sanctum::actingAs($admin);
        $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/webp')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get('/api/v1/admin/support-tickets/999/attachments/'.$attachment->id)->assertNotFound();
        $admin->update(['role' => 'admin_limited']);
        $this->getJson($url)->assertForbidden();
    }

    private function receipt(string $raw, int $uid): SupportMailReceipt
    {
        $key = 'mail/'.$uid.'.eml';
        Storage::disk('support-private')->put($key, $raw);

        return SupportMailReceipt::create(['mailbox' => config('support.address'), 'folder' => 'INBOX', 'uidvalidity' => 1, 'uid' => $uid, 'disk' => 'support-private', 'original_key' => $key, 'intake_status' => 'eligible']);
    }

    public function test_short_number_collision_retries_but_other_unique_constraints_do_not(): void
    {
        $operations = app(SupportOperations::class);
        $existing = $operations->createTicket('guest@example.test', 'First', 'admin', ['submission_key' => 'unique-submission']);
        $calls = 0;
        AdminSupportTicket::creating(function ($ticket) use ($existing, &$calls): void {
            $calls++;
            if ($calls === 1) {
                $ticket->public_number = $existing->public_number;
            }
        });
        try {
            $created = DB::transaction(fn () => $operations->createTicket('guest@example.test', 'Second', 'admin'));
            $this->assertSame(2, $calls);
            $this->assertNotSame($existing->public_number, $created->public_number);
            $this->assertMatchesRegularExpression('/^SUP-[ABCDEFGHJKLMNPQRSTUVWXYZ23456789]{7}$/', $created->public_number);
            $calls = 1;
            try {
                $operations->createTicket('guest@example.test', 'Duplicate submission', 'admin', ['submission_key' => 'unique-submission']);
                $this->fail('A submission key violation must propagate.');
            } catch (UniqueConstraintViolationException) {
                $this->assertSame(2, $calls);
            }
        } finally {
            AdminSupportTicket::flushEventListeners();
        }
    }

    public function test_number_collision_retries_are_bounded(): void
    {
        $operations = app(SupportOperations::class);
        $existing = $operations->createTicket('guest@example.test', 'First', 'admin');
        $calls = 0;
        AdminSupportTicket::creating(function ($ticket) use ($existing, &$calls): void {
            $calls++;
            $ticket->public_number = $existing->public_number;
        });
        try {
            $operations->createTicket('guest@example.test', 'Collision', 'admin');
            $this->fail('Repeated collisions must stop.');
        } catch (UniqueConstraintViolationException) {
            $this->assertSame(5, $calls);
            $this->assertDatabaseCount('admin_support_tickets', 1);
        } finally {
            AdminSupportTicket::flushEventListeners();
        }
    }

    public function test_submission_key_detail_cannot_masquerade_as_number_constraint(): void
    {
        $calls = 0;
        $previous = new \PDOException('duplicate submission');
        $previous->errorInfo = ['23505', 7, "ERROR:  duplicate key value violates unique constraint \"admin_support_tickets_submission_key_unique\"\nDETAIL:  Key (submission_key)=(admin_support_tickets_public_number_unique admin_support_tickets.public_number) already exists."];
        $exception = new UniqueConstraintViolationException('pgsql', 'insert into admin_support_tickets', [], $previous);
        AdminSupportTicket::creating(function () use (&$calls, $exception): void {
            $calls++;
            throw $exception;
        });
        try {
            app(SupportOperations::class)->createTicket('guest@example.test', 'Original', 'admin');
            $this->fail('An unrelated constraint with misleading detail must propagate.');
        } catch (UniqueConstraintViolationException $caught) {
            $this->assertSame($exception, $caught);
            $this->assertSame(1, $calls);
        } finally {
            AdminSupportTicket::flushEventListeners();
        }
    }

    public function test_current_and_old_subject_numbers_thread_only_for_original_sender(): void
    {
        $operations = app(SupportOperations::class);
        foreach (['SUP-ABCD234', 'SUP-ABCD2345', 'SUP-ABCDEFGHIJKLMNOPQRST'] as $index => $number) {
            $ticket = $operations->createTicket('guest@example.test', 'Original', 'web');
            $ticket->update(['public_number' => $number]);
            $raw = str_replace('Subject: Support question', 'Subject: Re: '.$number, $this->raw('number-'.$index.'@example.test'));
            $receipt = $this->receipt($raw, 20 + $index);
            app(SupportInbound::class)->accept($receipt);
            $this->assertSame('accepted', $receipt->fresh()->outcome);
            $this->assertSame($ticket->id, SupportMessage::findOrFail($receipt->fresh()->support_message_id)->ticket_id);
            $wrong = str_replace(['Guest <guest@example.test>', 'number-'.$index.'@example.test'], ['Other <other@example.test>', 'wrong-'.$index.'@example.test'], $raw);
            $wrongReceipt = $this->receipt($wrong, 30 + $index);
            app(SupportInbound::class)->accept($wrongReceipt);
            $this->assertSame('AMBIGUOUS_THREAD', $wrongReceipt->fresh()->reason);
            $this->assertSame($number, $ticket->fresh()->public_number);
        }
        $this->assertDatabaseCount('admin_support_tickets', 3);
        $this->assertDatabaseCount('support_messages', 3);
    }

    private function raw(string $id = 'incoming1@example.test', string $extra = '', string $body = 'Вопрос из почты'): string
    {
        return "From: Guest <guest@example.test>\r\nTo: demo@example.test\r\nDate: Wed, 28 Oct 2026 12:00:00 +0300\r\nMessage-ID: <{$id}>\r\nSubject: Support question\r\nMIME-Version: 1.0\r\n{$extra}Content-Type: text/plain; charset=utf-8\r\n\r\n{$body}";
    }

    private function webReply(AdminSupportTicket $ticket, string $id = 'incoming1@example.test', string $extra = '', string $body = 'Вопрос из почты'): string
    {
        return str_replace('Subject: Support question', 'Subject: Re: '.$ticket->public_number, $this->raw($id, $extra, $body));
    }

    public function test_unrelated_direct_mail_is_ignored_without_fetching_or_storing_its_body(): void
    {
        config(['support.imap.enabled' => true]);
        $mailbox = app(SupportMailbox::class);
        $mailbox->state()->update(['activated_at' => now(), 'baseline_uidvalidity' => 1, 'uidvalidity' => 1, 'baseline_uid' => 0, 'cursor' => 0, 'status' => 'active']);
        $imap = Mockery::mock(SupportImap::class);
        $imap->shouldReceive('connect')->once()->andReturn(['uidvalidity' => 1, 'uidnext' => 2]);
        $imap->shouldReceive('disconnect')->once();
        $imap->shouldReceive('metadata')->once()->with(1, 1)->andReturn([1 => ['bytes' => config('support.max_mail_bytes') + 1, 'received_at' => now()->toISOString()]]);
        $imap->shouldReceive('headers')->once()->with(1)->andReturn(strstr($this->raw(), "\r\n\r\n", true)."\r\n");
        $imap->shouldNotReceive('raw');
        $this->app->instance(SupportImap::class, $imap);

        $this->assertSame('active', $mailbox->run());
        $this->assertDatabaseHas('support_mail_receipts', ['uid' => 1, 'outcome' => 'ignored', 'reason' => 'NOT_WEB_TICKET', 'original_key' => null]);
        $this->assertDatabaseCount('admin_support_tickets', 0);
        $this->assertSame([], Storage::disk('support-private')->allFiles());
    }

    public function test_known_web_ticket_bounce_remains_visible_to_delivery_tracking(): void
    {
        $ticket = app(SupportOperations::class)->createTicket('guest@example.test', 'From website', 'web');
        $outbound = $ticket->messages()->create([
            'direction' => 'outbound', 'body' => 'Reply', 'sender' => config('support.address'),
            'message_id' => 'sent-web-reply@example.test', 'delivery_status' => 'sent',
        ]);
        $raw = "From: Mailer-Daemon <mailer-daemon@example.test>\r\nTo: demo@example.test\r\nDate: Wed, 28 Oct 2026 12:00:00 +0000\r\nMessage-ID: <bounce@example.test>\r\nIn-Reply-To: <sent-web-reply@example.test>\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nDelivery failed";
        $headers = strstr($raw, "\r\n\r\n", true)."\r\n";
        $this->assertTrue(app(SupportInbound::class)->belongsToWebTicket($headers));

        $receipt = $this->receipt($raw, 1);
        app(SupportInbound::class)->accept($receipt);
        $this->assertSame('ignored', $receipt->fresh()->outcome);
        $this->assertSame('DELIVERY_STATUS', $receipt->fresh()->reason);
        $this->assertSame('failed', $outbound->fresh()->delivery_status);
        $this->assertSame('BOUNCE_RECEIVED', $outbound->fresh()->error_code);
        $this->assertDatabaseCount('admin_support_tickets', 1);
    }

    public function test_header_failure_keeps_cursor_and_retries_the_web_reply(): void
    {
        $ticket = app(SupportOperations::class)->createTicket('guest@example.test', 'From website', 'web');
        config(['support.imap.enabled' => true]);
        $mailbox = app(SupportMailbox::class);
        $mailbox->state()->update(['activated_at' => now(), 'baseline_uidvalidity' => 1, 'uidvalidity' => 1, 'baseline_uid' => 0, 'cursor' => 0, 'status' => 'active']);
        $raw = $this->webReply($ticket);
        $failed = Mockery::mock(SupportImap::class);
        $failed->shouldReceive('connect')->once()->andReturn(['uidvalidity' => 1, 'uidnext' => 2]);
        $failed->shouldReceive('disconnect')->once();
        $failed->shouldReceive('metadata')->once()->with(1, 1)->andReturn([1 => ['bytes' => strlen($raw), 'received_at' => now()->toISOString()]]);
        $failed->shouldReceive('headers')->once()->with(1)->andThrow(new \RuntimeException('temporary header failure'));
        $failed->shouldNotReceive('raw');
        $this->app->instance(SupportImap::class, $failed);

        $this->assertSame('connection_failed', $mailbox->run());
        $this->assertSame(0, $mailbox->state()->fresh()->cursor);
        $this->assertDatabaseHas('support_mail_receipts', ['uid' => 1, 'outcome' => 'pending', 'original_key' => null]);

        $working = Mockery::mock(SupportImap::class);
        $working->shouldReceive('connect')->once()->andReturn(['uidvalidity' => 1, 'uidnext' => 2]);
        $working->shouldReceive('disconnect')->once();
        $working->shouldReceive('metadata')->once()->with(1, 1)->andReturn([1 => ['bytes' => strlen($raw), 'received_at' => now()->toISOString()]]);
        $working->shouldReceive('headers')->once()->with(1)->andReturn(strstr($raw, "\r\n\r\n", true)."\r\n");
        $working->shouldReceive('raw')->once()->with(1)->andReturn($raw);
        $this->app->instance(SupportImap::class, $working);

        $this->assertSame('active', $mailbox->run());
        $this->assertSame(1, $mailbox->state()->fresh()->cursor);
        $this->assertDatabaseHas('support_mail_receipts', ['uid' => 1, 'outcome' => 'accepted']);
        $this->assertDatabaseCount('support_messages', 1);
    }

    public function test_permanent_header_failures_are_quarantined_without_blocking_the_next_web_reply(): void
    {
        $ticket = app(SupportOperations::class)->createTicket('guest@example.test', 'From website', 'web');
        config(['support.imap.enabled' => true]);
        $mailbox = app(SupportMailbox::class);
        $mailbox->state()->update(['activated_at' => now(), 'baseline_uidvalidity' => 1, 'uidvalidity' => 1, 'baseline_uid' => 0, 'cursor' => 0, 'status' => 'active']);
        $raw = $this->webReply($ticket);
        $imap = Mockery::mock(SupportImap::class);
        $imap->shouldReceive('connect')->once()->andReturn(['uidvalidity' => 1, 'uidnext' => 4]);
        $imap->shouldReceive('disconnect')->once();
        $imap->shouldReceive('metadata')->once()->with(1, 3)->andReturn([
            1 => ['bytes' => 70000, 'received_at' => now()->toISOString()],
            2 => ['bytes' => 120, 'received_at' => now()->toISOString()],
            3 => ['bytes' => strlen($raw), 'received_at' => now()->toISOString()],
        ]);
        $imap->shouldReceive('headers')->once()->with(1)->andThrow(new \RuntimeException('HEADER_TOO_LARGE'));
        $imap->shouldReceive('headers')->once()->with(2)->andReturn("From: unrelated@example.invalid\r\nDate: not-a-date\r\nSubject: ordinary mail\r\n\r\n");
        $imap->shouldReceive('headers')->once()->with(3)->andReturn(strstr($raw, "\r\n\r\n", true)."\r\n");
        $imap->shouldReceive('raw')->once()->with(3)->andReturn($raw);
        $this->app->instance(SupportImap::class, $imap);

        $this->assertSame('active', $mailbox->run());
        $this->assertSame(3, $mailbox->state()->fresh()->cursor);
        $this->assertDatabaseHas('support_mail_receipts', ['uid' => 1, 'outcome' => 'quarantine', 'reason' => 'HEADER_TOO_LARGE']);
        $this->assertDatabaseHas('support_mail_receipts', ['uid' => 2, 'outcome' => 'quarantine', 'reason' => 'HEADER_INVALID']);
        $this->assertDatabaseHas('support_mail_receipts', ['uid' => 3, 'outcome' => 'accepted']);
        $this->assertDatabaseCount('support_messages', 1);
    }

    public function test_real_mime_parser_existing_web_reply_duplicate_html_collision_and_loop(): void
    {
        $inbound = app(SupportInbound::class);
        $ticket = app(SupportOperations::class)->createTicket('guest@example.test', 'From website', 'web');
        $raw = $this->webReply($ticket);
        $receipt = $this->receipt($raw, 1);
        $inbound->accept($receipt);
        $this->assertSame('accepted', $receipt->fresh()->outcome);
        $duplicate = $this->receipt($raw, 2);
        $inbound->accept($duplicate);
        $this->assertSame('duplicate', $duplicate->fresh()->outcome);
        $ticket->update(['status' => 'closed']);
        $reply = $this->receipt($this->raw('reply@example.test', "In-Reply-To: <incoming1@example.test>\r\n"), 3);
        $inbound->accept($reply);
        $this->assertSame('accepted', $reply->fresh()->outcome);
        $this->assertSame('new', $ticket->fresh()->status);
        $collision = $this->receipt($this->raw(body: 'Different bytes'), 4);
        $inbound->accept($collision);
        $this->assertSame('MESSAGE_ID_COLLISION', $collision->fresh()->reason);
        $loop = $this->receipt($this->raw('auto@example.test', "Auto-Submitted: auto-replied\r\n"), 5);
        $inbound->accept($loop);
        $this->assertSame('ignored', $loop->fresh()->outcome);
        $html = str_replace('text/plain', 'text/html', $this->webReply($ticket, 'html@example.test', body: '<p>Safe text</p><script>evil()</script><img src="https://tracker.invalid/x">'));
        $htmlReceipt = $this->receipt($html, 6);
        $inbound->accept($htmlReceipt);
        $this->assertSame('accepted', $htmlReceipt->fresh()->outcome);
        $this->assertDatabaseHas('support_messages', ['body' => 'Safe text']);
        $this->assertDatabaseCount('admin_support_tickets', 1);
        $this->assertDatabaseCount('support_messages', 3);
    }

    public function test_inbound_rejected_attachment_notifies_once_and_auto_mail_never_notifies(): void
    {
        $ticket = app(SupportOperations::class)->createTicket('guest@example.test', 'From website', 'web');
        $raw = preg_replace('/Content-Type: text\/plain; charset=utf-8/', 'Content-Type: multipart/mixed; boundary="fixture"', $this->webReply($ticket, 'withfile@example.test', body: "--fixture\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nТекст сохранён\r\n--fixture\r\nContent-Type: application/pdf\r\nContent-Disposition: attachment; filename=bad.pdf\r\n\r\nnot an image\r\n--fixture--"), 1);
        $receipt = $this->receipt($raw, 1);
        app(SupportInbound::class)->accept($receipt);
        $this->assertSame('accepted', $receipt->fresh()->outcome, $receipt->fresh()->reason ?? 'none');
        app(SupportRecovery::class)->run();
        $this->assertDatabaseCount('support_messages', 2);
        app(SupportInbound::class)->accept($receipt->fresh());
        app(SupportRecovery::class)->run();
        $this->assertDatabaseCount('support_messages', 2);
    }

    public function test_activation_excludes_old_uid_and_recovery_accepts_new_uid_without_reset_skip(): void
    {
        $ticket = app(SupportOperations::class)->createTicket('guest@example.test', 'From website', 'web');
        config(['support.imap.enabled' => true]);
        $imap = Mockery::mock(SupportImap::class);
        $imap->shouldReceive('connect')->once()->andReturn(['uidvalidity' => 1, 'uidnext' => 51]);
        $imap->shouldReceive('disconnect')->once();
        $this->app->instance(SupportImap::class, $imap);
        $mailbox = app(SupportMailbox::class);
        $this->assertSame('active', $mailbox->run(activate: true));
        $state = SupportMailboxState::first();
        $this->assertSame(50, $state->cursor);
        $baseline = $state->activated_at;
        $this->travel(1)->minutes();
        $imap = Mockery::mock(SupportImap::class);
        $imap->shouldReceive('connect')->twice()->andReturn(['uidvalidity' => 2, 'uidnext' => 3]);
        $imap->shouldReceive('disconnect')->twice();
        $imap->shouldReceive('metadata')->once()->with(1, 2)->andReturn([1 => ['bytes' => 500, 'received_at' => $baseline->copy()->subDay()->toISOString()], 2 => ['bytes' => 500, 'received_at' => now()->toISOString()]]);
        $imap->shouldReceive('headers')->with(1)->once()->andReturn($this->webReply($ticket, 'old@example.test'));
        $imap->shouldReceive('headers')->with(2)->once()->andReturn($this->webReply($ticket, 'new@example.test'));
        $imap->shouldReceive('raw')->with(1)->once()->andReturn($this->webReply($ticket, 'old@example.test'));
        $imap->shouldReceive('raw')->with(2)->once()->andReturn($this->webReply($ticket, 'new@example.test'));
        $this->app->instance(SupportImap::class, $imap);
        $this->assertSame('recovery_required', $mailbox->run());
        $this->assertSame(50, $state->fresh()->cursor);
        $this->assertSame('active', $mailbox->run(recover: true));
        $this->assertSame('accepted', SupportMailReceipt::where('uid', 2)->firstOrFail()->outcome, SupportMailReceipt::all()->toJson());
        $this->assertSame(2, $state->fresh()->cursor);
        $this->assertTrue($baseline->eq($state->fresh()->activated_at));
        $this->assertDatabaseCount('admin_support_tickets', 1);
        $this->assertDatabaseHas('support_mail_receipts', ['uid' => 1, 'outcome' => 'historical']);
        $this->assertDatabaseHas('support_mail_receipts', ['uid' => 2, 'outcome' => 'accepted']);
    }

    public function test_not_configured_and_connection_failure_are_visible_without_global_health_change(): void
    {
        config(['mail.mailers.support.host' => null, 'support.imap.host' => null, 'support.imap.username' => null, 'support.imap.password' => null]);
        $health = app(SupportRecovery::class)->health();
        $this->assertSame('not_configured', $health['imap']);
        $this->assertSame('not_configured', $health['smtp']);
        config(['support.imap.enabled' => true]);
        $this->assertSame('not_configured', app(SupportMailbox::class)->run(activate: true));
        $imap = Mockery::mock(SupportImap::class);
        $imap->shouldReceive('connect')->once()->andThrow(new \RuntimeException('Private connection transcript'));
        $imap->shouldReceive('disconnect')->once();
        $this->app->instance(SupportImap::class, $imap);
        $this->assertSame('connection_failed', app(SupportMailbox::class)->run(activate: true));
        $this->assertSame('IMAP_CONNECTION_FAILED', SupportMailboxState::first()->error_code);
        $this->assertStringNotContainsString('transcript', SupportMailboxState::first()->toJson());
    }

    public function test_recovery_known_duplicates_unknown_time_and_large_original_are_observable(): void
    {
        config(['support.imap.enabled' => true]);
        $ticket = app(SupportOperations::class)->createTicket('guest@example.test', 'From website', 'web');
        $raw = $this->webReply($ticket, 'known@example.test');
        $known = $this->receipt($raw, 50);
        app(SupportInbound::class)->accept($known);
        $mailbox = app(SupportMailbox::class);
        $state = $mailbox->state();
        $state->update(['activated_at' => now()->subHour(), 'baseline_uid' => 49, 'baseline_uidvalidity' => 1, 'uidvalidity' => 1, 'cursor' => 50, 'status' => 'active']);
        $baseline = $state->activated_at;
        $imap = Mockery::mock(SupportImap::class);
        $imap->shouldReceive('connect')->once()->andReturn(['uidvalidity' => 2, 'uidnext' => 4]);
        $imap->shouldReceive('disconnect')->once();
        $imap->shouldReceive('metadata')->with(1, 3)->once()->andReturn([
            1 => ['bytes' => strlen($raw), 'received_at' => null],
            2 => ['bytes' => 500, 'received_at' => null],
            3 => ['bytes' => config('support.max_mail_bytes') + 1, 'received_at' => now()->toISOString()],
        ]);
        $imap->shouldReceive('headers')->with(1)->once()->andReturn($raw);
        $imap->shouldReceive('headers')->with(2)->once()->andReturn($this->webReply($ticket, 'uncertain@example.test'));
        $imap->shouldReceive('headers')->with(3)->once()->andReturn($this->webReply($ticket, 'large@example.test'));
        $imap->shouldReceive('raw')->with(1)->once()->andReturn($raw);
        $imap->shouldReceive('raw')->with(2)->once()->andReturn($this->webReply($ticket, 'uncertain@example.test'));
        $this->app->instance(SupportImap::class, $imap);
        $this->assertSame('active', $mailbox->run(recover: true));
        $this->assertTrue($baseline->eq($state->fresh()->activated_at));
        $this->assertDatabaseHas('support_mail_receipts', ['uidvalidity' => 2, 'uid' => 1, 'outcome' => 'duplicate']);
        $this->assertDatabaseHas('support_mail_receipts', ['uidvalidity' => 2, 'uid' => 2, 'outcome' => 'recovery_manual']);
        $this->assertDatabaseHas('support_mail_receipts', ['uidvalidity' => 2, 'uid' => 3, 'reason' => 'MAIL_TOO_LARGE']);
        $this->assertDatabaseCount('admin_support_tickets', 1);
        $manual = SupportMailReceipt::where('outcome', 'recovery_manual')->firstOrFail();
        $actor = User::factory()->create(['role' => 'admin_full']);
        app(SupportOperations::class)->resolveReceipt($actor, $manual->id, 'accept', 'manual-accept-key');
        app(SupportOperations::class)->resolveReceipt($actor, $manual->id, 'accept', 'manual-accept-key');
        app(SupportRecovery::class)->run();
        $this->assertSame('accepted', $manual->fresh()->outcome);
        $this->assertDatabaseCount('admin_support_tickets', 1);
        $this->assertDatabaseCount('admin_operation_events', 1);
    }

    public function test_ambiguous_sender_malformed_body_and_autogenerated_attachment_create_no_reply(): void
    {
        $ticket = app(SupportOperations::class)->createTicket('guest@example.test', 'From website', 'web');
        $accepted = $this->receipt($this->webReply($ticket), 1);
        app(SupportInbound::class)->accept($accepted);
        $wrongSender = str_replace('guest@example.test', 'other@example.test', $this->raw('wrong@example.test', "In-Reply-To: <incoming1@example.test>\r\n"));
        $receipt = $this->receipt($wrongSender, 2);
        app(SupportInbound::class)->accept($receipt);
        $this->assertSame('AMBIGUOUS_THREAD', $receipt->fresh()->reason);
        $empty = $this->receipt($this->raw('empty@example.test', body: ''), 3);
        app(SupportInbound::class)->accept($empty);
        $this->assertSame('UNREADABLE_BODY', $empty->fresh()->reason);
        Storage::disk('support-private')->assertExists($empty->original_key);
        $auto = $this->receipt($this->raw('auto@example.test', "Auto-Submitted: auto-generated\r\n", 'Autogenerated message'), 4);
        app(SupportInbound::class)->accept($auto);
        $this->assertSame('LOOP_PREVENTED', $auto->fresh()->reason);
        app(SupportRecovery::class)->run();
        $this->assertDatabaseCount('support_messages', 1);
    }

    public function test_retry_exhaustion_and_permanent_failure_stay_failed_until_explicit_operator_retry(): void
    {
        $this->submit();
        $ticket = AdminSupportTicket::first();
        $actor = User::factory()->create(['role' => 'admin_full']);
        $message = app(SupportOperations::class)->reply($actor, $ticket->id, 'Reply', $ticket->revision, 'exhaustion-key');
        $message->update(['attempts' => config('support.delivery_attempts') - 1]);
        $smtp = Mockery::mock(SupportSmtp::class)->shouldReceive('send')->once()->andThrow(new SupportSmtpFailure('SMTP_TEMPORARY_REJECTION', true))->getMock();
        (new DeliverSupportMessage($message->id))->handle($smtp);
        $this->assertSame('failed', $message->fresh()->delivery_status);
        $this->assertNull($message->fresh()->next_attempt_at);
        app(SupportOperations::class)->retry($actor, $message->id, 'manual-retry-once');
        app(SupportOperations::class)->retry($actor, $message->id, 'manual-retry-once');
        $smtp = Mockery::mock(SupportSmtp::class)->shouldReceive('send')->once()->andThrow(new SupportSmtpFailure('SMTP_PERMANENT_REJECTION', false))->getMock();
        (new DeliverSupportMessage($message->id))->handle($smtp);
        $this->assertSame('failed', $message->fresh()->delivery_status);
        $this->assertSame('SMTP_PERMANENT_REJECTION', $message->fresh()->error_code);
        $this->assertDatabaseCount('support_messages', 2);
    }
}
