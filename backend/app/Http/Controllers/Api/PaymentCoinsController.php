<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CoinReservation;
use App\Models\Order;
use App\Models\PromoReservation;
use App\Services\Bonuses\CoinLedgerService;
use App\Services\Bonuses\CoinOrderPricingService;
use App\Services\Bonuses\OrderDiscountService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PaymentCoinsController extends Controller
{
    public function balance(Request $request, CoinLedgerService $coins): JsonResponse
    {
        $enabled = config('bonuses.checkout_enabled', false) === true;

        return response()->json([
            'enabled' => $enabled,
            'coin_minor_units' => config('bonuses.coin_minor_units'),
            'available_units' => $enabled ? $coins->balance((int) $request->user()->id) : null,
        ])->header('Cache-Control', 'private, no-store');
    }

    public function quote(Request $request, Order $order, CoinLedgerService $coins): JsonResponse
    {
        $this->assertOwner($request, $order);
        $input = $request->validate(['coins_units' => ['required', 'integer', 'min:1']]);
        try {
            $this->assertEnabled();
            $quote = $coins->quote((int) $request->user()->id, (int) $order->id, (int) $input['coins_units']);

            return response()->json(array_intersect_key($quote, array_flip([
                'order_id', 'subtotal', 'currency', 'coins_units', 'coins_writeoff',
                'payable_total', 'coin_minor_units', 'expires_at', 'quote_hash',
            ])))->header('Cache-Control', 'private, no-store');
        } catch (DomainException $exception) {
            return $this->conflict($exception, $request);
        }
    }

    public function apply(Request $request, Order $order, CoinOrderPricingService $pricing): JsonResponse
    {
        $this->assertOwner($request, $order);
        $input = validator([
            ...$request->only(['coins_units', 'quote_hash']),
            'key' => $request->header('Idempotency-Key'),
        ], [
            'coins_units' => ['required', 'integer', 'min:1'],
            'quote_hash' => ['nullable', 'string', 'regex:/^[a-f0-9]{64}$/D'],
            'key' => ['required', 'string', 'max:128', 'regex:/^[A-Za-z0-9:_-]+$/D'],
        ])->validate();
        try {
            $result = $pricing->apply((int) $request->user()->id, (int) $order->id, (int) $input['coins_units'], $input['key'], $input['quote_hash'] ?? null);

            return response()->json(self::publicPricing($result))->header('Cache-Control', 'private, no-store');
        } catch (DomainException $exception) {
            return $this->conflict($exception, $request);
        }
    }

    public function settle(Request $request, Order $order, OrderDiscountService $pricing): JsonResponse
    {
        $this->assertOwner($request, $order);
        $user = $request->user();
        if (! $user || (! $user->usesAdminWorkspace() && ! $user->phone_verified_at)) {
            return response()->json([
                'error' => [
                    'code' => 'VERIFY_PHONE_REQUIRED',
                    'message' => 'Сначала подтвердите телефон перед подтверждением заказа.',
                    'request_id' => (string) Str::uuid(),
                ],
            ], 403)->header('Cache-Control', 'private, no-store');
        }
        $input = validator(['key' => $request->header('Idempotency-Key')], [
            'key' => ['required', 'string', 'max:128', 'regex:/^[A-Za-z0-9:_-]+$/D'],
        ])->validate();
        try {
            $pricing->finalizeZeroCash((int) $request->user()->id, (int) $order->id, $input['key']);
            $order->refresh();

            return response()->json([
                ...self::publicPricing($order),
                'payment_status' => $order->payment_status,
                'access_status' => $order->access_status,
            ])->header('Cache-Control', 'private, no-store');
        } catch (DomainException $exception) {
            return $this->conflict($exception, $request);
        }
    }

    public static function publicPricing(Order $order): array
    {
        $items = $order->items()->get();
        $kind = $items->count() === 1 ? ($items->sole()->benefit_snapshot['discount_type'] ?? null) : null;
        $isPromo = $kind === 'promo';
        $model = $isPromo ? PromoReservation::class : CoinReservation::class;
        $binding = $items->count() === 1 ? data_get($items->sole()->benefit_snapshot, $isPromo ? 'promo_reservation' : 'coin_reservation') : null;
        $reservation = is_array($binding) && isset($binding['reservation_id'])
            ? $model::query()->whereKey($binding['reservation_id'])->where('order_id', $order->id)
                ->where('user_id', $order->user_id)->first() : null;
        if ($reservation && (($reservation->proof['monetary_pricing_verified'] ?? false) !== true
            || ($reservation->proof['proof_hash'] ?? null) !== ($binding['proof_hash'] ?? null))) {
            $reservation = null;
        }

        return ['order_id' => (int) $order->id, 'currency' => $order->currency,
            'subtotal' => (int) $order->amount_total, 'discount' => (int) $order->amount_discount,
            'payable_total' => (int) $order->amount_final,
            'discount_type' => $reservation ? ($isPromo ? 'promo' : 'coins') : null,
            'coins_units' => $reservation && ! $isPromo ? $reservation->units : 0,
            'reservation_status' => $reservation?->status,
            'expires_at' => $reservation?->expires_at?->toIso8601String()];
    }

    private function assertOwner(Request $request, Order $order): void
    {
        abort_unless((int) $order->user_id === (int) $request->user()->id, 404);
    }

    private function assertEnabled(): void
    {
        if (config('bonuses.checkout_enabled', false) !== true) {
            throw new DomainException('BONUS_CHECKOUT_DISABLED');
        }
    }

    private function conflict(DomainException $exception, Request $request): JsonResponse
    {
        $code = $exception->getMessage();
        $message = match ($code) {
            'BONUS_CHECKOUT_DISABLED' => 'Оплата монетками пока не подключена.',
            'BONUS_INSUFFICIENT_COINS' => 'Не хватает доступных монеток.',
            'BONUS_COINS_EXCEED_ORDER' => 'Сумма монеток превышает стоимость заказа.',
            'BONUS_RESERVATION_EXPIRED' => 'Срок резерва монеток истёк. Обновите заказ.',
            'BONUS_QUOTE_CHANGED' => 'Расчёт изменился. Проверьте сумму ещё раз.',
            default => 'Не удалось применить монетки. Обновите состояние заказа.',
        };

        return response()->json(['error' => ['code' => $code, 'message' => $message,
            'request_id' => (string) ($request->header('X-Request-Id') ?: Str::uuid())]], 409)
            ->header('Cache-Control', 'private, no-store');
    }
}
