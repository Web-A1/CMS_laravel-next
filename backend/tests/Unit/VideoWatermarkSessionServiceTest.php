<?php

namespace Tests\Unit;

use App\Models\User;
use App\Models\VideoWatermarkLookupAuditEvent;
use App\Models\VideoWatermarkSession;
use App\Services\VideoWatermarks\VideoWatermarkSessionService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

class VideoWatermarkSessionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_current_playback_creates_one_canonical_code_per_auth_session(): void
    {
        $user = User::factory()->create();
        $request = $this->requestForSession('session-one');

        $first = app(VideoWatermarkSessionService::class)->currentForPlayback($request, $user);
        $second = app(VideoWatermarkSessionService::class)->currentForPlayback($request, $user);

        $this->assertSame($first->id, $second->id);
        $this->assertMatchesRegularExpression('/^CMS-[2-9A-HJKMNP-Z]{4}-[2-9A-HJKMNP-Z]{4}$/', $first->code);
        $this->assertDatabaseCount('video_watermark_sessions', 1);
        $this->assertFalse(Schema::hasColumn('video_watermark_sessions', 'ip_address'));
        $this->assertFalse(Schema::hasColumn('video_watermark_sessions', 'user_agent'));
        $this->assertFalse(Schema::hasColumn('video_watermark_sessions', 'device_metadata'));
    }

    public function test_current_playback_retries_a_colliding_visible_code(): void
    {
        $existingUser = User::factory()->create();
        VideoWatermarkSession::query()->create([
            'id' => (string) Str::uuid(),
            'code' => 'CMS-7K4M-92QF',
            'auth_session_hash' => hash('sha256', 'existing-session'),
            'user_id' => $existingUser->id,
            'started_at' => now(),
            'last_used_at' => now(),
        ]);
        $user = User::factory()->create();

        Str::createRandomStringsUsingSequence([
            'session-id-placeholder',
            '7K4M92QF',
            '8H3N4R5S',
        ]);

        try {
            $watermark = app(VideoWatermarkSessionService::class)->currentForPlayback(
                $this->requestForSession('new-session'),
                $user,
            );
        } finally {
            Str::createRandomStringsNormally();
        }

        $this->assertSame('CMS-8H3N-4R5S', $watermark->code);
        $this->assertDatabaseCount('video_watermark_sessions', 2);
    }

    public function test_lookup_normalizes_case_spaces_and_dashes(): void
    {
        $user = User::factory()->create();
        $admin = User::factory()->create(['role' => 'admin_full']);
        $session = VideoWatermarkSession::query()->create([
            'id' => (string) Str::uuid(),
            'code' => 'CMS-7K4M-92QF',
            'auth_session_hash' => hash('sha256', 'session-one'),
            'user_id' => $user->id,
            'started_at' => now(),
            'last_used_at' => now(),
        ]);

        $found = app(VideoWatermarkSessionService::class)->lookupByInput(' cms 7k4m 92qf ', $admin);

        $this->assertSame($session->id, $found?->id);
        $this->assertDatabaseHas('video_watermark_lookup_audit_events', [
            'actor_user_id' => $admin->id,
            'watermark_session_id' => $session->id,
            'normalized_code' => 'CMS7K4M92QF',
            'result' => 'found',
        ]);
    }

    public function test_lookup_audits_valid_not_found_and_malformed_inputs(): void
    {
        $admin = User::factory()->create(['role' => 'admin_full']);
        $service = app(VideoWatermarkSessionService::class);

        $this->assertNull($service->lookupByInput('CMS 7K4M 92QF', $admin));
        $this->assertDatabaseHas('video_watermark_lookup_audit_events', [
            'actor_user_id' => $admin->id,
            'normalized_code' => 'CMS7K4M92QF',
            'result' => 'not_found',
        ]);

        $this->assertNull($service->lookupByInput('not a watermark code', $admin));
        $this->assertDatabaseHas('video_watermark_lookup_audit_events', [
            'actor_user_id' => $admin->id,
            'normalized_code' => null,
            'result' => 'not_found',
        ]);
    }

    public function test_close_current_marks_session_for_purge_three_years_after_end(): void
    {
        Carbon::setTestNow('2026-08-17 18:00:00');

        try {
            $user = User::factory()->create();
            $request = $this->requestForSession('session-one');
            $watermark = app(VideoWatermarkSessionService::class)->currentForPlayback($request, $user);

            app(VideoWatermarkSessionService::class)->closeCurrent(
                $request,
                VideoWatermarkSession::END_REASON_LOGOUT,
            );

            $closed = $watermark->refresh();
            $this->assertTrue($closed->ended_at->equalTo(now()));
            $this->assertSame(VideoWatermarkSession::END_REASON_LOGOUT, $closed->end_reason);
            $this->assertTrue($closed->purge_after->equalTo(now()->addYears(3)));
            $this->assertSame(VideoWatermarkSession::STATUS_ENDED, $closed->computedStatus());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_session_id_regeneration_preserves_and_then_closes_the_current_code(): void
    {
        Carbon::setTestNow('2026-08-17 18:00:00');

        try {
            $user = User::factory()->create();
            $initialSessionId = str_repeat('A', 40);
            $regeneratedSessionId = str_repeat('B', 40);
            $request = $this->requestForSession($initialSessionId);
            $service = app(VideoWatermarkSessionService::class);
            $first = $service->currentForPlayback($request, $user);

            $request->session()->setId($regeneratedSessionId);
            $second = $service->currentForPlayback($request, $user);
            $service->closeCurrent($request, VideoWatermarkSession::END_REASON_LOGOUT);

            $closed = $first->refresh();
            $this->assertSame($first->id, $second->id);
            $this->assertSame($first->code, $second->code);
            $this->assertSame(hash('sha256', $regeneratedSessionId), $closed->auth_session_hash);
            $this->assertTrue($closed->ended_at->equalTo(now()));
            $this->assertTrue($closed->purge_after->equalTo(now()->addYears(3)));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_cleanup_closes_orphans_and_purges_expired_sessions(): void
    {
        Carbon::setTestNow('2026-08-17 18:00:00');

        try {
            config([
                'session.driver' => 'database',
                'session.encrypt' => false,
                'session.lifetime' => 60,
                'session.table' => 'sessions',
            ]);
            $expiredAuthSessionId = str_repeat('D', 40);
            $liveSessionId = str_repeat('C', 40);
            $missing = $this->createSession([
                'code' => 'CMS-7K4M-92QF',
                'auth_session_hash' => hash('sha256', 'missing-session'),
                'started_at' => now()->subHours(2),
                'last_used_at' => now()->subHours(2),
            ]);
            $expiredAuthSession = $this->createSession([
                'code' => 'CMS-8H3N-4R5S',
                'auth_session_hash' => hash('sha256', $expiredAuthSessionId),
                'started_at' => now()->subHours(2),
                'last_used_at' => now()->subHours(2),
            ]);
            $live = $this->createSession([
                'code' => 'CMS-6T7V-WXYZ',
                'auth_session_hash' => hash('sha256', $liveSessionId),
                'started_at' => now()->subHours(2),
                'last_used_at' => now()->subHours(2),
            ]);
            $expired = $this->createSession([
                'code' => 'CMS-9ABC-DEFG',
                'auth_session_hash' => hash('sha256', 'purge-session'),
                'ended_at' => now()->subYears(3),
                'end_reason' => VideoWatermarkSession::END_REASON_LOGOUT,
                'purge_after' => now()->subSecond(),
            ]);
            $this->persistDatabaseSession(
                $expiredAuthSessionId,
                $expiredAuthSession,
                now()->subMinutes(61),
            );
            $this->persistDatabaseSession($liveSessionId, $live, now());

            $service = app(VideoWatermarkSessionService::class);

            $this->assertSame(2, $service->closeOrphanedSessions());
            $this->assertSame(VideoWatermarkSession::END_REASON_ORPHAN_EXPIRED, $missing->refresh()->end_reason);
            $this->assertSame(VideoWatermarkSession::END_REASON_ORPHAN_EXPIRED, $expiredAuthSession->refresh()->end_reason);
            $this->assertNull($live->refresh()->ended_at);
            $this->assertTrue($missing->purge_after->equalTo(now()->addYears(3)));
            $this->assertSame(
                $live->id,
                $service->currentForPlayback($this->requestForSession($liveSessionId), $live->user)->id,
            );
            $this->assertSame(VideoWatermarkSession::STATUS_EXPIRED, $expired->computedStatus());
            $this->assertSame(1, $service->purgeExpiredSessions());
            $this->assertDatabaseMissing('video_watermark_sessions', ['id' => $expired->id]);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_cleanup_keeps_old_sessions_when_the_configured_driver_cannot_prove_expiry(): void
    {
        config(['session.driver' => 'array']);
        $watermark = $this->createSession([
            'code' => 'CMS-7K4M-92QF',
            'auth_session_hash' => hash('sha256', 'uninspectable-session'),
            'started_at' => now()->subHours(2),
            'last_used_at' => now()->subHours(2),
        ]);

        $this->assertSame(0, app(VideoWatermarkSessionService::class)->closeOrphanedSessions());
        $this->assertNull($watermark->refresh()->ended_at);
    }

    public function test_cleanup_uses_file_backed_laravel_session_expiry(): void
    {
        Carbon::setTestNow('2026-08-17 18:00:00');
        $directory = storage_path('framework/testing-video-watermark-sessions-'.Str::uuid());

        try {
            File::ensureDirectoryExists($directory);
            config([
                'session.driver' => 'file',
                'session.encrypt' => false,
                'session.files' => $directory,
                'session.lifetime' => 60,
            ]);
            $expired = $this->createSession([
                'code' => 'CMS-7K4M-92QF',
                'auth_session_hash' => hash('sha256', 'expired-file-session'),
                'started_at' => now()->subHours(2),
                'last_used_at' => now()->subHours(2),
            ]);
            $live = $this->createSession([
                'code' => 'CMS-8H3N-4R5S',
                'auth_session_hash' => hash('sha256', 'live-file-session'),
                'started_at' => now()->subHours(2),
                'last_used_at' => now()->subHours(2),
            ]);
            $this->persistFileSession($directory, str_repeat('E', 40), $expired, now()->subMinutes(61));
            $this->persistFileSession($directory, str_repeat('F', 40), $live, now());

            $this->assertSame(1, app(VideoWatermarkSessionService::class)->closeOrphanedSessions());
            $this->assertSame(VideoWatermarkSession::END_REASON_ORPHAN_EXPIRED, $expired->refresh()->end_reason);
            $this->assertNull($live->refresh()->ended_at);
        } finally {
            File::deleteDirectory($directory);
            Carbon::setTestNow();
        }
    }

    public function test_migration_defines_a_postgresql_truncate_guard_for_lookup_audit_events(): void
    {
        $migration = (string) file_get_contents(
            database_path('migrations/2026_08_17_180000_create_video_watermark_sessions_table.php'),
        );

        $this->assertStringContainsString('BEFORE TRUNCATE ON video_watermark_lookup_audit_events', $migration);
        $this->assertStringContainsString('FOR EACH STATEMENT EXECUTE FUNCTION video_watermark_lookup_audit_events_immutable()', $migration);
    }

    public function test_lookup_audit_events_prevent_model_updates_and_deletes(): void
    {
        $event = VideoWatermarkLookupAuditEvent::query()->create([
            'actor_user_id' => User::factory()->create()->id,
            'normalized_code' => 'CMS7K4M92QF',
            'result' => 'not_found',
            'created_at' => now(),
        ]);

        try {
            $event->update(['result' => 'found']);
            $this->fail('Model update unexpectedly succeeded.');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }

        try {
            $event->delete();
            $this->fail('Model delete unexpectedly succeeded.');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_lookup_audit_events_prevent_bulk_updates_and_deletes(): void
    {
        $event = VideoWatermarkLookupAuditEvent::query()->create([
            'actor_user_id' => User::factory()->create()->id,
            'normalized_code' => 'CMS7K4M92QF',
            'result' => 'not_found',
            'created_at' => now(),
        ]);

        try {
            VideoWatermarkLookupAuditEvent::query()
                ->whereKey($event->id)
                ->update(['result' => 'found']);
            $this->fail('Bulk update unexpectedly succeeded.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        try {
            VideoWatermarkLookupAuditEvent::query()->whereKey($event->id)->delete();
            $this->fail('Bulk delete unexpectedly succeeded.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }

    private function requestForSession(string $sessionId): Request
    {
        $request = Request::create('/api/v1/learning/video-playback', 'POST');
        $request->setLaravelSession(app('session.store'));
        $request->session()->setId($sessionId);

        return $request;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createSession(array $attributes): VideoWatermarkSession
    {
        return VideoWatermarkSession::query()->create([
            'id' => (string) Str::uuid(),
            'user_id' => User::factory()->create()->id,
            'started_at' => now(),
            'last_used_at' => now(),
            ...$attributes,
        ]);
    }

    private function persistDatabaseSession(
        string $sessionId,
        VideoWatermarkSession $watermark,
        Carbon $lastActivity,
    ): void {
        DB::table('sessions')->insert([
            'id' => $sessionId,
            'user_id' => $watermark->user_id,
            'payload' => base64_encode(json_encode([
                'video_watermark_session_id' => $watermark->id,
            ], JSON_THROW_ON_ERROR)),
            'last_activity' => $lastActivity->timestamp,
        ]);
    }

    private function persistFileSession(
        string $directory,
        string $sessionId,
        VideoWatermarkSession $watermark,
        Carbon $lastModified,
    ): void {
        $path = $directory.'/'.$sessionId;

        File::put($path, json_encode([
            'video_watermark_session_id' => $watermark->id,
        ], JSON_THROW_ON_ERROR));
        touch($path, $lastModified->timestamp);
    }
}
