<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\VideoWatermarkSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class VideoWatermarkSessionApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $this->withHeader('Referer', 'http://localhost:3000/');
    }

    public function test_logout_closes_current_watermark_session_before_session_invalidation(): void
    {
        Carbon::setTestNow('2026-08-18 01:00:00');

        try {
            $user = User::factory()->create();
            $watermark = $this->createSession($user, [
                'auth_session_hash' => hash('sha256', 'different-session'),
            ]);
            Sanctum::actingAs($user);

            $this->withSession(['video_watermark_session_id' => $watermark->id])
                ->postJson('/api/v1/auth/logout')
                ->assertOk()
                ->assertExactJson(['status' => 'ok']);

            $closed = $watermark->refresh();

            $this->assertTrue($closed->ended_at->equalTo(now()));
            $this->assertSame(VideoWatermarkSession::END_REASON_LOGOUT, $closed->end_reason);
            $this->assertTrue($closed->purge_after->equalTo(now()->addYears(3)));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_idle_logout_closes_current_watermark_session_with_idle_timeout_reason(): void
    {
        $user = User::factory()->create();
        $watermark = $this->createSession($user, [
            'auth_session_hash' => hash('sha256', 'different-session'),
        ]);
        Sanctum::actingAs($user);

        $this->withSession(['video_watermark_session_id' => $watermark->id])
            ->postJson('/api/v1/auth/idle-logout')
            ->assertOk()
            ->assertExactJson(['status' => 'ok']);

        $this->assertSame(VideoWatermarkSession::END_REASON_IDLE_TIMEOUT, $watermark->refresh()->end_reason);
    }

    public function test_logout_invalidates_the_auth_session_when_watermark_close_throws(): void
    {
        $user = User::factory()->create();
        Log::spy();
        DB::shouldReceive('transaction')
            ->once()
            ->andThrow(new RuntimeException('watermark retention failed'));

        $this->actingAs($user, 'web')
            ->withSession(['video_watermark_session_id' => (string) Str::uuid()])
            ->postJson('/api/v1/auth/logout')
            ->assertOk()
            ->assertExactJson(['status' => 'ok']);

        $this->assertGuest('web');
        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn (string $message, array $context): bool => $message === 'Video watermark session close failed during logout.'
                && $context === ['exception' => RuntimeException::class]);
    }

    public function test_cleanup_closes_orphaned_sessions_and_purges_expired_ended_rows(): void
    {
        Carbon::setTestNow('2026-08-18 01:00:00');

        try {
            config([
                'session.driver' => 'database',
                'session.encrypt' => false,
                'session.lifetime' => 60,
                'session.table' => 'sessions',
            ]);
            $orphan = $this->createSession(User::factory()->create(), [
                'code' => 'CMS-7K4M-92QF',
                'auth_session_hash' => hash('sha256', 'orphan-session'),
                'started_at' => now()->subHours(2),
                'last_used_at' => now()->subHours(2),
            ]);
            $expired = $this->createSession(User::factory()->create(), [
                'code' => 'CMS-8H3N-4R5S',
                'auth_session_hash' => hash('sha256', 'expired-session'),
                'ended_at' => now()->subYears(3),
                'end_reason' => VideoWatermarkSession::END_REASON_LOGOUT,
                'purge_after' => now()->subSecond(),
            ]);

            $this->artisan('app:cleanup-video-watermark-sessions')
                ->expectsOutput('closed=1 purged=1')
                ->assertExitCode(0);

            $closed = $orphan->refresh();
            $this->assertTrue($closed->ended_at->equalTo(now()));
            $this->assertSame(VideoWatermarkSession::END_REASON_ORPHAN_EXPIRED, $closed->end_reason);
            $this->assertTrue($closed->purge_after->equalTo(now()->addYears(3)));
            $this->assertDatabaseMissing('video_watermark_sessions', ['id' => $expired->id]);
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createSession(User $user, array $overrides = []): VideoWatermarkSession
    {
        return VideoWatermarkSession::query()->create(array_replace([
            'id' => (string) Str::uuid(),
            'code' => 'CMS-6T7V-WXYZ',
            'auth_session_hash' => hash('sha256', 'default-session'),
            'user_id' => $user->id,
            'started_at' => now(),
            'last_used_at' => now(),
        ], $overrides));
    }
}
