<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\LessonAssessment;
use App\Models\LessonAssessmentAsset;
use App\Models\Subject;
use App\Models\User;
use App\Services\AccessResolver;
use App\Services\Learning\LessonAssessmentStateSelector;
use App\Services\TrustedBrowser\TrustedBrowserAccessService;
use App\Support\ImpersonationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class LearningAssessmentAssetController extends Controller
{
    private const ALLOWED_MIME_TYPES = [
        'image/png',
        'image/webp',
    ];

    public function __invoke(
        Request $request,
        Subject $subject,
        Course $course,
        Lesson $lesson,
        string $asset,
        AccessResolver $accessResolver,
        LessonAssessmentStateSelector $stateSelector,
        TrustedBrowserAccessService $trustedBrowserAccess,
    ): Response {
        if ($this->lessonIsUnavailable($subject, $course, $lesson)) {
            return $this->lessonNotFound();
        }
        if (! $this->hasAccess($request, $lesson, $accessResolver)) {
            return $this->accessDenied();
        }

        $trustedBrowserDenial = $this->trustedBrowserDenial($request, $trustedBrowserAccess);
        if ($trustedBrowserDenial instanceof JsonResponse) {
            return $trustedBrowserDenial;
        }

        $assessment = LessonAssessment::query()->where('lesson_id', $lesson->id)->first();
        if (! $assessment instanceof LessonAssessment) {
            return $this->assetNotFound();
        }
        $state = $stateSelector->select($this->user($request), $assessment);
        if ($state === null || preg_match('/\A[a-z0-9][a-z0-9-]{1,63}\z/', $asset) !== 1) {
            return $this->assetNotFound();
        }
        $version = $state['version'];

        $record = LessonAssessmentAsset::query()
            ->where('lesson_assessment_version_id', $version->id)
            ->where('asset_key', $asset)
            ->first();
        if (! $record instanceof LessonAssessmentAsset || ! $this->hasSafeMetadata($record)) {
            return $this->assetNotFound();
        }

        try {
            $stream = Storage::disk($record->disk)->readStream($record->object_key);
        } catch (Throwable) {
            return $this->assetNotFound();
        }
        if (! is_resource($stream)) {
            return $this->assetNotFound();
        }

        return response()->stream(
            static function () use ($stream): void {
                try {
                    fpassthru($stream);
                } finally {
                    fclose($stream);
                }
            },
            Response::HTTP_OK,
            [
                'Content-Type' => $record->mime_type,
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }

    private function lessonIsUnavailable(Subject $subject, Course $course, Lesson $lesson): bool
    {
        return ! $subject->is_active
            || ! $course->is_active
            || ! $lesson->is_active
            || (int) $course->subject_id !== (int) $subject->id
            || (int) $lesson->course_id !== (int) $course->id;
    }

    private function hasAccess(Request $request, Lesson $lesson, AccessResolver $accessResolver): bool
    {
        $access = $accessResolver->check(
            $this->user($request),
            AccessResolver::ENTITY_LESSON,
            (int) $lesson->id,
        );

        return $access['access'] === AccessResolver::ACCESS_FULL || (bool) $lesson->is_free_preview;
    }

    private function hasSafeMetadata(LessonAssessmentAsset $asset): bool
    {
        return in_array($asset->mime_type, self::ALLOWED_MIME_TYPES, true)
            && is_string($asset->disk)
            && $asset->disk !== ''
            && is_string($asset->object_key)
            && $this->isSafeObjectKey($asset->object_key)
            && (int) $asset->width > 0
            && (int) $asset->height > 0;
    }

    private function isSafeObjectKey(string $objectKey): bool
    {
        if (
            $objectKey === ''
            || strlen($objectKey) > 1024
            || str_starts_with($objectKey, '/')
            || str_contains($objectKey, '\\')
            || str_contains($objectKey, "\0")
        ) {
            return false;
        }

        foreach (explode('/', $objectKey) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }

    private function trustedBrowserDenial(
        Request $request,
        TrustedBrowserAccessService $trustedBrowserAccess,
    ): ?JsonResponse {
        $decision = $trustedBrowserAccess->evaluate($request, $this->user($request));
        if (! $decision->shouldBlock()) {
            return null;
        }

        return $this->error(
            $decision->code ?? 'TRUSTED_BROWSER_REQUIRED',
            $decision->message ?? 'Подтвердите этот браузер для доступа к защищённым материалам.',
            Response::HTTP_FORBIDDEN,
        );
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = ImpersonationContext::effectiveUser($request);

        return $user;
    }

    private function lessonNotFound(): JsonResponse
    {
        return $this->error('LEARNING_LESSON_NOT_FOUND', 'Урок не найден.', Response::HTTP_NOT_FOUND);
    }

    private function accessDenied(): JsonResponse
    {
        return $this->error(
            'LEARNING_ACCESS_DENIED',
            'У пользователя нет доступа к этому уроку.',
            Response::HTTP_FORBIDDEN,
        );
    }

    private function assetNotFound(): JsonResponse
    {
        return $this->error(
            'LEARNING_ASSESSMENT_ASSET_NOT_FOUND',
            'Схема для экзамена не найдена.',
            Response::HTTP_NOT_FOUND,
        );
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()
            ->json(['error' => ['code' => $code, 'message' => $message]], $status)
            ->header('Cache-Control', 'private, no-store');
    }
}
