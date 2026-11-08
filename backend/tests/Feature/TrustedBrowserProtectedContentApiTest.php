<?php

namespace Tests\Feature;

use App\Contracts\ProtectedFiles\TemporaryDownloadUrlIssuer;
use App\Data\Kinescope\KinescopePlaybackData;
use App\Jobs\GenerateProtectedFileDeliveryJob;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\LessonMaterial;
use App\Models\LessonMaterialFile;
use App\Models\ProtectedFileDelivery;
use App\Models\Subject;
use App\Models\TrustedBrowserAccount;
use App\Models\TrustedBrowserCredential;
use App\Models\TrustedBrowserSessionLink;
use App\Models\TrustedBrowserSlot;
use App\Models\User;
use App\Models\UserEntitlementGrant;
use App\Services\Kinescope\KinescopeVideoPlaybackService;
use App\Services\ProtectedFiles\ProtectedFileDeliveryService;
use App\Services\TrustedBrowser\TrustedBrowserCookie;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class TrustedBrowserProtectedContentApiTest extends TestCase
{
    use RefreshDatabase;

    private const CSRF_TOKEN = 'protected-content-csrf-token';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.env' => 'testing',
            'protected_files.output_disk' => 'local',
            'sanctum.stateful' => ['localhost'],
            'trusted_browser.mode' => 'enforce',
        ]);
    }

    public function test_enforce_blocks_unknown_browser_before_provider_or_delivery_work(): void
    {
        Queue::fake();
        $user = User::factory()->create(['role' => 'user_no_purchase']);
        [$subject, $course, $lesson] = $this->createLesson();
        $video = $this->createMaterial($lesson, LessonMaterial::KIND_VIDEO);
        $pdf = $this->createMaterial($lesson, LessonMaterial::KIND_PDF);
        $source = $this->createSource($pdf);
        $delivery = $this->createDelivery($user, $source, ProtectedFileDelivery::STATUS_READY);
        $this->createGrant($user, $lesson);
        $this->authenticate($user);
        $this->forbidProtectedGrantDependencies();

        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $expected = $this->trustedBrowserDenial(
            'TRUSTED_BROWSER_REQUIRED',
            'Подтвердите этот браузер для доступа к защищённым материалам.',
        );
        $deliveriesUrl = $this->deliveriesUrl($subject, $course, $lesson);
        $deliveryUrl = "{$deliveriesUrl}/{$delivery->public_id}";

        $this->postJson($this->playbackUrl($subject, $course, $lesson), [
            'materialId' => $video->id,
        ])->assertForbidden()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertExactJson($expected);
        $this->postJson($deliveriesUrl, [
            'materialId' => $pdf->id,
        ])->assertForbidden()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertExactJson($expected);
        $this->postJson("{$deliveriesUrl}/prewarm", [])
            ->assertForbidden()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertExactJson($expected);
        $this->getJson($deliveryUrl)
            ->assertForbidden()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertExactJson($expected);
        $this->postJson("{$deliveryUrl}/access-grants", [
            'confidentialityAcknowledged' => true,
        ])->assertForbidden()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertExactJson($expected);

        $this->assertFalse(collect($queries)->contains(
            fn (string $query): bool => str_contains($query, 'lesson_material_kinescope_videos')
                || str_contains($query, 'lesson_material_files')
                || str_contains($query, 'protected_file_deliveries'),
        ));
        Queue::assertNothingPushed();
    }

    public function test_entitlement_denial_precedes_trusted_browser_enforcement_for_every_grant_path(): void
    {
        Queue::fake();
        $user = User::factory()->create(['role' => 'user_no_purchase']);
        [$subject, $course, $lesson] = $this->createLesson();
        $video = $this->createMaterial($lesson, LessonMaterial::KIND_VIDEO);
        $pdf = $this->createMaterial($lesson, LessonMaterial::KIND_PDF);
        $source = $this->createSource($pdf);
        $delivery = $this->createDelivery($user, $source, ProtectedFileDelivery::STATUS_READY);
        $this->authenticate($user);
        $this->forbidProtectedGrantDependencies();

        $expected = $this->accessDenied();
        $deliveriesUrl = $this->deliveriesUrl($subject, $course, $lesson);
        $deliveryUrl = "{$deliveriesUrl}/{$delivery->public_id}";

        $this->postJson($this->playbackUrl($subject, $course, $lesson), [
            'materialId' => $video->id,
        ])->assertForbidden()->assertExactJson($expected);
        $this->postJson($deliveriesUrl, [
            'materialId' => $pdf->id,
        ])->assertForbidden()->assertExactJson($expected);
        $this->postJson("{$deliveriesUrl}/prewarm", [])
            ->assertForbidden()
            ->assertExactJson($expected);
        $this->getJson($deliveryUrl)
            ->assertForbidden()
            ->assertExactJson($expected);
        $this->postJson("{$deliveryUrl}/access-grants", [
            'confidentialityAcknowledged' => true,
        ])->assertForbidden()->assertExactJson($expected);

        $this->assertDatabaseCount('trusted_browser_accounts', 0);
        Queue::assertNothingPushed();
    }

    public function test_enforce_maps_support_required_state_to_stable_denial(): void
    {
        $user = User::factory()->create(['role' => 'user_no_purchase']);
        [$subject, $course, $lesson] = $this->createLesson();
        $video = $this->createMaterial($lesson, LessonMaterial::KIND_VIDEO);
        $this->createGrant($user, $lesson);
        $this->createOccupiedBrowserSlots($user);
        $this->authenticate($user);
        $this->forbidProtectedGrantDependencies();

        $this->postJson($this->playbackUrl($subject, $course, $lesson), [
            'materialId' => $video->id,
        ])->assertForbidden()->assertExactJson($this->trustedBrowserDenial(
            'TRUSTED_BROWSER_SUPPORT_REQUIRED',
            'Для восстановления доступа обратитесь в поддержку.',
        ));
    }

    public function test_enforce_maps_risk_locked_state_to_stable_denial(): void
    {
        $user = User::factory()->create(['role' => 'user_no_purchase']);
        [$subject, $course, $lesson] = $this->createLesson();
        $video = $this->createMaterial($lesson, LessonMaterial::KIND_VIDEO);
        $this->createGrant($user, $lesson);
        $this->authenticate($user);
        $this->createBrowserCredential($user, 'risk_locked', true);
        $this->forbidProtectedGrantDependencies();

        $this->postJson($this->playbackUrl($subject, $course, $lesson), [
            'materialId' => $video->id,
        ])->assertForbidden()->assertExactJson($this->trustedBrowserDenial(
            'TRUSTED_BROWSER_RISK_LOCKED',
            'Доступ временно ограничен. Требуется дополнительная проверка.',
        ));
    }

    public function test_off_and_observe_allow_unknown_browser_and_observe_is_audited(): void
    {
        $user = User::factory()->create(['role' => 'user_no_purchase']);
        [$subject, $course, $lesson] = $this->createLesson();
        $video = $this->createMaterial($lesson, LessonMaterial::KIND_VIDEO);
        $this->createGrant($user, $lesson);
        $this->authenticate($user);
        $this->allowVideoPlayback(2);

        config()->set('trusted_browser.mode', 'off');
        $this->postJson($this->playbackUrl($subject, $course, $lesson), [
            'materialId' => $video->id,
        ])->assertOk();
        $this->assertDatabaseCount('trusted_browser_audit_events', 0);

        config()->set('trusted_browser.mode', 'observe');
        $this->postJson($this->playbackUrl($subject, $course, $lesson), [
            'materialId' => $video->id,
        ])->assertOk();

        $this->assertDatabaseHas('trusted_browser_audit_events', [
            'event_type' => 'access.observed',
            'mode' => 'observe',
            'state' => 'unknown_can_confirm',
        ]);
    }

    public function test_trusted_browser_is_allowed_for_video_and_file_grants_in_enforce_mode(): void
    {
        Queue::fake();
        $user = User::factory()->create(['role' => 'user_no_purchase']);
        [$subject, $course, $lesson] = $this->createLesson();
        $video = $this->createMaterial($lesson, LessonMaterial::KIND_VIDEO);
        $pdf = $this->createMaterial($lesson, LessonMaterial::KIND_PDF);
        $this->createSource($pdf);
        $this->createGrant($user, $lesson);
        $sessionId = $this->authenticate($user);
        $credential = $this->createBrowserCredential($user, 'active', true);
        TrustedBrowserSessionLink::query()->create([
            'account_id' => $credential->slot->account_id,
            'slot_id' => $credential->slot_id,
            'credential_id' => $credential->id,
            'session_id' => $sessionId,
            'bound_at' => now(),
            'last_seen_at' => now(),
        ]);
        $this->allowVideoPlayback();

        $playbackResponse = $this->postJson($this->playbackUrl($subject, $course, $lesson), [
            'materialId' => $video->id,
        ]);
        $playbackResponse->assertOk()->assertJsonPath(
            'data.embedUrl',
            'https://kinescope.io/embed/allowed?drmauthtoken=test',
        );
        $this->postJson($this->deliveriesUrl($subject, $course, $lesson), [
            'materialId' => $pdf->id,
        ])->assertAccepted()->assertJsonPath('data.status', ProtectedFileDelivery::STATUS_PENDING);

        $this->assertDatabaseHas('trusted_browser_session_links', [
            'session_id' => $sessionId,
            'credential_id' => $credential->id,
            'revoked_at' => null,
        ]);
        Queue::assertPushed(GenerateProtectedFileDeliveryJob::class, 1);
    }

    public function test_enforce_does_not_gate_video_index_or_position_updates(): void
    {
        $user = User::factory()->create(['role' => 'user_no_purchase']);
        [$subject, $course, $lesson] = $this->createLesson();
        $video = $this->createMaterial($lesson, LessonMaterial::KIND_VIDEO);
        $this->createGrant($user, $lesson);
        $this->authenticate($user);

        $this->getJson($this->videoMaterialsUrl($subject, $course, $lesson))
            ->assertOk()
            ->assertJsonPath('data.videos.0.materialId', $video->id);
        $this->postJson($this->positionUrl($subject, $course, $lesson), [
            'event' => 'pause',
            'materialId' => $video->id,
            'positionSeconds' => 12.5,
        ])->assertOk()->assertJsonPath('data.resumePositionSeconds', 12.5);

        $this->assertDatabaseCount('trusted_browser_accounts', 0);
    }

    private function authenticate(User $user): string
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user);
        $this->actingAs($user, 'web');
        $session = $this->app['session']->driver();
        $session->setId(Str::random(40));
        $session->start();
        $session->put('_token', self::CSRF_TOKEN);
        $session->save();
        $sessionId = $session->getId();

        $this->withCredentials();
        $this->withCookie((string) config('session.cookie'), $sessionId);
        $this->withHeader('Origin', 'http://localhost');
        $this->withHeader('X-CSRF-TOKEN', self::CSRF_TOKEN);

        return $sessionId;
    }

    private function createBrowserCredential(
        User $user,
        string $status,
        bool $attachCookie = false,
        int $slotNumber = 1,
    ): TrustedBrowserCredential {
        $account = TrustedBrowserAccount::query()->firstOrCreate(
            ['user_id' => $user->id],
            ['cookie_key' => bin2hex(random_bytes(16))],
        );
        $credentialData = $this->app->make(TrustedBrowserCookie::class)->createCredential();
        $slot = TrustedBrowserSlot::query()->create([
            'account_id' => $account->id,
            'slot_number' => $slotNumber,
            'status' => $status,
            'current_generation' => 1,
            'locked_at' => $status === 'active' ? null : now(),
        ]);
        $credential = TrustedBrowserCredential::query()->create([
            'slot_id' => $slot->id,
            'generation' => 1,
            'selector' => $credentialData['selector'],
            'secret_hash' => $credentialData['secret_hash'],
            'expires_at' => now()->addYear(),
            'last_refreshed_at' => now(),
        ]);

        if ($attachCookie) {
            $this->withUnencryptedCookie(
                'cms-tb-v1-'.$account->cookie_key,
                $credentialData['value'],
            );
        }

        return $credential;
    }

    private function createOccupiedBrowserSlots(User $user): void
    {
        foreach (range(1, 3) as $slotNumber) {
            $this->createBrowserCredential($user, 'active', false, $slotNumber);
        }
    }

    private function forbidProtectedGrantDependencies(): void
    {
        $playback = Mockery::mock(KinescopeVideoPlaybackService::class);
        $playback->shouldNotReceive('issue');
        $this->app->instance(KinescopeVideoPlaybackService::class, $playback);

        $delivery = Mockery::mock(ProtectedFileDeliveryService::class);
        $delivery->shouldNotReceive('request');
        $this->app->instance(ProtectedFileDeliveryService::class, $delivery);

        $issuer = Mockery::mock(TemporaryDownloadUrlIssuer::class);
        $issuer->shouldNotReceive('issue');
        $this->app->instance(TemporaryDownloadUrlIssuer::class, $issuer);
    }

    private function allowVideoPlayback(int $times = 1): void
    {
        $playback = Mockery::mock(KinescopeVideoPlaybackService::class);
        $playback->shouldReceive('issue')
            ->times($times)
            ->andReturn(new KinescopePlaybackData(
                'https://kinescope.io/embed/allowed?drmauthtoken=test',
                CarbonImmutable::now()->addMinutes(5),
            ));
        $this->app->instance(KinescopeVideoPlaybackService::class, $playback);
    }

    /** @return array{Subject, Course, Lesson} */
    private function createLesson(): array
    {
        $subject = Subject::query()->create([
            'slug' => 'trusted-subject',
            'title' => 'Доверенный предмет',
        ]);
        $course = Course::query()->create([
            'subject_id' => $subject->id,
            'slug' => 'trusted-course',
            'title' => 'Доверенный курс',
            'price_cents' => 100000,
        ]);
        $lesson = Lesson::query()->create([
            'course_id' => $course->id,
            'slug' => 'trusted-lesson',
            'title' => 'Доверенный урок',
        ]);

        return [$subject, $course, $lesson];
    }

    private function createMaterial(Lesson $lesson, string $kind): LessonMaterial
    {
        return LessonMaterial::query()->create([
            'lesson_id' => $lesson->id,
            'kind' => $kind,
            'title' => $kind === LessonMaterial::KIND_VIDEO ? 'Видеолекция' : 'Конспект PDF',
            'is_published' => true,
        ]);
    }

    private function createSource(LessonMaterial $material): LessonMaterialFile
    {
        return LessonMaterialFile::query()->create([
            'lesson_material_id' => $material->id,
            'source_object_key' => "protected-sources/{$material->id}/notes.pdf",
            'source_sha256' => str_repeat('a', 64),
            'source_size_bytes' => 1024,
            'source_mime_type' => 'application/pdf',
            'download_filename' => 'notes.pdf',
        ]);
    }

    private function createDelivery(
        User $user,
        LessonMaterialFile $source,
        string $status,
    ): ProtectedFileDelivery {
        return ProtectedFileDelivery::query()->create([
            'public_id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'lesson_material_file_id' => $source->id,
            'source_sha256' => $source->source_sha256,
            'copy_code' => 'COPY-'.strtoupper(Str::random(16)),
            'status' => $status,
            'output_object_key' => 'protected-copies/ready.pdf',
            'output_sha256' => str_repeat('b', 64),
            'output_size_bytes' => 2048,
            'ready_at' => now(),
        ]);
    }

    private function createGrant(User $user, Lesson $lesson): UserEntitlementGrant
    {
        return UserEntitlementGrant::query()->create([
            'user_id' => $user->id,
            'atom_kind' => UserEntitlementGrant::ATOM_LESSON,
            'atom_id' => $lesson->id,
            'status' => UserEntitlementGrant::STATUS_ACTIVE,
            'source_type' => UserEntitlementGrant::SOURCE_TEST_FIXTURE,
            'source_id' => "trusted-browser-lesson-{$lesson->id}",
            'idempotency_key' => "trusted-browser-{$user->id}-{$lesson->id}",
            'granted_at' => now(),
        ]);
    }

    private function playbackUrl(Subject $subject, Course $course, Lesson $lesson): string
    {
        return "/api/v1/learning/subjects/{$subject->slug}/courses/{$course->slug}/lessons/{$lesson->slug}/video-playback";
    }

    private function videoMaterialsUrl(Subject $subject, Course $course, Lesson $lesson): string
    {
        return "/api/v1/learning/subjects/{$subject->slug}/courses/{$course->slug}/lessons/{$lesson->slug}/video-materials";
    }

    private function positionUrl(Subject $subject, Course $course, Lesson $lesson): string
    {
        return "/api/v1/learning/subjects/{$subject->slug}/courses/{$course->slug}/lessons/{$lesson->slug}/video-position";
    }

    private function deliveriesUrl(Subject $subject, Course $course, Lesson $lesson): string
    {
        return "/api/v1/learning/subjects/{$subject->slug}/courses/{$course->slug}/lessons/{$lesson->slug}/file-deliveries";
    }

    /** @return array{error: array{code: string, message: string}} */
    private function accessDenied(): array
    {
        return ['error' => [
            'code' => 'LEARNING_ACCESS_DENIED',
            'message' => 'У пользователя нет доступа к этому уроку.',
        ]];
    }

    /** @return array{error: array{code: string, message: string}} */
    private function trustedBrowserDenial(string $code, string $message): array
    {
        return ['error' => [
            'code' => $code,
            'message' => $message,
        ]];
    }
}
