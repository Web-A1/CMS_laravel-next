<?php

namespace App\Http\Controllers\Api;

use App\Data\Kinescope\LegacyKinescopePlaybackClaims;
use App\Exceptions\InvalidKinescopePlaybackTokenException;
use App\Exceptions\KinescopePlaybackUnavailableException;
use App\Http\Controllers\Controller;
use App\Models\LessonMaterial;
use App\Models\VideoWatermarkSession;
use App\Services\AccessResolver;
use App\Services\Kinescope\KinescopePlaybackTokenRouter;
use App\Services\Kinescope\LegacyKinescopeVideoAllowlist;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class KinescopeAuthorizationController extends Controller
{
    public function __invoke(
        Request $request,
        KinescopePlaybackTokenRouter $playbackTokenRouter,
        LegacyKinescopeVideoAllowlist $legacyVideoAllowlist,
        AccessResolver $accessResolver,
    ): Response {
        if (! $this->configurationIsReady()) {
            return response('', HttpResponse::HTTP_SERVICE_UNAVAILABLE);
        }

        if (! $this->basicCredentialsAreValid($request)) {
            return response('', HttpResponse::HTTP_FORBIDDEN);
        }

        $payload = $request->json()->all();
        if (! $this->payloadIsValid($payload)) {
            return response('', HttpResponse::HTTP_BAD_REQUEST);
        }

        try {
            $claims = $playbackTokenRouter->verify($payload['token']);
        } catch (InvalidKinescopePlaybackTokenException) {
            return response('', HttpResponse::HTTP_FORBIDDEN);
        } catch (KinescopePlaybackUnavailableException) {
            return response('', HttpResponse::HTTP_SERVICE_UNAVAILABLE);
        }

        if ($claims instanceof LegacyKinescopePlaybackClaims) {
            try {
                $allowed = match ($claims->accessIntent) {
                    LegacyKinescopePlaybackClaims::INTENT_PROTECTED_PLAYBACK,
                    LegacyKinescopePlaybackClaims::INTENT_PUBLIC_PREVIEW => $legacyVideoAllowlist->allows(
                        $claims->embedLocator,
                        $payload['id'],
                    ),
                    default => false,
                };
            } catch (KinescopePlaybackUnavailableException) {
                return response('', HttpResponse::HTTP_SERVICE_UNAVAILABLE);
            }

            return response('', $allowed ? HttpResponse::HTTP_OK : HttpResponse::HTTP_FORBIDDEN);
        }

        if (! hash_equals($claims->videoId, $payload['id'])) {
            return response('', HttpResponse::HTTP_FORBIDDEN);
        }

        $material = LessonMaterial::query()
            ->whereKey($claims->materialId)
            ->where('kind', LessonMaterial::KIND_VIDEO)
            ->published()
            ->with(['kinescopeVideo', 'lesson.course.subject'])
            ->first();

        $lesson = $material?->lesson;
        $course = $lesson?->course;
        $subject = $course?->subject;
        $locator = $material?->kinescopeVideo;

        if (
            ! $material
            || ! $lesson
            || ! $course
            || ! $subject
            || ! $locator
            || ! $lesson->is_active
            || ! $course->is_active
            || ! $subject->is_active
            || ! is_string($locator->provider_video_id)
            || ! hash_equals($locator->provider_video_id, $claims->videoId)
        ) {
            return response('', HttpResponse::HTTP_FORBIDDEN);
        }

        $access = $accessResolver->check(
            $claims->userId,
            AccessResolver::ENTITY_LESSON,
            (int) $lesson->id,
        );

        $hasFullAccess = $access['access'] === AccessResolver::ACCESS_FULL;
        $isFreePreview = (bool) $lesson->is_free_preview;

        if (! $hasFullAccess && ! $isFreePreview) {
            return response('', HttpResponse::HTTP_FORBIDDEN);
        }

        if ($isFreePreview && $claims->watermarkSessionId !== null) {
            return response('', HttpResponse::HTTP_FORBIDDEN);
        }

        if (! $isFreePreview) {
            if ($claims->watermarkSessionId === null) {
                return response('', HttpResponse::HTTP_FORBIDDEN);
            }

            $watermark = VideoWatermarkSession::query()
                ->whereKey($claims->watermarkSessionId)
                ->where('user_id', $claims->userId)
                ->whereNull('ended_at')
                ->first();

            if (! $watermark) {
                return response('', HttpResponse::HTTP_FORBIDDEN);
            }
        }

        return response('', HttpResponse::HTTP_OK);
    }

    private function configurationIsReady(): bool
    {
        $username = config('services.kinescope.callback_username');
        $password = config('services.kinescope.callback_password');

        return config('services.kinescope.enabled') === true
            && is_string($username)
            && $username !== ''
            && is_string($password)
            && strlen($password) >= 16;
    }

    private function basicCredentialsAreValid(Request $request): bool
    {
        $username = (string) config('services.kinescope.callback_username');
        $password = (string) config('services.kinescope.callback_password');

        return is_string($request->getUser())
            && is_string($request->getPassword())
            && hash_equals($username, $request->getUser())
            && hash_equals($password, $request->getPassword());
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function payloadIsValid(array $payload): bool
    {
        return is_string($payload['id'] ?? null)
            && preg_match('/^[A-Za-z0-9_-]{1,255}$/', $payload['id']) === 1
            && ($payload['type'] ?? null) === 'vod'
            && is_string($payload['token'] ?? null)
            && $payload['token'] !== ''
            && strlen($payload['token']) <= 4096;
    }
}
