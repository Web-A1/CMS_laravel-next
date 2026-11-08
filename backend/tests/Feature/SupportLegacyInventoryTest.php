<?php

namespace Tests\Feature;

use App\Models\AdminSupportTicket;
use App\Models\SupportMailReceipt;
use App\Models\SupportMediaDeletion;
use App\Models\User;
use App\Services\Support\SupportLegacyEnvironmentEvidence;
use App\Services\Support\SupportMediaRemoval;
use App\Services\Support\SupportMediaStorage;
use App\Services\Support\SupportOperations;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\Concerns\SupportDatabase;
use Tests\TestCase;

class SupportLegacyInventoryTest extends TestCase
{
    use SupportDatabase;

    private string $temporary;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('support-private');
        $this->temporary = realpath(sys_get_temp_dir()).'/support-inventory-fixture-'.bin2hex(random_bytes(8));
        mkdir($this->temporary, 0700);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->temporary);
        parent::tearDown();
    }

    private function legacy(): array
    {
        $ticket = app(SupportOperations::class)->createTicket('fixture@example.test', 'Старое обращение', 'web');
        $message = $ticket->messages()->create(['direction' => 'inbound', 'body' => 'Текст остаётся', 'sender' => 'fixture@example.test', 'message_id' => 'legacy@example.test']);
        $attachment = $message->attachments()->create(['disk' => 'support-private', 'mime' => 'image/png', 'bytes' => 10, 'status' => 'ready']);
        $attachment->update(['preview_key' => 'images/'.$attachment->id.'/full.webp', 'thumbnail_key' => 'images/'.$attachment->id.'/thumb.webp']);
        Storage::disk('support-private')->put($attachment->preview_key, 'fixture full');
        Storage::disk('support-private')->put($attachment->thumbnail_key, 'fixture thumb');
        $raw = "From: fixture@example.test\r\nTo: support@example.test\r\nMessage-ID: <legacy@example.test>\r\nSubject: {$ticket->public_number}\r\n\r\nText\r\n";
        $receipt = SupportMailReceipt::create(['mailbox' => 'fixture@example.test', 'folder' => 'INBOX', 'uidvalidity' => 1, 'uid' => 1, 'disk' => 'support-private', 'outcome' => 'accepted', 'intake_status' => 'eligible', 'message_id' => 'legacy@example.test', 'fingerprint' => hash('sha256', $raw), 'support_message_id' => $message->id]);
        // A pre-protocol crash could leave this object with original_key still NULL.
        Storage::disk('support-private')->put('mail/'.$receipt->id.'.eml', $raw);

        return [$ticket, $message, $attachment, $receipt, $raw];
    }

    private function close(AdminSupportTicket $ticket): SupportMediaDeletion
    {
        app(SupportOperations::class)->close(User::factory()->create(['role' => 'admin_full']), $ticket->id, $ticket->revision, 'legacy-close-001');

        return SupportMediaDeletion::sole();
    }

    private function evidence(?\Closure $mutate = null, string $channel = 'root_owned_file', bool $realStorageDiscovery = false): void
    {
        $storage = app(SupportMediaStorage::class);
        $physical = ['driver' => 'local', 'host' => gethostname(), 'root' => $this->temporary];
        $domains = [['id' => 'store', 'purpose' => 'legacy_store', 'identity' => $storage->identity('support-private'), 'basis' => ['kind' => 'deployment_record', 'reference' => 'fixture deployment']]];
        foreach (['image_work', 'mail_work', 'upload_tmp', 'processor_tmp'] as $purpose) {
            $domains[] = ['id' => $purpose, 'purpose' => $purpose, 'identity' => $physical, 'basis' => ['kind' => 'owner_attestation', 'reference' => 'fixture effective temp roots']];
        }
        $receipt = [
            'schema_version' => 1,
            'provenance' => ['receipt_id' => 'fixture-retirement', 'issuer' => 'isolated fixture', 'issued_at' => now()->toIso8601String(), 'source_kind' => 'deployment_owner_attestation', 'source_reference' => 'fixture observations'],
            'environment' => ['application_instance' => hash('sha256', realpath(base_path())), 'host_id' => gethostname(), 'app_root' => realpath(base_path()), 'writer_contract' => 1, 'retirement_release_reference' => 'fixture retirement'],
            'domains' => $domains,
            'writer_retirement' => ['producers' => array_map(fn ($role) => ['role' => $role, 'identity' => 'fixture:'.$role, 'result' => 'drained', 'observation_source' => 'fixture process observations'], ['http', 'queue', 'maintenance', 'processor']), 'unresolved_producers' => [], 'uncertain_external_io' => []],
            'attested_scope' => ['issuer' => 'isolated fixture', 'application_instance' => hash('sha256', realpath(base_path())), 'writer_entrypoints' => ['http', 'queue', 'maintenance', 'processor'], 'physical_domains' => array_column($domains, 'id'), 'basis' => 'fixture deployment and runtime facts', 'transition_reference' => 'fixture protocol1 transition'],
        ];
        if ($mutate) {
            $receipt = $mutate($receipt);
        }
        $source = ['channel' => $channel, 'owner_uid' => 0, 'mode' => 0400, 'receipt_digest' => hash('sha256', json_encode($receipt))];
        // Only the external facts/channel are doubles. The verifier and scanner execute normally.
        $adapter = Mockery::mock(SupportLegacyEnvironmentEvidence::class, [$storage])->makePartial()->shouldAllowMockingProtectedMethods();
        $adapter->shouldReceive('storedSource')->andReturn([$receipt, $source]);
        if ($realStorageDiscovery) {
            $adapter->shouldReceive('knownDomains')->andReturnUsing(function ($deletion) use ($storage, $physical) {
                $method = new ReflectionMethod(SupportLegacyEnvironmentEvidence::class, 'knownDomains');
                $known = $method->invoke(new SupportLegacyEnvironmentEvidence($storage), $deletion);

                // Only process temporary roots are isolated; storage discovery is production code.
                return array_map(fn ($domain) => $domain['purpose'] === 'legacy_store'
                    ? $domain : [...$domain, 'identity' => $physical], $known);
            });
        } else {
            $adapter->shouldReceive('knownDomains')->andReturn(array_map(fn ($domain) => ['purpose' => $domain['purpose'], 'identity' => $domain['identity']], $domains));
        }
        $this->app->instance(SupportLegacyEnvironmentEvidence::class, $adapter);
    }

    public function test_lost_source_and_null_original_key_can_reach_confirmed_inventory_cleanup(): void
    {
        [$ticket, $message, $attachment, $receipt] = $this->legacy();
        $deletion = $this->close($ticket);
        $this->evidence();
        app(SupportMediaRemoval::class)->run($deletion->id);

        $this->assertNull($ticket->fresh()->media_protocol);
        $this->assertSame('proven_inventory', $deletion->fresh()->coverage);
        $this->assertSame('completed', $deletion->fresh()->status);
        $this->assertSame([], Storage::disk('support-private')->allFiles());
        $this->assertSame('Текст остаётся', $message->fresh()->body);
        $this->assertSame('deleted', $attachment->fresh()->status);
        $this->assertNotNull($receipt->fresh()->fingerprint);
    }

    #[DataProvider('historicalDomains')]
    public function test_real_storage_discovery_requires_and_scans_historical_domains(bool $included, bool $orphan, string $status, ?string $error): void
    {
        [$ticket, $message, $attachment] = $this->legacy();
        Storage::fake('support-old');
        config(['support.media_disks' => ['support-old']]);
        foreach ([$attachment->preview_key, $attachment->thumbnail_key] as $key) {
            Storage::disk('support-old')->put($key, Storage::disk('support-private')->get($key));
            Storage::disk('support-private')->delete($key);
        }
        $attachment->update(['disk' => 'support-old']);
        if ($orphan) {
            Storage::disk('support-old')->put('sources/lost-old-source', 'original bytes');
        }
        $deletion = $this->close($ticket);
        $this->evidence(function ($receipt) use ($included) {
            if ($included) {
                $receipt['domains'][] = ['id' => 'old', 'purpose' => 'legacy_store', 'identity' => app(SupportMediaStorage::class)->identity('support-old'), 'basis' => ['kind' => 'deployment_record', 'reference' => 'fixture old disk']];
                $receipt['attested_scope']['physical_domains'][] = 'old';
            }

            return $receipt;
        }, realStorageDiscovery: true);
        app(SupportMediaRemoval::class)->run($deletion->id);

        $this->assertSame($status, $deletion->fresh()->status);
        $this->assertSame($error, $deletion->fresh()->error_code);
        $this->assertSame($status === 'completed' ? 'proven_inventory' : 'legacy_unknown', $deletion->fresh()->coverage);
        $this->assertSame('Текст остаётся', $message->fresh()->body);
        if ($status === 'completed') {
            $this->assertSame([], Storage::disk('support-old')->allFiles());
        } else {
            Storage::disk('support-old')->assertExists($attachment->preview_key);
            Storage::disk('support-old')->assertExists('sources/lost-old-source');
        }
    }

    public static function historicalDomains(): array
    {
        return [
            'omitted historical store' => [false, true, 'blocked', 'MEDIA_ENVIRONMENT_KNOWN_DOMAIN_MISSING'],
            'unresolved historical source' => [true, true, 'blocked', 'MEDIA_LEGACY_RESOURCE_UNRESOLVED'],
            'complete historical inventory' => [true, false, 'completed', null],
        ];
    }

    public function test_proven_foreign_source_survives_and_does_not_block_target(): void
    {
        [$ticket] = $this->legacy();
        $other = app(SupportOperations::class)->createTicket('other@example.test', 'Другой тикет', 'web');
        $message = $other->messages()->create(['direction' => 'inbound', 'body' => 'Чужой текст']);
        $message->attachments()->create(['disk' => 'support-private', 'source_key' => 'sources/foreign', 'mime' => 'image/png', 'bytes' => 7]);
        Storage::disk('support-private')->put('sources/foreign', 'foreign');
        $deletion = $this->close($ticket);
        $this->evidence();
        app(SupportMediaRemoval::class)->run($deletion->id);

        $this->assertSame('completed', $deletion->fresh()->status);
        $this->assertSame(['sources/foreign'], Storage::disk('support-private')->allFiles());
        $this->assertSame('new', $other->fresh()->status);
    }

    public function test_unlinked_mime_duplicate_is_admitted_by_retained_identity_and_removed(): void
    {
        [$ticket, $message, $attachment, $receipt, $raw] = $this->legacy();
        $duplicate = SupportMailReceipt::create(['mailbox' => 'fixture@example.test', 'folder' => 'INBOX', 'uidvalidity' => 1, 'uid' => 2, 'disk' => 'support-private', 'fingerprint' => $receipt->fingerprint, 'outcome' => 'duplicate']);
        Storage::disk('support-private')->put('mail/'.$duplicate->id.'.eml', $raw);
        $deletion = $this->close($ticket);
        $this->assertNotContains($duplicate->id, $deletion->snapshot['receipts']);
        $this->evidence();
        app(SupportMediaRemoval::class)->run($deletion->id);

        $this->assertSame('completed', $deletion->fresh()->status);
        $this->assertContains($duplicate->id, $deletion->fresh()->manifest['admitted_receipts']);
        $this->assertSame($deletion->id, $duplicate->fresh()->media_deletion_id);
        $this->assertSame([], Storage::disk('support-private')->allFiles());
    }

    public function test_unknown_original_blocks_without_deleting_proven_files(): void
    {
        [$ticket, , $attachment] = $this->legacy();
        Storage::disk('support-private')->put('sources/lost-owner', 'unknown original');
        $deletion = $this->close($ticket);
        $this->evidence();
        app(SupportMediaRemoval::class)->run($deletion->id);

        $this->assertSame('blocked', $deletion->fresh()->status);
        $this->assertSame('legacy_unknown', $deletion->fresh()->coverage);
        $this->assertSame('MEDIA_LEGACY_RESOURCE_UNRESOLVED', $deletion->fresh()->error_code);
        Storage::disk('support-private')->assertExists($attachment->preview_key);
        Storage::disk('support-private')->assertExists('sources/lost-owner');
        $this->assertNotEmpty($deletion->fresh()->inventory_evidence['unresolved']);
    }

    public function test_unknown_processor_scratch_blocks_even_with_a_valid_attestation(): void
    {
        [$ticket, , $attachment] = $this->legacy();
        file_put_contents($this->temporary.'/magick-fixture-cache', 'unknown scratch');
        $deletion = $this->close($ticket);
        $this->evidence();
        app(SupportMediaRemoval::class)->run($deletion->id);

        $this->assertSame('blocked', $deletion->fresh()->status);
        $this->assertSame('MEDIA_LEGACY_TEMP_UNRESOLVED', $deletion->fresh()->error_code);
        Storage::disk('support-private')->assertExists($attachment->preview_key);
        $this->assertFileExists($this->temporary.'/magick-fixture-cache');
    }

    public function test_missing_environment_source_blocks_and_can_later_be_retried(): void
    {
        [$ticket] = $this->legacy();
        $deletion = $this->close($ticket);
        app(SupportMediaRemoval::class)->run($deletion->id);
        $this->assertSame('MEDIA_LEGACY_ENVIRONMENT_EVIDENCE_NOT_CONFIGURED', $deletion->fresh()->error_code);

        $this->evidence();
        app(SupportMediaRemoval::class)->run($deletion->id);
        $this->assertSame('completed', $deletion->fresh()->status);
    }

    public function test_untrusted_source_cannot_raise_coverage(): void
    {
        [$ticket] = $this->legacy();
        $deletion = $this->close($ticket);
        $this->evidence(channel: 'http_form');
        app(SupportMediaRemoval::class)->run($deletion->id);
        $this->assertSame('MEDIA_ENVIRONMENT_SOURCE_UNTRUSTED', $deletion->fresh()->error_code);
        $this->assertSame('legacy_unknown', $deletion->fresh()->coverage);
    }

    public function test_known_domain_cannot_be_omitted_by_a_proven_flag(): void
    {
        [$ticket] = $this->legacy();
        $deletion = $this->close($ticket);
        $this->evidence(function ($receipt) {
            array_pop($receipt['domains']);
            $receipt['proven'] = true;

            return $receipt;
        });
        app(SupportMediaRemoval::class)->run($deletion->id);
        $this->assertSame('MEDIA_ENVIRONMENT_KNOWN_DOMAIN_MISSING', $deletion->fresh()->error_code);
    }

    public function test_uncertain_old_writer_is_not_declared_drained(): void
    {
        [$ticket] = $this->legacy();
        $deletion = $this->close($ticket);
        $this->evidence(function ($receipt) {
            $receipt['writer_retirement']['uncertain_external_io'] = ['fixture unresolved put'];

            return $receipt;
        });
        app(SupportMediaRemoval::class)->run($deletion->id);
        $this->assertSame('MEDIA_LEGACY_WRITERS_UNCONFIRMED', $deletion->fresh()->error_code);
    }

    public function test_missing_producer_observation_cannot_hide_behind_an_attestation(): void
    {
        [$ticket] = $this->legacy();
        $deletion = $this->close($ticket);
        $this->evidence(function ($receipt) {
            array_pop($receipt['writer_retirement']['producers']);

            return $receipt;
        });
        app(SupportMediaRemoval::class)->run($deletion->id);
        $this->assertSame('MEDIA_LEGACY_WRITERS_UNCONFIRMED', $deletion->fresh()->error_code);
    }

    public function test_wrong_host_is_rejected_before_storage_mutation(): void
    {
        [$ticket] = $this->legacy();
        $deletion = $this->close($ticket);
        $this->evidence(function ($receipt) {
            $receipt['environment']['host_id'] = 'another-fixture-host';

            return $receipt;
        });
        app(SupportMediaRemoval::class)->run($deletion->id);
        $this->assertSame('MEDIA_ENVIRONMENT_INSTANCE_MISMATCH', $deletion->fresh()->error_code);
        $this->assertCount(3, Storage::disk('support-private')->allFiles());
    }
}
