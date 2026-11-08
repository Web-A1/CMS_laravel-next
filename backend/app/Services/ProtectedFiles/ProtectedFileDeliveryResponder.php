<?php

namespace App\Services\ProtectedFiles;

use App\Contracts\ProtectedFiles\TemporaryDownloadUrlIssuer;
use App\Jobs\GenerateProtectedFileDeliveryJob;
use App\Models\Guide;
use App\Models\Lesson;
use App\Models\LessonMaterial;
use App\Models\LessonMaterialFile;
use App\Models\ProtectedFileDelivery;
use App\Services\AccessResolver;
use App\Services\TrustedBrowser\TrustedBrowserAccessService;
use App\Support\ImpersonationContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class ProtectedFileDeliveryResponder
{
    public function index(
        Request $request,
        Lesson|Guide $owner,
        AccessResolver $accessResolver,
    ): JsonResponse {
        if (ImpersonationContext::isActive($request)) {
            return ImpersonationContext::mediaDisabledResponse();
        }

        if (! $this->ownerIsActive($owner)) {
            return $this->ownerNotFound($owner);
        }
        if (! $this->hasAccess($request, $owner, $accessResolver)) {
            return $this->accessDenied($owner);
        }

        $files = $owner->materials()
            ->published()
            ->where('kind', LessonMaterial::KIND_PDF)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'title'])
            ->map(fn (LessonMaterial $material): array => [
                'materialId' => (int) $material->id,
                'title' => $material->title,
            ])
            ->all();

        return $this->privateResponse([
            'data' => [
                'files' => $files,
            ],
        ]);
    }

    public function store(
        Request $request,
        Lesson|Guide $owner,
        AccessResolver $accessResolver,
        TrustedBrowserAccessService $trustedBrowserAccess,
        ProtectedFileDeliveryService $deliveryService,
    ): JsonResponse {
        if (ImpersonationContext::isActive($request)) {
            return ImpersonationContext::mediaDisabledResponse();
        }

        $materialId = $request->input('materialId');
        if (array_keys($request->all()) !== ['materialId'] || ! is_int($materialId) || $materialId < 1) {
            return $this->error(
                'LEARNING_FILE_REQUEST_INVALID',
                'Не удалось определить файл.',
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        if (! $this->ownerIsActive($owner)) {
            return $this->ownerNotFound($owner);
        }

        if (! $this->hasAccess($request, $owner, $accessResolver)) {
            return $this->accessDenied($owner);
        }

        $trustedBrowserDenial = $this->trustedBrowserDenial($request, $trustedBrowserAccess);
        if ($trustedBrowserDenial) {
            return $trustedBrowserDenial;
        }

        $material = LessonMaterial::query()
            ->with('protectedFile')
            ->whereKey($materialId)
            ->where($owner instanceof Guide ? 'guide_id' : 'lesson_id', $owner->id)
            ->where('kind', LessonMaterial::KIND_PDF)
            ->published()
            ->first();

        if (! $material) {
            return $this->fileNotFound();
        }

        if (! $material->protectedFile) {
            return $this->fileUnavailable();
        }
        if (! $this->hasSafeMetadata($material->protectedFile)) {
            return $this->fileUnavailable();
        }

        $result = $deliveryService->request($request->user(), $material->protectedFile);
        if ($result['dispatch']) {
            GenerateProtectedFileDeliveryJob::dispatch($result['delivery']->id);
        }

        $status = $result['delivery']->status;

        return $this->privateResponse([
            'data' => $this->deliveryPayload($result['delivery'], $material->protectedFile),
        ], $status === 'ready' ? Response::HTTP_OK : Response::HTTP_ACCEPTED);
    }

    public function prewarm(
        Request $request,
        Lesson|Guide $owner,
        AccessResolver $accessResolver,
        TrustedBrowserAccessService $trustedBrowserAccess,
        ProtectedFileDeliveryService $deliveryService,
    ): JsonResponse {
        if (ImpersonationContext::isActive($request)) {
            return ImpersonationContext::mediaDisabledResponse();
        }

        if ($request->all() !== []) {
            return $this->error(
                'LEARNING_FILE_PREWARM_INVALID',
                'Не удалось запустить подготовку файлов.',
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        if (! $this->ownerIsActive($owner)) {
            return $this->ownerNotFound($owner);
        }

        if (! $this->hasAccess($request, $owner, $accessResolver)) {
            return $this->accessDenied($owner);
        }

        $trustedBrowserDenial = $this->trustedBrowserDenial($request, $trustedBrowserAccess);
        if ($trustedBrowserDenial) {
            return $trustedBrowserDenial;
        }

        $deliveries = [];
        $hasPendingWork = false;

        $materials = $owner->materials()
            ->with('protectedFile')
            ->published()
            ->where('kind', LessonMaterial::KIND_PDF)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'kind']);

        foreach ($materials as $material) {
            $source = $material->protectedFile;
            if (! $source || ! $this->hasSafeMetadata($source)) {
                continue;
            }

            $result = $deliveryService->request($request->user(), $source);
            if ($result['dispatch']) {
                GenerateProtectedFileDeliveryJob::dispatch($result['delivery']->id);
            }

            $delivery = $result['delivery'];
            $hasPendingWork = $hasPendingWork || ! $delivery->isReady();
            $deliveries[] = $this->deliveryPayload($delivery, $source, (int) $material->id);
        }

        return $this->privateResponse([
            'data' => [
                'deliveries' => $deliveries,
            ],
        ], $hasPendingWork ? Response::HTTP_ACCEPTED : Response::HTTP_OK);
    }

    public function show(
        Request $request,
        Lesson|Guide $owner,
        string $delivery,
        AccessResolver $accessResolver,
        TrustedBrowserAccessService $trustedBrowserAccess,
        ProtectedFileDeliveryService $deliveryService,
    ): JsonResponse {
        if (ImpersonationContext::isActive($request)) {
            return ImpersonationContext::mediaDisabledResponse();
        }

        if (! $this->ownerIsActive($owner)) {
            return $this->ownerNotFound($owner);
        }
        if (! $this->hasAccess($request, $owner, $accessResolver)) {
            return $this->accessDenied($owner);
        }

        $trustedBrowserDenial = $this->trustedBrowserDenial($request, $trustedBrowserAccess);
        if ($trustedBrowserDenial) {
            return $trustedBrowserDenial;
        }

        $record = $this->deliveryForUser($request, $owner, $delivery);
        if (! $record) {
            return $this->fileNotFound();
        }
        if (! $this->hasSafeMetadata($record->source)) {
            return $this->fileUnavailable();
        }
        if (! $this->hasCurrentSourceVersion($record)) {
            return $this->fileUnavailable();
        }

        if ($record->status === ProtectedFileDelivery::STATUS_PENDING) {
            $result = $deliveryService->request($request->user(), $record->source);
            if ($result['dispatch']) {
                GenerateProtectedFileDeliveryJob::dispatch($result['delivery']->id);
            }

            return $this->deliveryResponse($result['delivery']->setRelation('source', $record->source));
        }

        return $this->deliveryResponse($record);
    }

    public function grantAccess(
        Request $request,
        Lesson|Guide $owner,
        string $delivery,
        AccessResolver $accessResolver,
        TrustedBrowserAccessService $trustedBrowserAccess,
        TemporaryDownloadUrlIssuer $urlIssuer,
    ): JsonResponse {
        if (ImpersonationContext::isActive($request)) {
            return ImpersonationContext::mediaDisabledResponse();
        }

        if (
            array_keys($request->all()) !== ['confidentialityAcknowledged']
            || $request->input('confidentialityAcknowledged') !== true
        ) {
            return $this->error(
                'LEARNING_FILE_ACCESS_ACKNOWLEDGEMENT_REQUIRED',
                'Подтвердите правила использования файла.',
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        if (! $this->ownerIsActive($owner)) {
            return $this->ownerNotFound($owner);
        }
        if (! $this->hasAccess($request, $owner, $accessResolver)) {
            return $this->accessDenied($owner);
        }

        $trustedBrowserDenial = $this->trustedBrowserDenial($request, $trustedBrowserAccess);
        if ($trustedBrowserDenial) {
            return $trustedBrowserDenial;
        }

        $record = $this->deliveryForUser($request, $owner, $delivery);
        if (! $record) {
            return $this->fileNotFound();
        }
        if (! $this->hasSafeMetadata($record->source)) {
            return $this->fileUnavailable();
        }
        if (! $this->hasCurrentSourceVersion($record)) {
            return $this->fileUnavailable();
        }
        if (! $record->isReady()) {
            return $this->error(
                'LEARNING_FILE_NOT_READY',
                'Персональная копия файла ещё не готова.',
                Response::HTTP_CONFLICT,
            );
        }
        if (
            ! is_string($record->output_object_key)
            || ! $this->isSafeObjectKey($record->output_object_key)
        ) {
            return $this->fileUnavailable();
        }

        $ttl = config('protected_files.temporary_url_ttl_seconds', 120);
        if (! is_int($ttl) || $ttl < 30 || $ttl > 300) {
            return $this->fileUnavailable();
        }
        $expiresAt = CarbonImmutable::now()->addSeconds($ttl);

        try {
            $url = $urlIssuer->issue(
                (string) config('protected_files.output_disk', 'local'),
                $record->output_object_key,
                $expiresAt,
                $record->source->download_filename,
            );
        } catch (Throwable) {
            return $this->fileUnavailable();
        }

        return $this->privateResponse([
            'data' => [
                'downloadUrl' => $url,
                'expiresAt' => $expiresAt->toJSON(),
                'filename' => $record->source->download_filename,
            ],
        ]);
    }

    private function ownerIsActive(Lesson|Guide $owner): bool
    {
        return $owner->is_active && ($owner instanceof Guide || ($owner->course?->is_active && $owner->course?->subject?->is_active));
    }

    private function hasAccess(
        Request $request,
        Lesson|Guide $owner,
        AccessResolver $accessResolver,
    ): bool {
        $access = $accessResolver->check(
            $request->user(),
            $owner instanceof Guide ? AccessResolver::ENTITY_GUIDE : AccessResolver::ENTITY_LESSON,
            (int) $owner->id,
        );

        return $access['access'] === AccessResolver::ACCESS_FULL || ($owner instanceof Lesson && (bool) $owner->is_free_preview);
    }

    private function deliveryForUser(
        Request $request,
        Lesson|Guide $owner,
        string $publicId,
    ): ?ProtectedFileDelivery {
        return ProtectedFileDelivery::query()
            ->with('source')
            ->where('public_id', $publicId)
            ->where('user_id', $request->user()->id)
            ->whereHas('source.material', function ($query) use ($owner): void {
                $query
                    ->where($owner instanceof Guide ? 'guide_id' : 'lesson_id', $owner->id)
                    ->where('kind', LessonMaterial::KIND_PDF)
                    ->published();
            })
            ->first();
    }

    private function deliveryResponse(ProtectedFileDelivery $delivery): JsonResponse
    {
        return $this->privateResponse([
            'data' => $this->deliveryPayload($delivery, $delivery->source),
        ]);
    }

    /** @return array<string, mixed> */
    private function deliveryPayload(
        ProtectedFileDelivery $delivery,
        LessonMaterialFile $source,
        ?int $materialId = null,
    ): array {
        $payload = [];
        if ($materialId !== null) {
            $payload['materialId'] = $materialId;
        }

        return [
            ...$payload,
            'deliveryId' => $delivery->public_id,
            'status' => $delivery->status,
            'filename' => $source->download_filename,
            'canRequestAccess' => $delivery->isReady(),
        ];
    }

    private function hasSafeMetadata(LessonMaterialFile $source): bool
    {
        $filename = $source->download_filename;

        return $source->source_mime_type === 'application/pdf'
            && $this->isSafeObjectKey($source->source_object_key)
            && is_string($filename)
            && $filename !== ''
            && strlen($filename) <= 255
            && basename($filename) === $filename
            && ! str_contains($filename, '\\')
            && preg_match('/[\x00-\x1F\x7F]/', $filename) !== 1
            && str_ends_with(strtolower($filename), '.pdf');
    }

    private function hasCurrentSourceVersion(ProtectedFileDelivery $delivery): bool
    {
        return preg_match('/\A[a-f0-9]{64}\z/', (string) $delivery->source_sha256) === 1
            && hash_equals(
                (string) $delivery->source_sha256,
                (string) $delivery->source->source_sha256,
            );
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

    private function ownerNotFound(Lesson|Guide $owner): JsonResponse
    {
        return $this->error(
            $owner instanceof Guide ? 'LEARNING_GUIDE_NOT_FOUND' : 'LEARNING_LESSON_NOT_FOUND',
            $owner instanceof Guide ? 'Методичка не найдена.' : 'Урок не найден.',
            Response::HTTP_NOT_FOUND,
        );
    }

    private function accessDenied(Lesson|Guide $owner): JsonResponse
    {
        return $this->error(
            'LEARNING_ACCESS_DENIED',
            $owner instanceof Guide ? 'У пользователя нет доступа к этой методичке.' : 'У пользователя нет доступа к этому уроку.',
            Response::HTTP_FORBIDDEN,
        );
    }

    private function trustedBrowserDenial(
        Request $request,
        TrustedBrowserAccessService $trustedBrowserAccess,
    ): ?JsonResponse {
        $decision = $trustedBrowserAccess->evaluate($request, $request->user());
        if (! $decision->shouldBlock()) {
            return null;
        }

        return $this->error(
            $decision->code ?? 'TRUSTED_BROWSER_REQUIRED',
            $decision->message ?? 'Подтвердите этот браузер для доступа к защищённым материалам.',
            Response::HTTP_FORBIDDEN,
        );
    }

    private function fileNotFound(): JsonResponse
    {
        return $this->error(
            'LEARNING_FILE_NOT_FOUND',
            'Файл не найден.',
            Response::HTTP_NOT_FOUND,
        );
    }

    private function fileUnavailable(): JsonResponse
    {
        return $this->error(
            'LEARNING_FILE_UNAVAILABLE',
            'Файл временно недоступен.',
            Response::HTTP_SERVICE_UNAVAILABLE,
        );
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return $this->privateResponse([
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ], $status);
    }

    /** @param array<string, mixed> $payload */
    private function privateResponse(array $payload, int $status = Response::HTTP_OK): JsonResponse
    {
        return response()
            ->json($payload, $status)
            ->header('Cache-Control', 'private, no-store');
    }
}
