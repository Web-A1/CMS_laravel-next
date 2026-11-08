<?php

namespace Tests\Feature;

use App\Filament\Pages\Operations\SupportTicketDetail;
use App\Jobs\DeleteSupportMedia;
use App\Jobs\ProcessSupportImage;
use App\Models\AdminOperationEvent;
use App\Models\AdminSupportTicket;
use App\Models\SupportAttachment;
use App\Models\SupportMailReceipt;
use App\Models\SupportMediaDeletion;
use App\Models\User;
use App\Services\Reviews\ReviewImageProcessor;
use App\Services\Support\SupportImages;
use App\Services\Support\SupportImap;
use App\Services\Support\SupportMailbox;
use App\Services\Support\SupportMediaRemoval;
use App\Services\Support\SupportOperations;
use App\Services\Support\SupportRecovery;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\Concerns\SupportDatabase;
use Tests\TestCase;

class SupportClosureTest extends TestCase
{
    use SupportDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('support-private');
    }

    public function test_close_preserves_text_and_audit_and_records_a_durable_snapshot(): void
    {
        $actor = User::factory()->create(['role' => 'admin_full']);
        $operations = app(SupportOperations::class);
        $ticket = $operations->createTicket('fixture@example.test', 'Текст обращения', 'web');
        $message = $ticket->messages()->create(['direction' => 'inbound', 'body' => 'Текст переписки', 'sender' => 'fixture@example.test']);

        $closed = $operations->close($actor, $ticket->id, $ticket->revision, 'fixture-close-001');

        $this->assertSame('closed', $closed->status);
        $this->assertSame($ticket->revision + 1, $closed->revision);
        $this->assertSame('Текст переписки', $message->fresh()->body);
        $this->assertSame('Текст обращения', $closed->description);
        $deletion = SupportMediaDeletion::sole();
        $this->assertSame([$message->id], $deletion->snapshot['messages']);
        $this->assertSame('pending', $deletion->status);
        $this->assertDatabaseHas('admin_operation_events', ['operation' => 'support_close', 'actor_id' => $actor->id]);

        $operations->close($actor, $ticket->id, $ticket->revision, 'fixture-close-001');
        $this->assertSame(1, SupportMediaDeletion::count());
    }

    public function test_protocol_one_cleanup_deletes_original_and_previews_and_denies_old_urls(): void
    {
        $operations = app(SupportOperations::class);
        $operations->submit('fixture@example.test', 'Сохранить переписку', 'closure-image-001', [UploadedFile::fake()->image('screen.png', 20, 30)]);
        $ticket = AdminSupportTicket::sole();
        $attachment = SupportAttachment::sole();
        (new ProcessSupportImage($attachment->id))->handle(app(ReviewImageProcessor::class), app(SupportImages::class));
        $this->assertCount(2, Storage::disk('support-private')->allFiles());
        $actor = User::factory()->create(['role' => 'admin_full']);
        Sanctum::actingAs($actor);
        $url = '/api/v1/admin/support-tickets/'.$ticket->id.'/attachments/'.$attachment->id;
        $this->get($url)->assertOk();

        $operations->close($actor, $ticket->id, $ticket->revision, 'closure-image-close');
        $this->get($url)->assertNotFound();
        app(SupportMediaRemoval::class)->run(SupportMediaDeletion::sole()->id);

        $this->assertSame('completed', SupportMediaDeletion::sole()->status);
        $this->assertSame('proven_v1', SupportMediaDeletion::sole()->coverage);
        $this->assertSame([], Storage::disk('support-private')->allFiles());
        $this->assertSame('deleted', $attachment->fresh()->status);
        $this->assertSame('Сохранить переписку', $ticket->messages()->first()->body);
        $this->assertDatabaseHas('admin_operation_events', ['operation' => 'support_media_deleted']);
        app(SupportMediaRemoval::class)->run(SupportMediaDeletion::sole()->id);
        $this->assertSame(2, AdminOperationEvent::count());
    }

    public function test_old_cleanup_and_close_key_do_not_delete_or_close_a_new_inbound(): void
    {
        $operations = app(SupportOperations::class);
        $operations->submit('fixture@example.test', 'Первое сообщение', 'closure-reopen-001', [UploadedFile::fake()->image('first.png', 20, 20)]);
        $ticket = AdminSupportTicket::sole();
        $actor = User::factory()->create(['role' => 'admin_full']);
        $revision = $ticket->revision;
        $operations->close($actor, $ticket->id, $revision, 'closure-reopen-close');
        $firstDeletion = SupportMediaDeletion::sole();
        $new = DB::transaction(fn () => $operations->inbound($ticket, 'Новый вопрос', 'fixture@example.test'));
        app(SupportImages::class)->accept($new, [UploadedFile::fake()->image('new.png', 20, 20)]);
        $newFile = $new->attachments()->sole();

        app(SupportMediaRemoval::class)->run($firstDeletion->id);
        $operations->close($actor, $ticket->id, $revision, 'closure-reopen-close');

        $this->assertSame('completed', $firstDeletion->fresh()->status);
        $this->assertSame('new', $ticket->fresh()->status);
        $this->assertNull($newFile->fresh()->media_deletion_id);
        Storage::disk('support-private')->assertExists($newFile->source_key);
        $this->assertSame(1, SupportMediaDeletion::count());
        $this->assertSame('Новый вопрос', $new->fresh()->body);
    }

    public function test_foreign_reference_blocks_deletion_and_preserves_both_tickets(): void
    {
        $operations = app(SupportOperations::class);
        $operations->submit('fixture@example.test', 'Первый тикет', 'closure-shared-001', [UploadedFile::fake()->image('first.png', 20, 20)]);
        $ticket = AdminSupportTicket::sole();
        $file = SupportAttachment::sole();
        $other = $operations->createTicket('other@example.test', 'Другой тикет', 'web');
        $message = $other->messages()->create(['direction' => 'inbound', 'body' => 'Чужой текст']);
        $message->attachments()->create(['disk' => $file->disk, 'source_key' => $file->source_key, 'mime' => $file->mime, 'bytes' => $file->bytes]);
        $operations->close(User::factory()->create(['role' => 'admin_full']), $ticket->id, $ticket->revision, 'closure-shared-close');

        app(SupportMediaRemoval::class)->run(SupportMediaDeletion::sole()->id);

        $this->assertSame('blocked', SupportMediaDeletion::sole()->status);
        $this->assertSame('MEDIA_SHARED_KEY', SupportMediaDeletion::sole()->error_code);
        Storage::disk('support-private')->assertExists($file->source_key);
        $this->assertSame('new', $other->fresh()->status);
        $this->assertSame('Чужой текст', $message->fresh()->body);
    }

    public function test_close_api_enforces_permissions_revision_and_cannot_bypass_cleanup_via_status(): void
    {
        $operations = app(SupportOperations::class);
        $operations->submit('fixture@example.test', 'Сохранить текст', 'api-close-ticket');
        $ticket = AdminSupportTicket::sole();
        $url = '/api/v1/admin/support-tickets/'.$ticket->id;
        $payload = ['status' => 'closed', 'revision' => $ticket->revision];
        $this->withHeader('Idempotency-Key', 'api-close-key')->patchJson($url, $payload)->assertUnauthorized();
        Sanctum::actingAs(User::factory()->create(['role' => 'admin_limited']));
        $this->patchJson($url, $payload)->assertForbidden();
        Sanctum::actingAs(User::factory()->create(['role' => 'admin_full']));
        $this->patchJson($url, [...$payload, 'revision' => 999])->assertConflict();
        $this->assertSame(0, SupportMediaDeletion::count());
        $this->patchJson($url, $payload)->assertOk();
        $this->assertSame(1, SupportMediaDeletion::count());
        $this->assertSame(SupportMediaDeletion::sole()->id, $ticket->messages()->sole()->media_deletion_id);
    }

    public function test_failed_storage_and_lost_dispatch_remain_recoverable_and_pending_image_cannot_resurrect(): void
    {
        $operations = app(SupportOperations::class);
        $operations->submit('fixture@example.test', 'Повтор очистки', 'retry-close-ticket', [UploadedFile::fake()->image('pending.png', 20, 20)]);
        $ticket = AdminSupportTicket::sole();
        $attachment = SupportAttachment::sole();
        $operations->close(User::factory()->create(['role' => 'admin_full']), $ticket->id, $ticket->revision, 'retry-close-key');
        $deletion = SupportMediaDeletion::sole();
        Queue::fake();
        app(SupportRecovery::class)->run();
        Queue::assertPushed(DeleteSupportMedia::class, fn ($job) => $job->deletionId === $deletion->id);
        Queue::assertNotPushed(ProcessSupportImage::class);

        // A symlink is an unsafe storage target. Failure must not be called completion.
        $source = Storage::disk('support-private')->path($attachment->source_key);
        rename($source, $source.'.fixture');
        symlink($source.'.fixture', $source);
        app(SupportMediaRemoval::class)->run($deletion->id);
        $this->assertSame('failed', $deletion->fresh()->status);
        $this->assertNull($deletion->fresh()->completed_at);
        $this->assertFileExists($source.'.fixture');
        unlink($source);
        rename($source.'.fixture', $source);
        app(SupportMediaRemoval::class)->run($deletion->id);
        $this->assertSame('completed', $deletion->fresh()->status);
        (new ProcessSupportImage($attachment->id))->handle(app(ReviewImageProcessor::class), app(SupportImages::class));
        $this->assertSame([], Storage::disk('support-private')->allFiles());
        $this->assertSame('deleted', $attachment->fresh()->status);
    }

    public function test_outer_transaction_is_rejected_before_any_file_is_persisted(): void
    {
        $upload = UploadedFile::fake()->image('outside.png', 20, 20);
        DB::beginTransaction();
        try {
            app(SupportOperations::class)->submit('fixture@example.test', 'Nested', 'nested-transaction', [$upload]);
            $this->fail('The owner reservation must be genuinely committed');
        } catch (\RuntimeException $error) {
            $this->assertSame('MEDIA_REQUIRES_COMMITTED_RESERVATION', $error->getMessage());
        } finally {
            DB::rollBack();
        }
        $this->assertSame([], Storage::disk('support-private')->allFiles());
        $this->assertSame(0, AdminSupportTicket::count());
    }

    public function test_mail_original_and_duplicates_are_removed_and_old_imap_delivery_cannot_restore_them(): void
    {
        $operations = app(SupportOperations::class);
        $operations->submit('fixture@example.test', 'Письма и изображения', 'mail-close-ticket');
        $ticket = AdminSupportTicket::sole();
        config(['support.imap.enabled' => true]);
        app(SupportMailbox::class)->state()->update(['activated_at' => now(), 'uidvalidity' => 1, 'baseline_uidvalidity' => 1, 'baseline_uid' => 0, 'cursor' => 0]);
        $raw = "From: fixture@example.test\r\nTo: support@example.test\r\nMessage-ID: <close-mail@example.test>\r\nSubject: Re: {$ticket->public_number}\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nТекст письма остаётся";
        $imap = \Mockery::mock(SupportImap::class);
        $imap->shouldReceive('connect')->andReturn(['uidvalidity' => 1, 'uidnext' => 3], ['uidvalidity' => 1, 'uidnext' => 4]);
        $imap->shouldReceive('disconnect')->twice();
        $imap->shouldReceive('metadata')->with(1, 2)->once()->andReturn([1 => ['bytes' => strlen($raw)], 2 => ['bytes' => strlen($raw)]]);
        $imap->shouldReceive('metadata')->with(3, 3)->once()->andReturn([3 => ['bytes' => strlen($raw)]]);
        $imap->shouldReceive('headers')->withAnyArgs()->times(3)->andReturn(explode("\r\n\r\n", $raw)[0]."\r\n");
        $imap->shouldReceive('raw')->withAnyArgs()->times(3)->andReturn($raw);
        $this->app->instance(SupportImap::class, $imap);
        $this->assertSame('active', app(SupportMailbox::class)->run());
        $receipts = SupportMailReceipt::orderBy('id')->get();
        $this->assertCount(2, Storage::disk('support-private')->allFiles(), $receipts->map->only(['outcome', 'reason'])->toJson());
        $this->assertSame(2, $ticket->messages()->count());
        $this->assertSame(['accepted', 'duplicate'], $receipts->pluck('outcome')->all());
        $actor = User::factory()->create(['role' => 'admin_full']);
        $operations->close($actor, $ticket->id, $ticket->fresh()->revision, 'close-mail-action');
        Sanctum::actingAs($actor);
        $this->get('/api/v1/admin/support-mail-receipts/'.$receipts->first()->id.'/original')->assertNotFound();
        app(SupportMediaRemoval::class)->run(SupportMediaDeletion::sole()->id);
        $this->assertSame('completed', SupportMediaDeletion::sole()->status);
        $this->assertSame([], Storage::disk('support-private')->allFiles());
        $this->assertSame('active', app(SupportMailbox::class)->run());
        $this->assertSame([], Storage::disk('support-private')->allFiles());
        $this->assertSame('closed', $ticket->fresh()->status);
        $this->assertSame(2, $ticket->messages()->count());
        $this->assertSame('Текст письма остаётся', $ticket->messages()->reorder('id', 'desc')->first()->body);
    }

    public function test_filament_confirmation_closes_ticket_and_reports_pending_media_without_losing_history(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create(['role' => 'admin_full']), 'web');
        app(SupportOperations::class)->submit('fixture@example.test', 'История для карточки', 'panel-close-ticket');
        $ticket = AdminSupportTicket::sole();
        $page = Livewire::test(SupportTicketDetail::class, ['ticketId' => (string) $ticket->id])
            ->mountAction('closeTicket')
            ->assertActionMounted('closeTicket');
        $action = $page->instance()->getMountedAction();
        $this->assertSame('Закрыть тикет?', $action->getModalHeading());
        $this->assertStringContainsString('без возможности восстановления', $action->getModalDescription());
        $this->assertSame('new', $ticket->fresh()->status);
        $page->callMountedAction()->assertHasNoActionErrors();
        $this->assertSame('closed', $ticket->fresh()->status);
        $this->assertSame('pending', SupportMediaDeletion::sole()->status);
        $page->assertSee('История для карточки');
        app(SupportMediaRemoval::class)->run(SupportMediaDeletion::sole()->id);
        $page->call('$refresh')->assertSee('Медиа удалены');
    }
}
