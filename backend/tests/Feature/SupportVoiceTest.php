<?php

namespace Tests\Feature;

use App\Jobs\ProcessSupportImage;
use App\Models\AdminSupportTicket;
use App\Models\SupportAttachment;
use App\Models\SupportMediaDeletion;
use App\Models\SupportMediaReservation;
use App\Models\User;
use App\Services\Support\SupportMedia;
use App\Services\Support\SupportMediaRemoval;
use App\Services\Support\SupportOperations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Symfony\Component\Process\Process;
use Tests\Concerns\SupportDatabase;
use Tests\TestCase;

class SupportVoiceTest extends TestCase
{
    use SupportDatabase;

    private array $fixtures = [];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('support-private');
        config(['support.ip_per_hour' => 1000, 'support.email_per_hour' => 1000]);
    }

    protected function tearDown(): void
    {
        foreach ($this->fixtures as $path) {
            @unlink($path);
        }
        parent::tearDown();
    }

    private function media(string $format = 'webm', float $seconds = 1.5, array $extra = [], string $source = 'sine=frequency=440:sample_rate=48000'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'support-voice-');
        $this->fixtures[] = $path;
        $args = $format === 'webm' ? ['-c:a', 'libopus', '-live', '1'] : ['-c:a', 'aac', '-movflags', 'frag_keyframe+empty_moov'];
        $process = new Process(['ffmpeg', '-v', 'error', '-y', '-f', 'lavfi', '-i', $source, '-t', (string) $seconds, ...$args, ...$extra, '-f', $format, $path]);
        $process->mustRun();

        // Deliberately false client metadata: the server must inspect the binary.
        return new UploadedFile($path, 'untrusted.bin', 'application/octet-stream', null, true);
    }

    private function submit(array $payload, string $key = 'voice-submit-key')
    {
        return $this->withHeader('Idempotency-Key', $key)->post('/api/v1/support-tickets', ['email' => 'voice@example.test', ...$payload], ['Accept' => 'application/json']);
    }

    public function test_live_webm_without_metadata_duration_is_accepted_once_and_private(): void
    {
        $voice = $this->media();
        $probe = new Process(['ffprobe', '-v', 'error', '-show_format', '-of', 'json', $voice->getRealPath()]);
        $probe->mustRun();
        $this->assertArrayNotHasKey('duration', json_decode($probe->getOutput(), true)['format']);
        $retry = UploadedFile::fake()->createWithContent('untrusted.bin', file_get_contents($voice->getRealPath()));
        $first = $this->submit(['voice' => $voice])->assertCreated();
        $this->submit(['voice' => $retry])->assertCreated()->assertExactJson($first->json());
        $this->submit(['voice' => $this->media(seconds: 2)])->assertConflict();
        $this->assertDatabaseCount('admin_support_tickets', 1);
        $this->assertDatabaseCount('support_attachments', 1);
        $ticket = AdminSupportTicket::firstOrFail();
        $file = SupportAttachment::firstOrFail();
        $this->assertSame('', $ticket->messages->first()->body);
        $this->assertSame('Голосовое обращение', $ticket->subject);
        $this->assertSame('audio/webm', $file->mime);
        $this->assertSame('ready', $file->status);
        $this->assertNull($file->source_key);
        $this->assertNull($file->thumbnail_key);
        $this->assertCount(1, Storage::disk('support-private')->allFiles());
        Queue::assertNothingPushed();
        $url = '/api/v1/admin/support-tickets/'.$ticket->id.'/attachments/'.$file->id;
        $this->getJson($url)->assertUnauthorized();
        Sanctum::actingAs(User::factory()->create(['role' => 'admin_limited']));
        $this->getJson($url)->assertForbidden();
        Sanctum::actingAs(User::factory()->create(['role' => 'admin_full']));
        $this->get($url)->assertOk()->assertHeader('Content-Type', 'audio/webm')->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Cache-Control', 'no-store, private');
        $this->get('/api/v1/admin/support-tickets/999/attachments/'.$file->id)->assertNotFound();
        app(SupportOperations::class)->close(auth()->user(), $ticket->id, $ticket->revision, 'voice-close-key');
        $this->get($url)->assertNotFound();
        app(SupportMediaRemoval::class)->run(SupportMediaDeletion::sole()->id);
        $this->assertSame('completed', SupportMediaDeletion::sole()->status);
        $this->assertSame([], Storage::disk('support-private')->allFiles());
    }

    public function test_mp4_and_images_keep_existing_warnings_and_admin_markup(): void
    {
        $this->submit(['description' => 'Also text', 'voice' => $this->media('mp4'), 'images' => [UploadedFile::fake()->createWithContent('bad.png', 'invalid')]])->assertCreated()->assertJsonCount(1, 'data.warnings');
        $ticket = AdminSupportTicket::with('messages.attachments')->firstOrFail();
        $this->assertSame('audio/mp4', $ticket->messages->first()->attachments->first()->mime);
        $ticket->messages->first()->attachments()->create(['disk' => 'support-private', 'preview_key' => 'image', 'thumbnail_key' => 'thumb', 'mime' => 'image/png', 'bytes' => 1, 'status' => 'ready']);
        $html = view('filament.operations.support-history', ['ticket' => $ticket->fresh('messages.attachments'), 'events' => collect()])->render();
        $this->assertStringContainsString('<audio controls preload="metadata"', $html);
        $this->assertStringContainsString('?thumbnail=1', $html);
    }

    public function test_decoded_duration_boundary_accepts_180_and_rejects_181_even_with_text(): void
    {
        $this->submit(['voice' => $this->media(seconds: 180)], 'voice-duration-180')->assertCreated();
        $this->submit(['description' => 'Keep draft', 'voice' => $this->media(seconds: 181)], 'voice-duration-181')->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['voice']]]);
        $this->assertDatabaseCount('admin_support_tickets', 1);
    }

    public function test_long_small_opus_is_rejected_without_exhausting_memory(): void
    {
        $voice = $this->media(seconds: 14400, extra: ['-b:a', '6000', '-frame_duration', '60'], source: 'anullsrc=channel_layout=mono:sample_rate=8000');
        $this->assertLessThanOrEqual(10 * 1024 * 1024, $voice->getSize());
        $this->submit(['description' => 'Must remain a draft', 'voice' => $voice], 'long-small-voice')->assertUnprocessable()->assertJsonPath('error.fields.voice.0', 'Запись содержит слишком много аудиоданных для проверки.');
        $this->assertDatabaseCount('admin_support_tickets', 0);
        $this->assertDatabaseCount('support_attachments', 0);
        $this->assertSame([], Storage::disk('support-private')->allFiles());
    }

    public function test_five_images_and_voice_are_independent_and_only_images_are_queued(): void
    {
        $this->submit(['description' => 'Text with six files', 'voice' => $this->media('mp4'), 'images' => array_map(fn ($i) => UploadedFile::fake()->image('screen-'.$i.'.png', 20, 20), range(1, 5))])->assertCreated()->assertJsonCount(0, 'data.warnings');
        $this->assertDatabaseCount('support_attachments', 6);
        $this->assertSame(5, SupportAttachment::where('status', 'pending')->count());
        $audio = SupportAttachment::where('mime', 'audio/mp4')->firstOrFail();
        $this->assertSame('ready', $audio->status);
        Queue::assertPushed(ProcessSupportImage::class, 5);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin_full']));
        $this->get('/api/v1/admin/support-tickets/'.$audio->message->ticket_id.'/attachments/'.$audio->id)->assertOk()->assertHeader('Content-Type', 'audio/mp4');
    }

    public function test_invalid_voice_rejects_whole_request(): void
    {
        $cases = [
            UploadedFile::fake()->createWithContent('zero.webm', ''),
            UploadedFile::fake()->createWithContent('fake.webm', 'not audio'),
            UploadedFile::fake()->create('large.webm', 10241),
            new UploadedFile($this->media()->getRealPath(), 'failed.webm', 'audio/webm', UPLOAD_ERR_INI_SIZE, true),
            [$this->media()],
            $this->media('mp4', extra: ['-c:a', 'alac']),
        ];
        foreach ($cases as $index => $voice) {
            $this->submit(['description' => 'Text must not be accepted', 'voice' => $voice], 'invalid-voice-'.$index)->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['voice']]]);
        }
        $this->submit(['description' => '   ', 'images' => [UploadedFile::fake()->image('ok.png')]])->assertUnprocessable();
        $this->assertDatabaseCount('admin_support_tickets', 0);
        $this->assertDatabaseCount('support_messages', 0);
    }

    public function test_video_track_and_truncated_audio_are_rejected(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'support-video-');
        $this->fixtures[] = $path;
        (new Process(['ffmpeg', '-v', 'error', '-y', '-f', 'lavfi', '-i', 'color=black:s=16x16:r=1', '-f', 'lavfi', '-i', 'sine=frequency=440', '-t', '1', '-c:v', 'mpeg4', '-c:a', 'aac', '-f', 'mp4', $path]))->mustRun();
        $this->submit(['voice' => new UploadedFile($path, 'audio.m4a', 'audio/mp4', null, true)])->assertUnprocessable();
        $voice = $this->media();
        file_put_contents($voice->getRealPath(), substr(file_get_contents($voice->getRealPath()), 0, 300));
        $this->submit(['voice' => $voice])->assertUnprocessable();
        $this->assertDatabaseCount('admin_support_tickets', 0);
    }

    public function test_storage_failure_leaves_no_ticket_or_voice(): void
    {
        $disk = Mockery::mock(Storage::disk('support-private'))->makePartial();
        Storage::set('support-private', $disk);
        $disk->shouldReceive('put')->once()->andReturnFalse();
        $this->submit(['description' => 'Retry me', 'voice' => $this->media()])->assertStatus(503);
        $this->assertDatabaseCount('admin_support_tickets', 0);
        $this->assertSame('uncertain', SupportMediaReservation::sole()->files['voice']['state']);
    }

    public function test_database_failure_keeps_a_durable_owner_and_recovery_removes_only_new_audio(): void
    {
        Storage::disk('support-private')->put('existing-object', 'preserved');
        $event = 'eloquent.creating: '.AdminSupportTicket::class;
        Event::listen($event, function (): void {
            throw new \RuntimeException('simulated database failure');
        });
        try {
            app(SupportOperations::class)->submit('voice@example.test', '', 'rollback-voice-key', voice: $this->media());
            $this->fail('Expected the simulated database failure');
        } catch (\RuntimeException $e) {
            $this->assertSame('simulated database failure', $e->getMessage());
        } finally {
            Event::forget($event);
        }
        $this->assertCount(2, Storage::disk('support-private')->allFiles());
        $owner = SupportMediaReservation::sole();
        $this->assertNull($owner->ticket_id);
        $this->assertSame('acknowledged', $owner->files['voice']['state']);
        $owner->update(['created_at' => now()->subMinutes(31)]);
        app(SupportMedia::class)->recoverOrphans();
        $this->assertSame('MEDIA_ORPHAN_REMOVED', $owner->fresh()->error_code);
        $this->assertSame(['existing-object'], Storage::disk('support-private')->allFiles());
        $this->assertDatabaseCount('admin_support_tickets', 0);
    }

    public function test_missing_runtime_fails_closed_but_text_remains_available_and_hash_compatible(): void
    {
        config(['support.ffprobe' => '/nonexistent/support-ffprobe']);
        $this->submit(['voice' => $this->media()])->assertStatus(503);
        $body = 'Text only';
        $this->submit(['description' => $body])->assertCreated();
        $this->assertSame(hash('sha256', json_encode(['voice@example.test', $body, []])), AdminSupportTicket::firstOrFail()->submission_hash);
        app(SupportOperations::class)->submit('voice@example.test', $body, 'voice-submit-key');
        $this->assertDatabaseCount('admin_support_tickets', 1);
    }
}
