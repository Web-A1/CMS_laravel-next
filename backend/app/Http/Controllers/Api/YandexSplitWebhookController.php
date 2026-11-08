<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Payments\YandexSplitWebhookHandler;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class YandexSplitWebhookController extends Controller
{
    public function __invoke(Request $request, YandexSplitWebhookHandler $handler): JsonResponse
    {
        if (strtolower(explode(';', (string) $request->header('Content-Type'))[0]) !== 'application/octet-stream'
            || strlen($request->getContent()) > 65536) {
            return response()->json(['status' => 'fail', 'reasonCode' => 'OTHER'], 400);
        }
        try {
            $handler->handle($request->getContent());
        } catch (DomainException $exception) {
            $unauthorized = $exception->getMessage() === 'PAYMENT_YANDEX_UNAUTHORIZED';

            return response()->json(['status' => 'fail', 'reasonCode' => $unauthorized ? 'UNAUTHORIZED' : 'OTHER'], $unauthorized ? 401 : 503);
        }

        return response()->json(['status' => 'success']);
    }
}
