<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\KinescopeNotConfiguredException;
use App\Exceptions\KinescopePlaybackUnavailableException;
use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\LessonMaterial;
use App\Models\LessonVideoPlaybackPosition;
use App\Models\Subject;
use App\Services\AccessResolver;
use App\Services\Kinescope\KinescopeVideoPlaybackService;
use App\Services\TrustedBrowser\TrustedBrowserAccessService;
use App\Services\VideoWatermarks\VideoWatermarkSessionService;
use App\Support\ImpersonationContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LearningVideoController extends Controller
{
    public function index(
        Request $request,
        Subject $subject,
        Course $course,
        Lesson $lesson,
        AccessResolver $accessResolver,
    ): JsonResponse {
        if (ImpersonationContext::isActive($request)) {
            return ImpersonationContext::mediaDisabledResponse();
        }

        if ($this->lessonIsUnavailable($subject, $course, $lesson)) {
            return $this->lessonNotFound();
        }

        if (! $this->hasAccess($request, $lesson, $accessResolver)) {
            return $this->accessDenied();
        }

        $videoMaterials = $lesson->materials()
            ->published()
            ->where('kind', LessonMaterial::KIND_VIDEO)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'title']);

        $positions = LessonVideoPlaybackPosition::query()
            ->where('user_id', (int) $request->user()->id)
            ->whereIn('lesson_material_id', $videoMaterials->pluck('id'))
            ->pluck('position_milliseconds', 'lesson_material_id');

        $videos = $videoMaterials
            ->map(fn (LessonMaterial $material): array => [
                'materialId' => (int) $material->id,
                'title' => $material->title,
                'resumePositionSeconds' => $this->positionSeconds($positions->get($material->id)),
            ])
            ->all();

        return $this->privateResponse([
            'data' => [
                'videos' => $videos,
            ],
        ]);
    }

    public function savePosition(
        Request $request,
        Subject $subject,
        Course $course,
        Lesson $lesson,
        AccessResolver $accessResolver,
    ): JsonResponse {
        if (ImpersonationContext::isActive($request)) {
            return ImpersonationContext::mediaDisabledResponse();
        }

        $materialId = $request->input('materialId');
        $event = $request->input('event');
        $positionSeconds = $this->secondsInput($request->input('positionSeconds'));
        $durationSeconds = $this->optionalSecondsInput($request->input('durationSeconds'));

        if (
            ! is_int($materialId)
            || $materialId < 1
            || ! in_array($event, ['pause', 'ended'], true)
            || ($event === 'pause' && $positionSeconds === null)
            || $durationSeconds === false
        ) {
            return response()->json([
                'error' => [
                    'code' => 'LEARNING_VIDEO_POSITION_INVALID',
                    'message' => 'Не удалось сохранить позицию видео.',
                ],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($this->lessonIsUnavailable($subject, $course, $lesson)) {
            return $this->lessonNotFound();
        }

        if (! $this->hasAccess($request, $lesson, $accessResolver)) {
            return $this->accessDenied();
        }

        $material = $this->videoMaterial($lesson, $materialId);
        if (! $material) {
            return response()->json([
                'error' => [
                    'code' => 'LEARNING_VIDEO_NOT_FOUND',
                    'message' => 'Видеоматериал не найден.',
                ],
            ], Response::HTTP_NOT_FOUND);
        }

        if ($event === 'ended') {
            LessonVideoPlaybackPosition::query()
                ->where('user_id', (int) $request->user()->id)
                ->where('lesson_material_id', $material->id)
                ->delete();

            return $this->privateResponse([
                'data' => [
                    'resumePositionSeconds' => null,
                ],
            ]);
        }

        $positionMilliseconds = $this->secondsToMilliseconds($positionSeconds);
        $durationMilliseconds = $durationSeconds === null ? null : $this->secondsToMilliseconds($durationSeconds);

        LessonVideoPlaybackPosition::query()->updateOrCreate(
            [
                'user_id' => (int) $request->user()->id,
                'lesson_material_id' => (int) $material->id,
            ],
            [
                'position_milliseconds' => $positionMilliseconds,
                'duration_milliseconds' => $durationMilliseconds,
            ],
        );

        return $this->privateResponse([
            'data' => [
                'resumePositionSeconds' => $this->positionSeconds($positionMilliseconds),
            ],
        ]);
    }

    public function playback(
        Request $request,
        Subject $subject,
        Course $course,
        Lesson $lesson,
        AccessResolver $accessResolver,
        TrustedBrowserAccessService $trustedBrowserAccess,
        KinescopeVideoPlaybackService $playbackService,
        VideoWatermarkSessionService $watermarks,
    ): JsonResponse {
        if (ImpersonationContext::isActive($request)) {
            return ImpersonationContext::mediaDisabledResponse();
        }

        $materialId = $request->input('materialId');
        if (! is_int($materialId) || $materialId < 1) {
            return response()->json([
                'error' => [
                    'code' => 'LEARNING_VIDEO_REQUEST_INVALID',
                    'message' => 'Не удалось определить видеоматериал.',
                ],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($this->lessonIsUnavailable($subject, $course, $lesson)) {
            return $this->lessonNotFound();
        }

        if (! $this->hasAccess($request, $lesson, $accessResolver)) {
            return $this->accessDenied();
        }

        $trustedBrowserDenial = $this->trustedBrowserDenial($request, $trustedBrowserAccess);
        if ($trustedBrowserDenial) {
            return $trustedBrowserDenial;
        }

        $material = LessonMaterial::query()
            ->whereKey($materialId)
            ->where('lesson_id', $lesson->id)
            ->where('kind', LessonMaterial::KIND_VIDEO)
            ->published()
            ->first();

        if (! $material) {
            return response()->json([
                'error' => [
                    'code' => 'LEARNING_VIDEO_NOT_FOUND',
                    'message' => 'Видеоматериал не найден.',
                ],
            ], Response::HTTP_NOT_FOUND);
        }

        try {
            $watermarkSession = $lesson->is_free_preview
                ? null
                : $watermarks->currentForPlayback($request, $request->user());
            $playback = $playbackService->issue((int) $request->user()->id, $material, $watermarkSession);
        } catch (KinescopeNotConfiguredException) {
            return response()->json([
                'error' => [
                    'code' => 'LEARNING_VIDEO_PROVIDER_NOT_CONFIGURED',
                    'message' => 'Видеосервис пока не настроен.',
                ],
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        } catch (KinescopePlaybackUnavailableException) {
            return response()->json([
                'error' => [
                    'code' => 'LEARNING_VIDEO_UNAVAILABLE',
                    'message' => 'Видео временно недоступно.',
                ],
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return $this->privateResponse([
            'data' => [
                'embedUrl' => $playback->embedUrl,
                'expiresAt' => CarbonImmutable::instance($playback->expiresAt)->toJSON(),
            ],
        ]);
    }

    private function lessonIsUnavailable(Subject $subject, Course $course, Lesson $lesson): bool
    {
        return ! $subject->is_active || ! $course->is_active || ! $lesson->is_active;
    }

    private function hasAccess(Request $request, Lesson $lesson, AccessResolver $accessResolver): bool
    {
        $access = $accessResolver->check(
            $request->user(),
            AccessResolver::ENTITY_LESSON,
            (int) $lesson->id,
        );

        return $access['access'] === AccessResolver::ACCESS_FULL || (bool) $lesson->is_free_preview;
    }

    private function lessonNotFound(): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'LEARNING_LESSON_NOT_FOUND',
                'message' => 'Урок не найден.',
            ],
        ], Response::HTTP_NOT_FOUND);
    }

    private function accessDenied(): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'LEARNING_ACCESS_DENIED',
                'message' => 'У пользователя нет доступа к этому уроку.',
            ],
        ], Response::HTTP_FORBIDDEN);
    }

    private function trustedBrowserDenial(
        Request $request,
        TrustedBrowserAccessService $trustedBrowserAccess,
    ): ?JsonResponse {
        $decision = $trustedBrowserAccess->evaluate($request, $request->user());
        if (! $decision->shouldBlock()) {
            return null;
        }

        return $this->privateResponse([
            'error' => [
                'code' => $decision->code ?? 'TRUSTED_BROWSER_REQUIRED',
                'message' => $decision->message ?? 'Подтвердите этот браузер для доступа к защищённым материалам.',
            ],
        ], Response::HTTP_FORBIDDEN);
    }

    private function videoMaterial(Lesson $lesson, int $materialId): ?LessonMaterial
    {
        return LessonMaterial::query()
            ->whereKey($materialId)
            ->where('lesson_id', $lesson->id)
            ->where('kind', LessonMaterial::KIND_VIDEO)
            ->published()
            ->first();
    }

    private function secondsInput(mixed $value): ?float
    {
        if (! is_int($value) && ! is_float($value)) {
            return null;
        }

        $seconds = (float) $value;

        return is_finite($seconds) && $seconds >= 0 && $seconds <= 86400 ? $seconds : null;
    }

    private function optionalSecondsInput(mixed $value): float|null|false
    {
        if ($value === null) {
            return null;
        }

        return $this->secondsInput($value) ?? false;
    }

    private function secondsToMilliseconds(float $seconds): int
    {
        return max(0, (int) round($seconds * 1000));
    }

    private function positionSeconds(mixed $milliseconds): ?float
    {
        if (! is_int($milliseconds) || $milliseconds <= 0) {
            return null;
        }

        return round($milliseconds / 1000, 3);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function privateResponse(array $payload, int $status = Response::HTTP_OK): JsonResponse
    {
        return response()
            ->json($payload, $status)
            ->header('Cache-Control', 'private, no-store');
    }
}
