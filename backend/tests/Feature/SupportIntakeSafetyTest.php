<?php

namespace Tests\Feature;

use App\Models\AdminSupportTicket;
use App\Models\SupportMailboxState;
use App\Models\SupportMailReceipt;
use App\Services\Support\SupportImap;
use App\Services\Support\SupportInbound;
use App\Services\Support\SupportMailbox;
use App\Services\Support\SupportOperations;
use App\Services\Support\SupportRecovery;
use Aws\S3\S3ClientInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use Mockery;
use Tests\Concerns\SupportDatabase;
use Tests\TestCase;

class SupportIntakeSafetyTest extends TestCase
{
    use SupportDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('support-private');
    }

    private function original(int $uid, ?string $receivedAt, ?AdminSupportTicket $ticket = null): SupportMailReceipt
    {
        $key = 'mail/crash-'.$uid.'.eml';
        $subject = $ticket ? 'Re: '.$ticket->public_number : 'Saved before classification';
        Storage::disk('support-private')->put($key, "From: guest@example.test\r\nDate: Wed, 28 Oct 2026 12:00:00 +0000\r\nMessage-ID: <crash-{$uid}@example.test>\r\nSubject: {$subject}\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nQuestion");

        return SupportMailReceipt::create(['mailbox' => config('support.address'), 'folder' => 'INBOX', 'uidvalidity' => 2, 'uid' => $uid, 'disk' => 'support-private', 'original_key' => $key, 'received_at' => $receivedAt]);
    }

    public function test_saved_unclassified_originals_cannot_bypass_historical_or_manual_classification(): void
    {
        SupportMailboxState::create(['mailbox' => config('support.address'), 'folder' => 'INBOX', 'uidvalidity' => 1, 'baseline_uidvalidity' => 1, 'baseline_uid' => 100, 'activated_at' => now()->subHour(), 'activation_window_started_at' => now()->subHour()->subSecond(), 'activation_window_finished_at' => now()->subHour(), 'status' => 'recovery_required']);
        $old = $this->original(1, now()->subDay()->toISOString());
        $unknown = $this->original(2, null);
        app(SupportInbound::class)->accept($old);
        app(SupportInbound::class)->accept($unknown);
        $this->assertDatabaseCount('admin_support_tickets', 0);
        $this->assertSame(2, app(SupportRecovery::class)->health()['quarantine']);
        app(SupportRecovery::class)->run();
        app(SupportRecovery::class)->run();
        $this->assertDatabaseCount('admin_support_tickets', 0);
        $this->assertDatabaseCount('support_messages', 0);
        $this->assertSame('historical', $old->fresh()->outcome);
        $this->assertSame('recovery_manual', $unknown->fresh()->outcome);
    }

    public function test_saved_new_original_is_safely_reclassified_then_resumes_exactly_once(): void
    {
        $ticket = app(SupportOperations::class)->createTicket('guest@example.test', 'From website', 'web');
        SupportMailboxState::create(['mailbox' => config('support.address'), 'folder' => 'INBOX', 'uidvalidity' => 1, 'baseline_uidvalidity' => 1, 'baseline_uid' => 100, 'activated_at' => now()->subHour(), 'activation_window_started_at' => now()->subHour()->subSecond(), 'activation_window_finished_at' => now()->subHour(), 'status' => 'recovery_required']);
        $receipt = $this->original(1, now()->toISOString(), $ticket);
        app(SupportRecovery::class)->run();
        $this->assertSame('eligible', $receipt->fresh()->intake_status);
        $this->assertSame('accepted', $receipt->fresh()->outcome);
        app(SupportRecovery::class)->run();
        app(SupportInbound::class)->accept($receipt->fresh());
        $this->assertDatabaseCount('admin_support_tickets', 1);
        $this->assertDatabaseCount('support_messages', 1);
    }

    public function test_prior_activation_without_precise_window_is_conservative_after_uid_reset(): void
    {
        SupportMailboxState::create(['mailbox' => config('support.address'), 'folder' => 'INBOX', 'uidvalidity' => 1, 'baseline_uidvalidity' => 1, 'baseline_uid' => 100, 'activated_at' => now()->subHour(), 'status' => 'recovery_required']);
        $old = $this->original(1, now()->subDay()->toISOString());
        $new = $this->original(2, now()->toISOString());
        app(SupportRecovery::class)->run();
        $this->assertSame('recovery_manual', $old->fresh()->outcome);
        $this->assertSame('recovery_manual', $new->fresh()->outcome);
        $this->assertSame('ACTIVATION_WINDOW_UNKNOWN', $new->fresh()->reason);
        $this->assertDatabaseCount('admin_support_tickets', 0);
        $this->assertDatabaseCount('support_messages', 0);
    }

    public function test_internaldate_equal_to_snapshot_second_is_manual_even_with_zero_microseconds(): void
    {
        $boundary = Carbon::parse('2026-10-28 12:00:00', 'UTC');
        SupportMailboxState::create(['mailbox' => config('support.address'), 'folder' => 'INBOX', 'uidvalidity' => 1, 'baseline_uidvalidity' => 1, 'baseline_uid' => 100, 'activated_at' => $boundary, 'activation_window_started_at' => $boundary, 'activation_window_finished_at' => $boundary, 'status' => 'active']);
        $receipt = $this->original(1, $boundary->toISOString());
        app(SupportRecovery::class)->run();
        $this->assertSame('recovery_manual', $receipt->fresh()->outcome);
        $this->assertSame('ACTIVATION_WINDOW_AMBIGUOUS', $receipt->fresh()->reason);
        $this->assertDatabaseCount('admin_support_tickets', 0);
    }

    public function test_uid_reset_before_first_poll_keeps_post_snapshot_and_same_second_arrivals(): void
    {
        $ticket = app(SupportOperations::class)->createTicket('guest@example.test', 'From website', 'web');
        config(['support.imap.enabled' => true, 'support.imap.host' => 'fixture.invalid', 'support.imap.username' => 'fixture', 'support.imap.password' => 'synthetic']);
        $this->travelTo(Carbon::parse('2026-10-28 12:00:00.100000', 'UTC'));
        $imap = Mockery::mock(SupportImap::class);
        $imap->shouldReceive('connect')->once()->andReturnUsing(function (): array {
            $this->travelTo(Carbon::parse('2026-10-28 12:00:02.300000', 'UTC'));

            return ['uidvalidity' => 1, 'uidnext' => 101, 'exists' => 3];
        });
        $imap->shouldReceive('disconnect')->once();
        $this->app->instance(SupportImap::class, $imap);
        $mailbox = app(SupportMailbox::class);
        $this->assertSame('active', $mailbox->activation(true, function (): void {
            $this->travelTo(Carbon::parse('2026-10-28 12:00:05.500000', 'UTC'));
        })['status']);
        $state = SupportMailboxState::firstOrFail();
        $this->assertSame('12:00:00.100000', $state->activation_window_started_at->format('H:i:s.u'));
        $this->assertSame('12:00:02.300000', $state->activation_window_finished_at->format('H:i:s.u'));
        $snapshot = $state->only(['activated_at', 'baseline_uid', 'baseline_uidvalidity', 'activation_window_started_at', 'activation_window_finished_at']);
        $dates = [1 => '11:59:59', 2 => '12:00:00', 3 => '12:00:01', 4 => '12:00:02', 5 => '12:00:03', 6 => '12:00:06'];
        $metadata = [];
        foreach ($dates as $uid => $time) {
            $metadata[$uid] = ['bytes' => 500, 'received_at' => '2026-10-28 '.$time.' +0000'];
            $this->original($uid, null, $ticket);
        }
        $imap = Mockery::mock(SupportImap::class);
        $imap->shouldReceive('connect')->twice()->andReturn(['uidvalidity' => 2, 'uidnext' => 7]);
        $imap->shouldReceive('metadata')->once()->with(1, 6)->andReturn($metadata);
        $imap->shouldReceive('disconnect')->twice();
        $this->app->instance(SupportImap::class, $imap);
        // Simulate a persisted original before the timestamp/classification write was completed.
        foreach ($metadata as $uid => $meta) {
            SupportMailReceipt::where('uid', $uid)->update(['received_at' => Carbon::parse($meta['received_at'])]);
        }
        $this->assertSame('active', $mailbox->run(recover: true));
        $this->assertDatabaseHas('support_mail_receipts', ['uid' => 1, 'outcome' => 'historical']);
        foreach ([2, 3, 4] as $uid) {
            $this->assertDatabaseHas('support_mail_receipts', ['uid' => $uid, 'outcome' => 'recovery_manual', 'reason' => 'ACTIVATION_WINDOW_AMBIGUOUS']);
        }
        foreach ([5, 6] as $uid) {
            $this->assertDatabaseHas('support_mail_receipts', ['uid' => $uid, 'outcome' => 'accepted']);
        }
        $this->assertSame('active', $mailbox->run(recover: true));
        app(SupportRecovery::class)->run();
        $this->assertDatabaseCount('admin_support_tickets', 1);
        $this->assertDatabaseCount('support_messages', 2);
        $this->assertEquals($snapshot, $state->fresh()->only(array_keys($snapshot)));
    }

    public function test_real_s3_adapter_keys_are_stable_across_deployment_paths_without_credentials_or_network(): void
    {
        $storagePath = $this->app->storagePath();
        $repository = Env::getRepository();
        $originalDriver = $repository->get('SUPPORT_STORAGE_DRIVER');
        $originalPrefix = $repository->get('SUPPORT_S3_PREFIX');
        $keys = [];
        $client = Mockery::mock(S3ClientInterface::class);
        $client->shouldReceive('doesObjectExistV2')->twice()->andReturnUsing(function ($bucket, $key) use (&$keys): bool {
            $keys[] = $key;

            return true;
        });
        try {
            $repository->set('SUPPORT_STORAGE_DRIVER', 's3');
            $repository->set('SUPPORT_S3_PREFIX', 'support-private/');
            foreach (['/srv/deployment-one/storage', '/srv/deployment-two/storage'] as $root) {
                $this->app->useStoragePath($root);
                $config = require config_path('filesystems.php');
                $disk = $config['disks']['support-private'];
                $this->assertSame('private', $disk['visibility']);
                $adapter = new AwsS3V3Adapter($client, 'synthetic-bucket', $disk['root']);
                $this->assertTrue($adapter->fileExists('images/1/full.webp'));
            }
            $this->assertSame(['support-private/images/1/full.webp', 'support-private/images/1/full.webp'], $keys);
            $repository->set('SUPPORT_STORAGE_DRIVER', 'local');
            $config = require config_path('filesystems.php');
            $this->assertSame($this->app->storagePath('app/support-private'), $config['disks']['support-private']['root']);
        } finally {
            $this->app->useStoragePath($storagePath);
            $originalDriver === null ? $repository->clear('SUPPORT_STORAGE_DRIVER') : $repository->set('SUPPORT_STORAGE_DRIVER', $originalDriver);
            $originalPrefix === null ? $repository->clear('SUPPORT_S3_PREFIX') : $repository->set('SUPPORT_S3_PREFIX', $originalPrefix);
        }
    }
}
