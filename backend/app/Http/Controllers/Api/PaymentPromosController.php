<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Bonuses\PromoOrderPricingService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PaymentPromosController extends Controller
{
    public function apply(Request $request, Order $order, PromoOrderPricingService $pricing): JsonResponse
    {
        abort_unless((int) $order->user_id === (int) $request->user()->id, 404);
        $input = validator([
            ...$request->only(['code', 'quote_hash']), 'key' => $request->header('Idempotency-Key'),
        ], [
            'code' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{1,64}$/D'],
            'quote_hash' => ['nullable', 'string', 'regex:/^[a-f0-9]{64}$/D'],
            'key' => ['required', 'string', 'max:128', 'regex:/^[A-Za-z0-9:_-]+$/D'],
        ])->validate();
        try {
            $result = $pricing->apply((int) $request->user()->id, (int) $order->id, $input['code'], $input['key'], $input['quote_hash'] ?? null);

            return response()->json(PaymentCoinsController::publicPricing($result))->header('Cache-Control', 'private, no-store');
        } catch (DomainException $exception) {
            return response()->json(['error' => ['code' => $exception->getMessage(),
                'message' => 'Не удалось применить промокод. Проверьте код и условия его использования.',
                'request_id' => (string) ($request->header('X-Request-Id') ?: Str::uuid())]], 409)->header('Cache-Control', 'private, no-store');
        }
    }
}
