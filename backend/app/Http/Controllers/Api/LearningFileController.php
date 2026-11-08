<?php

namespace App\Http\Controllers\Api;

use App\Contracts\ProtectedFiles\TemporaryDownloadUrlIssuer;
use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\Subject;
use App\Services\AccessResolver;
use App\Services\ProtectedFiles\ProtectedFileDeliveryResponder;
use App\Services\ProtectedFiles\ProtectedFileDeliveryService;
use App\Services\TrustedBrowser\TrustedBrowserAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LearningFileController extends Controller
{
    public function index(
        Request $request,
        Subject $subject,
        Course $course,
        Lesson $lesson,
        AccessResolver $accessResolver,
        ProtectedFileDeliveryResponder $responder,
    ): JsonResponse {
        return $responder->index($request, $lesson, $accessResolver);
    }

    public function store(
        Request $request,
        Subject $subject,
        Course $course,
        Lesson $lesson,
        AccessResolver $accessResolver,
        TrustedBrowserAccessService $trustedBrowserAccess,
        ProtectedFileDeliveryService $deliveryService,
        ProtectedFileDeliveryResponder $responder,
    ): JsonResponse {
        return $responder->store($request, $lesson, $accessResolver, $trustedBrowserAccess, $deliveryService);
    }

    public function prewarm(
        Request $request,
        Subject $subject,
        Course $course,
        Lesson $lesson,
        AccessResolver $accessResolver,
        TrustedBrowserAccessService $trustedBrowserAccess,
        ProtectedFileDeliveryService $deliveryService,
        ProtectedFileDeliveryResponder $responder,
    ): JsonResponse {
        return $responder->prewarm($request, $lesson, $accessResolver, $trustedBrowserAccess, $deliveryService);
    }

    public function show(
        Request $request,
        Subject $subject,
        Course $course,
        Lesson $lesson,
        string $delivery,
        AccessResolver $accessResolver,
        TrustedBrowserAccessService $trustedBrowserAccess,
        ProtectedFileDeliveryService $deliveryService,
        ProtectedFileDeliveryResponder $responder,
    ): JsonResponse {
        return $responder->show($request, $lesson, $delivery, $accessResolver, $trustedBrowserAccess, $deliveryService);
    }

    public function grantAccess(
        Request $request,
        Subject $subject,
        Course $course,
        Lesson $lesson,
        string $delivery,
        AccessResolver $accessResolver,
        TrustedBrowserAccessService $trustedBrowserAccess,
        TemporaryDownloadUrlIssuer $urlIssuer,
        ProtectedFileDeliveryResponder $responder,
    ): JsonResponse {
        return $responder->grantAccess($request, $lesson, $delivery, $accessResolver, $trustedBrowserAccess, $urlIssuer);
    }
}
