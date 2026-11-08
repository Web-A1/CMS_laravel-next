<?php

namespace App\Http\Controllers\Api;

use App\Contracts\ProtectedFiles\TemporaryDownloadUrlIssuer;
use App\Http\Controllers\Controller;
use App\Models\Guide;
use App\Services\AccessResolver;
use App\Services\ProtectedFiles\ProtectedFileDeliveryResponder;
use App\Services\ProtectedFiles\ProtectedFileDeliveryService;
use App\Services\TrustedBrowser\TrustedBrowserAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GuideFileController extends Controller
{
    public function index(
        Request $request,
        Guide $guide,
        AccessResolver $accessResolver,
        ProtectedFileDeliveryResponder $responder,
    ): JsonResponse {
        return $responder->index($request, $guide, $accessResolver);
    }

    public function store(
        Request $request,
        Guide $guide,
        AccessResolver $accessResolver,
        TrustedBrowserAccessService $trustedBrowserAccess,
        ProtectedFileDeliveryService $deliveryService,
        ProtectedFileDeliveryResponder $responder,
    ): JsonResponse {
        return $responder->store($request, $guide, $accessResolver, $trustedBrowserAccess, $deliveryService);
    }

    public function prewarm(
        Request $request,
        Guide $guide,
        AccessResolver $accessResolver,
        TrustedBrowserAccessService $trustedBrowserAccess,
        ProtectedFileDeliveryService $deliveryService,
        ProtectedFileDeliveryResponder $responder,
    ): JsonResponse {
        return $responder->prewarm($request, $guide, $accessResolver, $trustedBrowserAccess, $deliveryService);
    }

    public function show(
        Request $request,
        Guide $guide,
        string $delivery,
        AccessResolver $accessResolver,
        TrustedBrowserAccessService $trustedBrowserAccess,
        ProtectedFileDeliveryService $deliveryService,
        ProtectedFileDeliveryResponder $responder,
    ): JsonResponse {
        return $responder->show($request, $guide, $delivery, $accessResolver, $trustedBrowserAccess, $deliveryService);
    }

    public function grantAccess(
        Request $request,
        Guide $guide,
        string $delivery,
        AccessResolver $accessResolver,
        TrustedBrowserAccessService $trustedBrowserAccess,
        TemporaryDownloadUrlIssuer $urlIssuer,
        ProtectedFileDeliveryResponder $responder,
    ): JsonResponse {
        return $responder->grantAccess($request, $guide, $delivery, $accessResolver, $trustedBrowserAccess, $urlIssuer);
    }
}
