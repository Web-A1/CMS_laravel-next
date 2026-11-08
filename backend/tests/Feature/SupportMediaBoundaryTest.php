<?php

namespace Tests\Feature;

use App\Models\AdminOperationEvent;
use App\Models\AdminSupportTicket;
use App\Models\SupportMailReceipt;
use App\Models\SupportMediaDeletion;
use App\Models\User;
use App\Services\Support\SupportLegacyEnvironmentEvidence;
use App\Services\Support\SupportMediaRemoval;
use App\Services\Support\SupportMediaStorage;
use App\Services\Support\SupportOperations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use ReflectionMethod;
use Tests\Concerns\SupportDatabase;
use Tests\TestCase;

class SupportMediaBoundaryTest extends TestCase
{
    use SupportDatabase;

    private string $temporary;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('support-private');
        config(['support.disk' => 'support-private', 'support.media_disks' => []]);
        $this->temporary = realpath(sys_get_temp_dir()).'/support-boundary-'.bin2hex(random_bytes(8));
        mkdir($this->temporary.'/work', 0700, true);
        // Explicit local operator import remains subject to the real source verifier.
        $this->app->instance('env', 'local');
        $storage = app(SupportMediaStorage::class);
        $adapter = Mockery::mock(SupportLegacyEnvironmentEvidence::class, [$storage])->makePartial()->shouldAllowMockingProtectedMethods();
        $adapter->shouldReceive('knownDomains')->andReturnUsing(function ($deletion) use ($storage) {
            $known = (new ReflectionMethod(SupportLegacyEnvironmentEvidence::class, 'knownDomains'))
                ->invoke(new SupportLegacyEnvironmentEvidence($storage), $deletion);

            // Keep production physical storage discovery; isolate only process temp roots.
            return array_map(fn ($domain) => $domain['purpose'] === 'legacy_store'
                ? $domain : [...$domain, 'identity' => $this->temporaryIdentity()], $known);
        });
        $this->app->instance(SupportLegacyEnvironmentEvidence::class, $adapter);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->temporary);
        parent::tearDown();
    }

    private function temporaryIdentity(): array
    {
        return ['driver' => 'local', 'host' => gethostname(), 'root' => $this->temporary.'/work'];
    }

    private function legacy(): array
    {
        $ticket = app(SupportOperations::class)->createTicket('fixture@example.test', 'История', 'web');
        $message = $ticket->messages()->create(['direction' => 'inbound', 'body' => 'Сохранённый текст', 'sender' => 'fixture@example.test', 'message_id' => 'boundary@example.test']);
        $attachment = $message->attachments()->create(['disk' => 'support-private', 'mime' => 'image/png', 'bytes' => 7, 'status' => 'ready']);
        $attachment->update(['preview_key' => 'images/'.$attachment->id.'/full.webp', 'thumbnail_key' => 'images/'.$attachment->id.'/thumb.webp']);
        Storage::disk('support-private')->put($attachment->preview_key, 'preview');
        Storage::disk('support-private')->put($attachment->thumbnail_key, 'thumb');
        $raw = "From: fixture@example.test\r\nMessage-ID: <boundary@example.test>\r\n\r\nOriginal\r\n";
        $receipt = SupportMailReceipt::create(['mailbox' => 'fixture@example.test', 'folder' => 'INBOX', 'uidvalidity' => 1, 'uid' => 1, 'disk' => 'support-private', 'outcome' => 'accepted', 'fingerprint' => hash('sha256', $raw), 'support_message_id' => $message->id]);
        Storage::disk('support-private')->put('mail/'.$receipt->id.'.eml', $raw);

        return [$ticket, $message, $attachment, $receipt, $raw];
    }

    private function close(AdminSupportTicket $ticket, string $key = 'boundary-close-001'): SupportMediaDeletion
    {
        app(SupportOperations::class)->close(User::factory()->create(['role' => 'admin_full']), $ticket->id, $ticket->fresh()->revision, $key);

        return SupportMediaDeletion::latest('id')->firstOrFail();
    }

    private function importEvidence(SupportMediaDeletion $deletion, ?string $path = null): string
    {
        if ($path === null) {
            $domains = [['id' => 'store', 'purpose' => 'legacy_store', 'identity' => app(SupportMediaStorage::class)->identity('support-private'), 'basis' => ['kind' => 'deployment_record', 'reference' => 'isolated disk']]];
            foreach (['image_work', 'mail_work', 'upload_tmp', 'processor_tmp'] as $purpose) {
                $domains[] = ['id' => $purpose, 'purpose' => $purpose, 'identity' => $this->temporaryIdentity(), 'basis' => ['kind' => 'owner_attestation', 'reference' => 'isolated temporary root']];
            }
            $instance = config('support.instance_id', hash('sha256', realpath(base_path())));
            $receipt = [
                'schema_version' => 1,
                'provenance' => ['receipt_id' => 'boundary-retirement', 'issuer' => 'fixture', 'issued_at' => now()->toIso8601String(), 'source_kind' => 'deployment_owner_attestation', 'source_reference' => 'isolated fixture'],
                'environment' => ['application_instance' => $instance, 'host_id' => gethostname(), 'app_root' => realpath(base_path()), 'writer_contract' => 1, 'retirement_release_reference' => 'fixture retirement'],
                'domains' => $domains,
                'writer_retirement' => ['producers' => array_map(fn ($role) => ['role' => $role, 'identity' => 'fixture:'.$role, 'result' => 'drained', 'observation_source' => 'isolated observation'], ['http', 'queue', 'maintenance', 'processor']), 'unresolved_producers' => [], 'uncertain_external_io' => []],
                'attested_scope' => ['issuer' => 'fixture', 'application_instance' => $instance, 'writer_entrypoints' => ['http', 'queue', 'maintenance', 'processor'], 'physical_domains' => array_column($domains, 'id'), 'basis' => 'isolated facts', 'transition_reference' => 'fixture transition'],
            ];
            $path = $this->temporary.'/receipt.json';
            file_put_contents($path, json_encode($receipt));
            chmod($path, 0600);
        }
        app(SupportLegacyEnvironmentEvidence::class)->importFile($deletion, $path, localOperator: true);

        return $path;
    }

    private function assertIncomplete(SupportMediaDeletion $deletion, string $error): void
    {
        $this->assertNotSame('completed', $deletion->fresh()->status);
        $this->assertNull($deletion->fresh()->completed_at);
        $this->assertSame($error, $deletion->fresh()->error_code);
        $this->assertFalse(AdminOperationEvent::where('operation', 'support_media_deleted')->where('idempotency_key', 'media-deleted:'.$deletion->id)->exists());
    }

    public function test_inventory_lock_session_loss_after_plan_prevents_destructive_io(): void
    {
        [$ticket] = $this->legacy();
        $deletion = $this->close($ticket);
        $this->importEvidence($deletion);
        $files = Storage::disk('support-private')->allFiles();
        $pidReads = 0;
        // Only PostgreSQL operational facts are doubled; all business queries use SQLite.
        $database = Mockery::mock(DB::getFacadeRoot());
        DB::swap($database);
        $database->shouldReceive('getDriverName')->andReturn('pgsql');
        DB::shouldReceive('selectOne')->with('SELECT pg_try_advisory_lock(192763, 1) AS locked')->once()->andReturn((object) ['locked' => true]);
        DB::shouldReceive('selectOne')->with('SELECT pg_backend_pid() AS id')->twice()->andReturnUsing(function () use (&$pidReads) {
            return (object) ['id' => ++$pidReads === 1 ? 101 : 202];
        });
        DB::shouldReceive('select')->with('SELECT pg_advisory_unlock(192763, 1)')->once()->andReturn([]);
        app(SupportMediaRemoval::class)->run($deletion->id);

        $this->assertSame(2, $pidReads);
        $this->assertIncomplete($deletion, 'MEDIA_INVENTORY_LOCK_LOST');
        $this->assertSame('legacy_unknown', $deletion->fresh()->coverage);
        $this->assertSame($files, Storage::disk('support-private')->allFiles());
    }

    public function test_receipt_relinked_after_inventory_before_admission_preserves_new_media(): void
    {
        [$ticket, , , $receipt, $raw] = $this->legacy();
        $duplicate = SupportMailReceipt::create(['mailbox' => 'fixture@example.test', 'folder' => 'INBOX', 'uidvalidity' => 1, 'uid' => 2, 'disk' => 'support-private', 'fingerprint' => $receipt->fingerprint, 'outcome' => 'duplicate']);
        $key = 'mail/'.$duplicate->id.'.eml';
        Storage::disk('support-private')->put($key, $raw);
        $deletion = $this->close($ticket);
        $this->assertNotContains($duplicate->id, $deletion->snapshot['receipts']);
        $this->importEvidence($deletion);
        $new = app(SupportOperations::class)->inbound($ticket->fresh(), 'Новое сообщение', 'fixture@example.test', messageId: 'new-boundary@example.test');
        $new->attachments()->create(['disk' => 'support-private', 'preview_key' => 'images/new/full.webp', 'mime' => 'image/png', 'bytes' => 3, 'status' => 'ready']);
        Storage::disk('support-private')->put('images/new/full.webp', 'new');
        $files = Storage::disk('support-private')->allFiles();
        $connection = DB::connection();
        $database = Mockery::mock(DB::getFacadeRoot());
        DB::swap($database);
        $database->shouldReceive('transaction')->once()->andReturnUsing(function ($callback) use ($connection, $duplicate, $new, $key) {
            // The scanner has returned its real plan; race at transaction admission.
            $duplicate->update(['support_message_id' => $new->id, 'original_key' => $key]);

            return $connection->transaction($callback);
        });
        app(SupportMediaRemoval::class)->run($deletion->id);

        $this->assertIncomplete($deletion, 'MEDIA_LEGACY_RECEIPT_CHANGED');
        $this->assertSame($new->id, $duplicate->fresh()->support_message_id);
        $this->assertNull($duplicate->fresh()->media_deletion_id);
        $this->assertSame('Новое сообщение', $new->fresh()->body);
        $this->assertSame($files, Storage::disk('support-private')->allFiles());
        $this->assertSame($raw, Storage::disk('support-private')->get($key));
    }

    public function test_retirement_receipt_requires_fresh_binding_after_reopen_and_second_close(): void
    {
        [$ticket, $oldMessage, $oldAttachment] = $this->legacy();
        $old = $this->close($ticket);
        $path = $this->importEvidence($old);
        app(SupportMediaRemoval::class)->run($old->id);
        $this->assertSame('completed', $old->fresh()->status);
        $oldState = $old->fresh()->getAttributes();
        $new = app(SupportOperations::class)->inbound($ticket->fresh(), 'После открытия', 'fixture@example.test', messageId: 'reopen-boundary@example.test');
        $attachment = $new->attachments()->create(['disk' => 'support-private', 'preview_key' => 'images/'.$new->id.'/full.webp', 'mime' => 'image/png', 'bytes' => 3, 'status' => 'ready']);
        Storage::disk('support-private')->put($attachment->preview_key, 'new');
        app(SupportMediaRemoval::class)->run($old->id);
        Storage::disk('support-private')->assertExists($attachment->preview_key);
        $next = $this->close($ticket, 'boundary-close-002');
        $next->update(['inventory_evidence' => $old->fresh()->inventory_evidence]);
        app(SupportMediaRemoval::class)->run($next->id);
        $this->assertIncomplete($next, 'MEDIA_ENVIRONMENT_SNAPSHOT_MISMATCH');
        Storage::disk('support-private')->assertExists($attachment->preview_key);
        $this->importEvidence($next->fresh(), $path);
        $binding = app(SupportLegacyEnvironmentEvidence::class)->evidenceFor($next->fresh());
        $this->assertSame($next->id, $binding['deletion_id']);
        $this->assertSame(hash('sha256', json_encode($next->snapshot)), $binding['snapshot_digest']);
        $this->assertNotSame($old->inventory_evidence['snapshot_digest'], $binding['snapshot_digest']);
        app(SupportMediaRemoval::class)->run($next->id);

        $this->assertSame('completed', $next->fresh()->status);
        $this->assertSame($oldState, $old->fresh()->getAttributes());
        $this->assertSame('deleted', $oldAttachment->fresh()->status);
        $this->assertSame('Сохранённый текст', $oldMessage->fresh()->body);
        $this->assertSame('После открытия', $new->fresh()->body);
        $this->assertSame([], Storage::disk('support-private')->allFiles());
    }

    public function test_imported_physical_identity_drift_preserves_old_and_replacement_disks(): void
    {
        [$ticket] = $this->legacy();
        $deletion = $this->close($ticket);
        $this->importEvidence($deletion);
        $oldDisk = Storage::disk('support-private');
        $files = $oldDisk->allFiles();
        mkdir($this->temporary.'/replacement', 0700);
        config(['filesystems.disks.support-private' => ['driver' => 'local', 'root' => $this->temporary.'/replacement']]);
        Storage::forgetDisk('support-private');
        foreach ($files as $key) {
            Storage::disk('support-private')->put($key, 'replacement');
        }
        app(SupportMediaRemoval::class)->run($deletion->id);

        $this->assertIncomplete($deletion, 'MEDIA_STORAGE_IDENTITY_CHANGED');
        $this->assertSame('legacy_unknown', $deletion->fresh()->coverage);
        $this->assertSame($files, $oldDisk->allFiles());
        $this->assertSame($files, Storage::disk('support-private')->allFiles());
        foreach ($files as $key) {
            $this->assertSame('replacement', Storage::disk('support-private')->get($key));
        }
    }
}
