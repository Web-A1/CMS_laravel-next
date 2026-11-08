<?php

namespace Tests\Feature;

use App\Contracts\Kinescope\KinescopePlaybackToken;
use App\Exceptions\KinescopePlaybackUnavailableException;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\LessonMaterial;
use App\Models\LessonMaterialKinescopeVideo;
use App\Models\LessonVideoPlaybackPosition;
use App\Models\Subject;
use App\Models\User;
use App\Models\UserEntitlementGrant;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class KinescopeVideoPlaybackApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        config()->set('services.kinescope', [
            'enabled' => true,
            'embed_base_url' => 'https://kinescope.io/embed',
            'playback_secret' => str_repeat('k', 64),
            'playback_issuer' => 'cms-laravel-next',
            'playback_audience' => 'kinescope-player',
            'playback_ttl_seconds' => 300,
            'callback_username' => 'kinescope-callback',
            'callback_password' => 'callback-password',
        ]);

        $this->withHeader('Referer', 'http://localhost:3000/');
        $this->withSession(['auth_marker' => 'default']);
    }

    public function test_unauthenticated_user_cannot_list_video_materials_or_request_playback(): void
    {
        [$subject, $course, $lesson] = $this->createLesson();

        $this->getJson($this->videoMaterialsUrl($subject, $course, $lesson))
            ->assertUnauthorized();
        $this->postJson($this->playbackUrl($subject, $course, $lesson), ['materialId' => 1])
            ->assertUnauthorized();
        $this->postJson($this->positionUrl($subject, $course, $lesson), [
            'event' => 'pause',
            'materialId' => 1,
            'positionSeconds' => 10,
        ])->assertUnauthorized();
    }

    public function test_entitlement_is_denied_before_material_or_locator_queries_and_signer_call(): void
    {
        $user = User::factory()->create(['role' => 'user_no_purchase']);
        [$subject, $course, $lesson] = $this->createLesson();
        $material = $this->createMaterial($lesson, LessonMaterial::KIND_VIDEO, true);
        $this->createLocator($material, 'closed-video-id');
        Sanctum::actingAs($user);

        $signer = Mockery::mock(KinescopePlaybackToken::class);
        $signer->shouldNotReceive('issue');
        $this->app->instance(KinescopePlaybackToken::class, $signer);

        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->getJson($this->videoMaterialsUrl($subject, $course, $lesson))
            ->assertForbidden()
            ->assertExactJson($this->accessDeniedPayload());
        $this->postJson($this->playbackUrl($subject, $course, $lesson), ['materialId' => $material->id])
            ->assertForbidden()
            ->assertExactJson($this->accessDeniedPayload());

        $this->assertFalse(collect($queries)->contains(
            fn (string $query): bool => str_contains($query, 'lesson_materials')
                || str_contains($query, 'lesson_material_kinescope_videos')
        ));
    }

    public function test_entitled_user_lists_only_published_video_selection_without_locator(): void
    {
        $user = User::factory()->create(['role' => 'user_no_purchase']);
        [$subject, $course, $lesson] = $this->createLesson();
        $video = $this->createMaterial($lesson, LessonMaterial::KIND_VIDEO, true, 'Видеолекция');
        $this->createLocator($video, 'private-video-sentinel');
        $this->createMaterial($lesson, LessonMaterial::KIND_VIDEO, false, 'Черновик видео');
        $this->createMaterial($lesson, LessonMaterial::KIND_PDF, true, 'Конспект PDF');
        $this->createGrant($user, $lesson);
        Sanctum::actingAs($user);

        $response = $this->getJson($this->videoMaterialsUrl($subject, $course, $lesson))
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertExactJson([
                'data' => [
                    'videos' => [[
                        'materialId' => $video->id,
                        'resumePositionSeconds' => null,
                        'title' => 'Видеолекция',
                    ]],
                ],
            ]);

        $payload = $response->getContent() ?: '';
        foreach (['private-video-sentinel', 'providerId', 'embedUrl', 'token', 'locator'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $payload);
        }
    }

    public function test_free_preview_user_lists_video_and_receives_playback_without_grant(): void
    {
        $this->travelTo(now()->startOfSecond());
        $user = User::factory()->create(['role' => 'user_no_purchase']);
        [$subject, $course, $lesson] = $this->createLesson();
        $lesson->forceFill(['is_free_preview' => true])->save();
        $video = $this->createMaterial($lesson, LessonMaterial::KIND_VIDEO, true, 'Бесплатная видеолекция');
        $this->createLocator($video, 'free-player-123', 'free-video-123');
        Sanctum::actingAs($user);

        $this->getJson($this->videoMaterialsUrl($subject, $course, $lesson))
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'videos' => [[
                        'materialId' => $video->id,
                        'resumePositionSeconds' => null,
                        'title' => 'Бесплатная видеолекция',
                    ]],
                ],
            ]);

        $response = $this->postJson(
            $this->playbackUrl($subject, $course, $lesson),
            ['materialId' => $video->id],
        )->assertOk();

        $this->assertSame(now()->addMinutes(5)->toJSON(), $response->json('data.expiresAt'));
        $this->assertStringContainsString('https://kinescope.io/embed/free-player-123', (string) $response->json('data.embedUrl'));
        $this->assertStringNotContainsString('playback_secret', $response->getContent() ?: '');
    }

    public function test_paid_playback_creates_and_reuses_watermark_for_current_auth_session(): void
    {
        $user = User::factory()->create(['role' => 'user_no_purchase']);
        [$subject, $course, $lesson] = $this->createLesson();
        $first = $this->createMaterial($lesson, LessonMaterial::KIND_VIDEO, true, 'Видео 1');
        $second = $this->createMaterial($lesson, LessonMaterial::KIND_VIDEO, true, 'Видео 2');
        $this->createLocator($first, 'player-one', 'video-one');
        $this->createLocator($second, 'player-two', 'video-two');
        $this->createGrant($user, $lesson);
        Sanctum::actingAs($user);

        $responseOne = $this->withSession(['auth_marker' => 'one'])
            ->postJson($this->playbackUrl($subject, $course, $lesson), ['materialId' => $first->id])
            ->assertOk();
        $responseTwo = $this->postJson($this->playbackUrl($subject, $course, $lesson), ['materialId' => $second->id])
            ->assertOk();

        parse_str((string) parse_url((string) $responseOne->json('data.embedUrl'), PHP_URL_QUERY), $queryOne);
        parse_str((string) parse_url((string) $responseTwo->json('data.embedUrl'), PHP_URL_QUERY), $queryTwo);

        $this->assertSame(['drmauthtoken', 'watermark'], array_keys($queryOne));
        $this->assertSame($queryOne['watermark'], $queryTwo['watermark']);
        $this->assertMatchesRegularExpression('/^CMS-[2-9A-HJKMNP-Z]{4}-[2-9A-HJKMNP-Z]{4}$/', $queryOne['watermark']);
        $this->assertDatabaseCount('video_watermark_sessions', 1);
    }

    public function test_free_preview_playback_does_not_create_or_send_watermark(): void
    {
        $user = User::factory()->create(['role' => 'user_no_purchase']);
        [$subject, $course, $lesson] = $this->createLesson();
        $lesson->forceFill(['is_free_preview' => true])->save();
        $video = $this->createMaterial($lesson, LessonMaterial::KIND_VIDEO, true);
        $this->createLocator($video, 'free-player', 'free-video');
        Sanctum::actingAs($user);

        $response = $this->withSession(['auth_marker' => 'free'])
            ->postJson($this->playbackUrl($subject, $course, $lesson), ['materialId' => $video->id])
            ->assertOk();

        parse_str((string) parse_url((string) $response->json('data.embedUrl'), PHP_URL_QUERY), $query);
        $this->assertSame(['drmauthtoken'], array_keys($query));
        $this->assertDatabaseCount('video_watermark_sessions', 0);
    }

    public function test_different_laravel_auth_sessions_for_the_same_user_get_different_watermark_codes(): void
    {
        $user = User::factory()->create(['role' => 'user_no_purchase']);
        [$subject, $course, $lesson] = $this->createLesson();
        $first = $this->createMaterial($lesson, LessonMaterial::KIND_VIDEO, true, 'Видео 1');
        $second = $this->createMaterial($lesson, LessonMaterial::KIND_VIDEO, true, 'Видео 2');
        $this->createLocator($first, 'player-one', 'video-one');
        $this->createLocator($second, 'player-two', 'video-two');
        $this->createGrant($user, $lesson);
        Sanctum::actingAs($user);

        $firstResponse = $this->withSession(['auth_marker' => 'one'])
            ->postJson($this->playbackUrl($subject, $course, $lesson), ['materialId' => $first->id])
            ->assertOk();

        $this->flushSession();

        $secondResponse = $this->withSession(['auth_marker' => 'two'])
            ->postJson($this->playbackUrl($subject, $course, $lesson), ['materialId' => $second->id])
            ->assertOk();

        parse_str((string) parse_url((string) $firstResponse->json('data.embedUrl'), PHP_URL_QUERY), $firstQuery);
        parse_str((string) parse_url((string) $secondResponse->json('data.embedUrl'), PHP_URL_QUERY), $secondQuery);

        $this->assertNotSame($firstQuery['watermark'], $secondQuery['watermark']);
        $this->assertDatabaseCount('video_watermark_sessions', 2);
    }

    public function test_pause_saves_video_resume_position_and_lists_it_for_same_user(): void
    {
        $user = User::factory()->create(['role' => 'user_no_purchase']);
        [$subject, $course, $lesson] = $this->createLesson();
        $video = $this->createMaterial($lesson, LessonMaterial::KIND_VIDEO, true, 'Видеолекция');
        $this->createGrant($user, $lesson);
        Sanctum::actingAs($user);

        $this->postJson($this->positionUrl($subject, $course, $lesson), [
            'durationSeconds' => 720.25,
            'event' => 'pause',
            'materialId' => $video->id,
            'positionSeconds' => 126.789,
        ])
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'resumePositionSeconds' => 126.789,
                ],
            ]);

        $this->assertDatabaseHas('lesson_video_playback_positions', [
            'duration_milliseconds' => 720250,
            'lesson_material_id' => $video->id,
            'position_milliseconds' => 126789,
            'user_id' => $user->id,
        ]);

        $this->getJson($this->videoMaterialsUrl($subject, $course, $lesson))
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'videos' => [[
                        'materialId' => $video->id,
                        'resumePositionSeconds' => 126.789,
                        'title' => 'Видеолекция',
                    ]],
                ],
            ]);
    }

    public function test_video_resume_position_is_scoped_to_authenticated_user(): void
    {
        $userWithPosition = User::factory()->create(['role' => 'user_no_purchase']);
        $currentUser = User::factory()->create(['role' => 'user_no_purchase']);
        [$subject, $course, $lesson] = $this->createLesson();
        $video = $this->createMaterial($lesson, LessonMaterial::KIND_VIDEO, true, 'Видеолекция');
        $this->createGrant($userWithPosition, $lesson);
        $this->createGrant($currentUser, $lesson);
        LessonVideoPlaybackPosition::query()->create([
            'duration_milliseconds' => 720000,
            'lesson_material_id' => $video->id,
            'position_milliseconds' => 126000,
            'user_id' => $userWithPosition->id,
        ]);
        Sanctum::actingAs($currentUser);

        $this->getJson($this->videoMaterialsUrl($subject, $course, $lesson))
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'videos' => [[
                        'materialId' => $video->id,
                        'resumePositionSeconds' => null,
                        'title' => 'Видеолекция',
                    ]],
                ],
            ]);
    }

    public function test_ended_event_resets_saved_video_resume_position(): void
    {
        $user = User::factory()->create(['role' => 'user_no_purchase']);
        [$subject, $course, $lesson] = $this->createLesson();
        $video = $this->createMaterial($lesson, LessonMaterial::KIND_VIDEO, true, 'Видеолекция');
        $this->createGrant($user, $lesson);
        LessonVideoPlaybackPosition::query()->create([
            'duration_milliseconds' => 720000,
            'lesson_material_id' => $video->id,
            'position_milliseconds' => 126000,
            'user_id' => $user->id,
        ]);
        Sanctum::actingAs($user);

        $this->postJson($this->positionUrl($subject, $course, $lesson), [
            'durationSeconds' => 720,
            'event' => 'ended',
            'materialId' => $video->id,
            'positionSeconds' => 719.9,
        ])
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'resumePositionSeconds' => null,
                ],
            ]);

        $this->assertDatabaseMissing('lesson_video_playback_positions', [
            'lesson_material_id' => $video->id,
            'user_id' => $user->id,
        ]);
    }

    public function test_video_position_save_reuses_playback_access_and_material_guards(): void
    {
        $user = User::factory()->create(['role' => 'user_no_purchase']);
        [$subject, $course, $lesson] = $this->createLesson();
        [, , $otherLesson] = $this->createLesson('physiology', 'physiology-course', 'heart');
        $foreign = $this->createMaterial($otherLesson, LessonMaterial::KIND_VIDEO, true, 'Чужое видео');
        $unpublished = $this->createMaterial($lesson, LessonMaterial::KIND_VIDEO, false, 'Черновик');
        $nonVideo = $this->createMaterial($lesson, LessonMaterial::KIND_PDF, true, 'PDF');
        Sanctum::actingAs($user);

        $this->postJson($this->positionUrl($subject, $course, $lesson), [
            'event' => 'pause',
            'materialId' => $foreign->id,
            'positionSeconds' => 10,
        ])
            ->assertForbidden()
            ->assertExactJson($this->accessDeniedPayload());

        $this->createGrant($user, $lesson);

        foreach ([$foreign->id, $unpublished->id, $nonVideo->id, 999999] as $materialId) {
            $this->postJson($this->positionUrl($subject, $course, $lesson), [
                'event' => 'pause',
                'materialId' => $materialId,
                'positionSeconds' => 10,
            ])
                ->assertNotFound()
                ->assertExactJson($this->videoNotFoundPayload());
        }

        $this->assertDatabaseCount('lesson_video_playback_positions', 0);
    }

    public function test_mismatched_subject_course_lesson_chain_is_not_found_before_signer_call(): void
    {
        $user = User::factory()->create(['role' => 'user_no_purchase']);
        [$subject, , $lesson] = $this->createLesson();
        [, $otherCourse, $otherLesson] = $this->createLesson('physiology', 'physiology-course', 'heart');
        $material = $this->createMaterial($otherLesson, LessonMaterial::KIND_VIDEO, true);
        $this->createLocator($material, 'foreign-chain-video');
        $this->createGrant($user, $lesson);
        Sanctum::actingAs($user);

        $signer = Mockery::mock(KinescopePlaybackToken::class);
        $signer->shouldNotReceive('issue');
        $this->app->instance(KinescopePlaybackToken::class, $signer);

        $this->postJson(
            "/api/v1/learning/subjects/{$subject->slug}/courses/{$otherCourse->slug}/lessons/{$otherLesson->slug}/video-playback",
            ['materialId' => $material->id],
        )->assertNotFound()
            ->assertExactJson([
                'error' => [
                    'code' => 'LEARNING_LESSON_NOT_FOUND',
                    'message' => 'Урок не найден.',
                ],
            ]);
    }

    public function test_entitled_user_receives_exact_short_lived_playback_shape(): void
    {
        Http::fake();
        $this->travelTo(now()->startOfSecond());
        $user = User::factory()->create(['role' => 'user_no_purchase']);
        [$subject, $course, $lesson] = $this->createLesson();
        $material = $this->createMaterial($lesson, LessonMaterial::KIND_VIDEO, true);
        $this->createLocator($material, 'player-123', 'canonical-video-123');
        $this->createGrant($user, $lesson);
        Sanctum::actingAs($user);

        $response = $this->postJson(
            $this->playbackUrl($subject, $course, $lesson),
            ['materialId' => $material->id],
        )->assertOk();

        $this->assertSame(['data'], array_keys($response->json()));
        $this->assertSame(['embedUrl', 'expiresAt'], array_keys($response->json('data')));
        $this->assertSame(now()->addMinutes(5)->toJSON(), $response->json('data.expiresAt'));

        $embedUrl = (string) $response->json('data.embedUrl');
        $this->assertSame('https', parse_url($embedUrl, PHP_URL_SCHEME));
        $this->assertSame('kinescope.io', parse_url($embedUrl, PHP_URL_HOST));
        $this->assertSame('/embed/player-123', parse_url($embedUrl, PHP_URL_PATH));

        parse_str((string) parse_url($embedUrl, PHP_URL_QUERY), $query);
        $this->assertSame(['drmauthtoken', 'watermark'], array_keys($query));
        $this->assertIsString($query['drmauthtoken']);
        $this->assertNotSame('', $query['drmauthtoken']);
        $this->assertMatchesRegularExpression('/^CMS-[2-9A-HJKMNP-Z]{4}-[2-9A-HJKMNP-Z]{4}$/', $query['watermark']);

        $tokenParts = explode('.', $query['drmauthtoken']);
        $this->assertCount(3, $tokenParts);
        $tokenPayload = json_decode($this->base64UrlDecode($tokenParts[1]), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('canonical-video-123', $tokenPayload['vid'] ?? null);
        $this->assertIsString($tokenPayload['wmid'] ?? null);
        $this->assertStringNotContainsString('player-123', $query['drmauthtoken']);

        $payload = $response->getContent() ?: '';
        foreach (['playback_secret', 'callback-password', 'apiToken', 'providerMetadata'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $payload);
        }
        Http::assertNothingSent();
    }

    public function test_invalid_material_id_shape_returns_exact_422_without_locator_query(): void
    {
        $user = User::factory()->create(['role' => 'user_no_purchase']);
        [$subject, $course, $lesson] = $this->createLesson();
        $this->createGrant($user, $lesson);
        Sanctum::actingAs($user);

        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->postJson($this->playbackUrl($subject, $course, $lesson), ['materialId' => 'not-an-id'])
            ->assertUnprocessable()
            ->assertExactJson([
                'error' => [
                    'code' => 'LEARNING_VIDEO_REQUEST_INVALID',
                    'message' => 'Не удалось определить видеоматериал.',
                ],
            ]);

        $this->assertFalse(collect($queries)->contains(
            fn (string $query): bool => str_contains($query, 'lesson_material_kinescope_videos')
        ));
    }

    public function test_foreign_unpublished_non_video_and_missing_materials_share_not_found_contract(): void
    {
        $user = User::factory()->create(['role' => 'user_no_purchase']);
        [$subject, $course, $lesson] = $this->createLesson();
        [, , $otherLesson] = $this->createLesson('physiology', 'physiology-course', 'heart');
        $foreign = $this->createMaterial($otherLesson, LessonMaterial::KIND_VIDEO, true, 'Чужое видео');
        $unpublished = $this->createMaterial($lesson, LessonMaterial::KIND_VIDEO, false, 'Черновик');
        $nonVideo = $this->createMaterial($lesson, LessonMaterial::KIND_PDF, true, 'PDF');
        $this->createGrant($user, $lesson);
        Sanctum::actingAs($user);

        foreach ([$foreign->id, $unpublished->id, $nonVideo->id, 999999] as $materialId) {
            $this->postJson($this->playbackUrl($subject, $course, $lesson), ['materialId' => $materialId])
                ->assertNotFound()
                ->assertExactJson($this->videoNotFoundPayload());
        }
    }

    public function test_missing_locator_returns_exact_service_unavailable_response(): void
    {
        $user = User::factory()->create(['role' => 'user_no_purchase']);
        [$subject, $course, $lesson] = $this->createLesson();
        $material = $this->createMaterial($lesson, LessonMaterial::KIND_VIDEO, true);
        $this->createGrant($user, $lesson);
        Sanctum::actingAs($user);

        $this->postJson($this->playbackUrl($subject, $course, $lesson), ['materialId' => $material->id])
            ->assertServiceUnavailable()
            ->assertExactJson($this->videoUnavailablePayload());
    }

    public function test_disabled_or_invalid_provider_configuration_fails_closed(): void
    {
        $user = User::factory()->create(['role' => 'user_no_purchase']);
        [$subject, $course, $lesson] = $this->createLesson();
        $material = $this->createMaterial($lesson, LessonMaterial::KIND_VIDEO, true);
        $this->createLocator($material, 'video-disabled');
        $this->createGrant($user, $lesson);
        Sanctum::actingAs($user);

        foreach ([
            ['services.kinescope.enabled' => false],
            ['services.kinescope.playback_secret' => 'too-short'],
            ['services.kinescope.embed_base_url' => 'http://untrusted.invalid/embed'],
        ] as $override) {
            foreach ($override as $key => $value) {
                config()->set($key, $value);
            }

            $this->postJson($this->playbackUrl($subject, $course, $lesson), ['materialId' => $material->id])
                ->assertServiceUnavailable()
                ->assertExactJson($this->providerNotConfiguredPayload());

            $this->setUpKinescopeConfig();
        }
    }

    public function test_incomplete_locator_pair_fails_closed(): void
    {
        $user = User::factory()->create(['role' => 'user_no_purchase']);
        [$subject, $course, $lesson] = $this->createLesson();
        $material = $this->createMaterial($lesson, LessonMaterial::KIND_VIDEO, true);
        $this->createLocator($material, 'player-without-provider-id', null);
        $this->createGrant($user, $lesson);
        Sanctum::actingAs($user);

        $this->postJson($this->playbackUrl($subject, $course, $lesson), ['materialId' => $material->id])
            ->assertServiceUnavailable()
            ->assertExactJson($this->videoUnavailablePayload());
    }

    public function test_signer_failure_is_mapped_to_service_unavailable_without_internal_details(): void
    {
        $user = User::factory()->create(['role' => 'user_no_purchase']);
        [$subject, $course, $lesson] = $this->createLesson();
        $material = $this->createMaterial($lesson, LessonMaterial::KIND_VIDEO, true);
        $this->createLocator($material, 'video-signer-failure');
        $this->createGrant($user, $lesson);
        Sanctum::actingAs($user);

        $signer = Mockery::mock(KinescopePlaybackToken::class);
        $signer->shouldReceive('issue')
            ->once()
            ->andThrow(new KinescopePlaybackUnavailableException('secret sentinel'));
        $this->app->instance(KinescopePlaybackToken::class, $signer);

        $response = $this->postJson(
            $this->playbackUrl($subject, $course, $lesson),
            ['materialId' => $material->id],
        )
            ->assertServiceUnavailable()
            ->assertExactJson($this->videoUnavailablePayload());

        $this->assertStringNotContainsString('secret sentinel', $response->getContent() ?: '');
    }

    /**
     * @return array{Subject, Course, Lesson}
     */
    private function createLesson(
        string $subjectSlug = 'anatomy',
        string $courseSlug = 'osteology',
        string $lessonSlug = 'bones',
    ): array {
        $subject = Subject::query()->create([
            'slug' => $subjectSlug,
            'title' => "Предмет {$subjectSlug}",
        ]);
        $course = Course::query()->create([
            'subject_id' => $subject->id,
            'slug' => $courseSlug,
            'title' => "Курс {$courseSlug}",
            'price_cents' => 100000,
        ]);
        $lesson = Lesson::query()->create([
            'course_id' => $course->id,
            'slug' => $lessonSlug,
            'title' => "Урок {$lessonSlug}",
        ]);

        return [$subject, $course, $lesson];
    }

    private function createMaterial(
        Lesson $lesson,
        string $kind,
        bool $published,
        string $title = 'Видеолекция',
    ): LessonMaterial {
        return LessonMaterial::query()->create([
            'lesson_id' => $lesson->id,
            'kind' => $kind,
            'title' => $title,
            'is_published' => $published,
        ]);
    }

    private function createLocator(
        LessonMaterial $material,
        string $embedLocator,
        ?string $providerVideoId = '',
    ): LessonMaterialKinescopeVideo {
        return LessonMaterialKinescopeVideo::query()->create([
            'lesson_material_id' => $material->id,
            'embed_locator' => $embedLocator,
            'provider_video_id' => $providerVideoId === '' ? $embedLocator : $providerVideoId,
        ]);
    }

    private function base64UrlDecode(string $value): string
    {
        $remainder = strlen($value) % 4;

        return base64_decode(
            strtr($value, '-_', '+/').str_repeat('=', (4 - $remainder) % 4),
            true,
        ) ?: '';
    }

    private function createGrant(User $user, Lesson $lesson): UserEntitlementGrant
    {
        return UserEntitlementGrant::query()->create([
            'user_id' => $user->id,
            'atom_kind' => UserEntitlementGrant::ATOM_LESSON,
            'atom_id' => $lesson->id,
            'status' => UserEntitlementGrant::STATUS_ACTIVE,
            'source_type' => UserEntitlementGrant::SOURCE_TEST_FIXTURE,
            'source_id' => "kinescope-lesson-{$lesson->id}",
            'idempotency_key' => "kinescope-{$user->id}-{$lesson->id}",
            'granted_at' => now(),
        ]);
    }

    private function videoMaterialsUrl(Subject $subject, Course $course, Lesson $lesson): string
    {
        return "/api/v1/learning/subjects/{$subject->slug}/courses/{$course->slug}/lessons/{$lesson->slug}/video-materials";
    }

    private function playbackUrl(Subject $subject, Course $course, Lesson $lesson): string
    {
        return "/api/v1/learning/subjects/{$subject->slug}/courses/{$course->slug}/lessons/{$lesson->slug}/video-playback";
    }

    private function positionUrl(Subject $subject, Course $course, Lesson $lesson): string
    {
        return "/api/v1/learning/subjects/{$subject->slug}/courses/{$course->slug}/lessons/{$lesson->slug}/video-position";
    }

    /**
     * @return array{error: array{code: string, message: string}}
     */
    private function accessDeniedPayload(): array
    {
        return ['error' => [
            'code' => 'LEARNING_ACCESS_DENIED',
            'message' => 'У пользователя нет доступа к этому уроку.',
        ]];
    }

    /**
     * @return array{error: array{code: string, message: string}}
     */
    private function videoNotFoundPayload(): array
    {
        return ['error' => [
            'code' => 'LEARNING_VIDEO_NOT_FOUND',
            'message' => 'Видеоматериал не найден.',
        ]];
    }

    /**
     * @return array{error: array{code: string, message: string}}
     */
    private function videoUnavailablePayload(): array
    {
        return ['error' => [
            'code' => 'LEARNING_VIDEO_UNAVAILABLE',
            'message' => 'Видео временно недоступно.',
        ]];
    }

    /**
     * @return array{error: array{code: string, message: string}}
     */
    private function providerNotConfiguredPayload(): array
    {
        return ['error' => [
            'code' => 'LEARNING_VIDEO_PROVIDER_NOT_CONFIGURED',
            'message' => 'Видеосервис пока не настроен.',
        ]];
    }

    private function setUpKinescopeConfig(): void
    {
        config()->set('services.kinescope.enabled', true);
        config()->set('services.kinescope.embed_base_url', 'https://kinescope.io/embed');
        config()->set('services.kinescope.playback_secret', str_repeat('k', 64));
    }
}
