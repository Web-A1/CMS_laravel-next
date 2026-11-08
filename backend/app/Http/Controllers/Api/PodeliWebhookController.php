<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Payments\PodeliClient;
use App\Services\Payments\PodeliWebhookHandler;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;

class PodeliWebhookController extends Controller
{
    public function __invoke(Request $request, PodeliWebhookHandler $handler): JsonResponse
    {
        $ips = config('podeli.sandbox', true) ? ['111.88.241.154'] : config('podeli.production_webhook_ips', []);
        if ($ips === [] || ! IpUtils::checkIp((string) $request->ip(), $ips)) {
            return response()->json(['status' => 'rejected'], 403);
        }
        if (! $request->isJson() || strlen($request->getContent()) > 65536) {
            return response()->json(['status' => 'rejected'], 400);
        }
        try {
            $handler->handle(PodeliClient::decode($request->getContent()));
        } catch (DomainException) {
            return response()->json(['status' => 'retry'], 503);
        }

        return response()->json(['status' => 'accepted']);
    }
}
