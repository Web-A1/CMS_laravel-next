<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Order;
use App\Models\OrderSettlement;
use App\Models\PaymentAttempt;
use App\Models\PaymentAuditLog;
use App\Models\ProviderEvent;
use App\Models\Refund;
use App\Models\SellableEntity;
use App\Models\User;
use App\Services\Bonuses\OrderDiscountService;
use App\Services\EntitlementGrantService;
use App\Services\OrderItemSnapshotService;
use App\Services\OrderSettlementService;
use App\Services\Payments\CloudpaymentsRefundService;
use App\Services\Payments\CloudpaymentsWebhookHandler;
use App\Services\Payments\PaymentAttemptCreator;
use App\Services\Payments\PaymentOutboxService;
use App\Services\Payments\PaymentProviderRegistry;
use App\Services\Payments\PodeliRefundService;
use App\Services\Payments\RobokassaRefundService;
use App\Services\Payments\YandexSplitRefundService;
use App\Services\Payments\ZeroCashRefundService;
use App\Services\PaymentStateMachine;
use App\Support\SellableTariff;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\IpUtils;

class PaymentsController extends Controller
{
    public function methods(PaymentProviderRegistry $providers): JsonResponse
    {
        $methods = [];
        foreach (['yandex_split' => 'yandex-split', 'robokassa' => 'robokassa', 'podeli' => 'podeli'] as $provider => $method) {
            if (config($provider.'.enabled', false) && $providers->forProvider($provider) !== null) {
                $methods[] = $method;
            }
        }

        return response()->json(['additional_methods' => $methods]);
    }

    private const CLOUDPAYMENTS_WEBHOOK_TYPES = ['check', 'pay', 'fail', 'refund'];

    public function createOrder(Request $request, OrderItemSnapshotService $snapshotService): JsonResponse
    {
        $validated = $request->validate([
            'course_id' => ['nullable', 'integer', 'min:1'],
            'sellable_sku' => ['nullable', 'string', 'max:128'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'promo_code' => ['nullable', 'string', 'max:64'],
            'coins_amount' => ['nullable', 'integer', 'min:0'],
            'discount_mode' => ['nullable', 'in:none,promo,coins'],
        ]);

        $user = $request->user();
        $hasCourseId = array_key_exists('course_id', $validated) && $validated['course_id'] !== null;
        $hasSellableSku = trim((string) ($validated['sellable_sku'] ?? '')) !== '';

        if ($hasCourseId === $hasSellableSku) {
            return $this->error(
                'PAYMENT_ORDER_INPUT_AMBIGUOUS',
                'Provide exactly one checkout source.',
                422,
                $request
            );
        }

        if ($hasSellableSku) {
            return $this->createSellableOrder($request, $snapshotService, $validated, $user?->id ? (int) $user->id : null);
        }

        $course = Course::query()
            ->with([
                'subject',
                'lessons' => fn ($query) => $query->active()->orderBy('sort_order')->orderBy('id'),
            ])
            ->active()
            ->find((int) $validated['course_id']);

        if (! $course) {
            return $this->error('PAYMENT_COURSE_NOT_FOUND', 'Course is not available for checkout.', 422, $request);
        }

        if (! $this->clientCurrencyMatches($validated, 'RUB')) {
            return $this->error('PAYMENT_CURRENCY_MISMATCH', 'Currency does not match the checkout item.', 422, $request);
        }

        try {
            $order = DB::transaction(function () use ($course, $snapshotService, $user): Order {
                $amountTotal = (int) $course->price_cents;
                $amountDiscount = 0;
                $amountFinal = $amountTotal;

                /** @var Order $order */
                $order = Order::query()->create([
                    'user_id' => $user?->id,
                    'course_id' => $course->id,
                    'currency' => 'RUB',
                    'amount_total' => $amountTotal,
                    'amount_discount' => $amountDiscount,
                    'amount_final' => $amountFinal,
                    'amount_paid' => 0,
                    'amount_refunded' => 0,
                    'payment_status' => 'draft',
                    'access_status' => 'not_granted',
                ]);

                $snapshotService->createCourseItem($order, $course);

                return $order->fresh();
            });
        } catch (DomainException) {
            return $this->error('PAYMENT_COURSE_NOT_GRANTABLE', 'Course has no grantable content snapshot.', 422, $request);
        }

        return response()->json([
            'order_id' => $order->id,
            'payment_status' => $order->payment_status,
            'access_status' => $order->access_status,
        ], 201);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function createSellableOrder(
        Request $request,
        OrderItemSnapshotService $snapshotService,
        array $validated,
        ?int $userId
    ): JsonResponse {
        $sellableSku = trim((string) $validated['sellable_sku']);
        $sellable = SellableEntity::query()
            ->active()
            ->where('sku', $sellableSku)
            ->first();

        if (! $sellable) {
            return $this->error('PAYMENT_SELLABLE_NOT_FOUND', 'Sellable item is not available for checkout.', 422, $request);
        }

        $currency = strtoupper((string) $sellable->currency);
        if (! $this->clientCurrencyMatches($validated, $currency)) {
            return $this->error('PAYMENT_CURRENCY_MISMATCH', 'Currency does not match the checkout item.', 422, $request);
        }

        try {
            $order = DB::transaction(function () use ($sellable, $snapshotService, $userId, $currency): Order {
                $amountTotal = (int) $sellable->price_cents;
                $amountDiscount = 0;
                $amountFinal = $amountTotal;
                $courseId = (string) $sellable->entity_type === 'course' && $sellable->entity_id !== null
                    ? (int) $sellable->entity_id
                    : null;

                /** @var Order $order */
                $order = Order::query()->create([
                    'user_id' => $userId,
                    'course_id' => $courseId,
                    'currency' => $currency,
                    'amount_total' => $amountTotal,
                    'amount_discount' => $amountDiscount,
                    'amount_final' => $amountFinal,
                    'amount_paid' => 0,
                    'amount_refunded' => 0,
                    'payment_status' => 'draft',
                    'access_status' => 'not_granted',
                ]);

                $snapshotService->createSellableItem($order, $sellable);

                return $order->fresh();
            });
        } catch (DomainException) {
            return $this->error('PAYMENT_SELLABLE_NOT_GRANTABLE', 'Sellable item has no grantable content snapshot.', 422, $request);
        }

        return response()->json([
            'order_id' => $order->id,
            'payment_status' => $order->payment_status,
            'access_status' => $order->access_status,
        ], 201);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function clientCurrencyMatches(array $validated, string $serverCurrency): bool
    {
        if (! array_key_exists('currency', $validated)) {
            return true;
        }

        return strtoupper(trim((string) $validated['currency'])) === $serverCurrency;
    }

    public function createAttempt(
        Request $request,
        Order $order,
        PaymentStateMachine $stateMachine,
        PaymentAttemptCreator $attemptCreator
    ): JsonResponse {
        if ($forbidden = $this->denyUnlessOrderAccessible($request, $order)) {
            return $forbidden;
        }
        if ($phoneRequired = $this->denyWithoutVerifiedPhone($request)) {
            return $phoneRequired;
        }

        $validated = $request->validate([
            'provider' => ['required', 'string', 'in:cloudpayments,yandex_split,robokassa,podeli,test_provider'],
            'return_url' => ['required', 'url'],
            'cancel_url' => ['required', 'url'],
            'client_info' => ['required_if:provider,podeli', 'array:first_name,last_name,phone,email'],
            'client_info.first_name' => ['required_if:provider,podeli', 'string', 'max:60'],
            'client_info.last_name' => ['required_if:provider,podeli', 'string', 'max:60'],
            'client_info.phone' => ['required_if:provider,podeli', 'string', 'regex:/^7[0-9]{10}$/D'],
            'client_info.email' => ['required_if:provider,podeli', 'email:rfc', 'max:250'],
        ]);

        try {
            $updatedOrder = $attemptCreator->create($order, $validated, $stateMachine);
        } catch (DomainException $e) {
            return $this->error($this->paymentAttemptErrorCode($e), $e->getMessage(), 409, $request);
        }

        $activeAttempt = PaymentAttempt::query()->find($updatedOrder->active_attempt_id);

        return response()->json([
            'attempt_id' => $activeAttempt?->id,
            'payment_status' => $updatedOrder->payment_status,
            'provider' => $activeAttempt?->provider,
            'provider_payment_id' => $activeAttempt?->provider_payment_id,
            'provider_payment_url' => $activeAttempt?->provider_payment_url,
            'amount' => $activeAttempt?->amount,
            'currency' => $activeAttempt?->currency,
            'provider_payload' => $activeAttempt ? $this->publicAttemptPayload($activeAttempt)['provider_payload'] : null,
        ], 201);
    }

    public function showOrder(Request $request, Order $order): JsonResponse
    {
        if ($forbidden = $this->denyUnlessOrderAccessible($request, $order)) {
            return $forbidden;
        }

        $activeAttempt = null;
        if ($order->active_attempt_id !== null) {
            $activeAttempt = PaymentAttempt::query()->find($order->active_attempt_id);
        }

        return response()->json([
            'order_id' => $order->id,
            'pricing' => PaymentCoinsController::publicPricing($order),
            'payment_status' => $order->payment_status,
            'access_status' => $order->access_status,
            'items' => $order->items()
                ->orderBy('id')
                ->get()
                ->map(fn ($item): array => [
                    'id' => $item->id,
                    'sellable_kind' => $item->sellable_kind,
                    'sellable_sku' => $item->sellable_sku,
                    'tariff' => SellableTariff::fromSnapshot($item->catalog_snapshot),
                    'purchased_entity_type' => $item->purchased_entity_type,
                    'purchased_entity_id' => $item->purchased_entity_id,
                    'display_title' => $item->display_title,
                    'currency' => $item->currency,
                    'final_amount' => $item->final_amount,
                    'access_status' => $item->access_status,
                ])
                ->values(),
            'active_attempt' => $activeAttempt ? $this->publicAttemptPayload($activeAttempt) : null,
        ])->header('Cache-Control', 'private, no-store');
    }

    public function retry(
        Request $request,
        Order $order,
        PaymentStateMachine $stateMachine,
        PaymentAttemptCreator $attemptCreator
    ): JsonResponse {
        if ($forbidden = $this->denyUnlessOrderAccessible($request, $order)) {
            return $forbidden;
        }
        if ($phoneRequired = $this->denyWithoutVerifiedPhone($request)) {
            return $phoneRequired;
        }

        if ($order->payment_status !== 'pending_payment') {
            return $this->error('PAYMENT_INVALID_TRANSITION', 'Retry is allowed only for pending orders.', 409, $request);
        }

        $validated = $request->validate([
            'provider' => ['nullable', 'string', 'in:cloudpayments,yandex_split,robokassa,podeli,test_provider'],
            'return_url' => ['nullable', 'url'],
            'cancel_url' => ['nullable', 'url'],
            'client_info' => ['required_if:provider,podeli', 'array:first_name,last_name,phone,email'],
            'client_info.first_name' => ['required_if:provider,podeli', 'string', 'max:60'],
            'client_info.last_name' => ['required_if:provider,podeli', 'string', 'max:60'],
            'client_info.phone' => ['required_if:provider,podeli', 'string', 'regex:/^7[0-9]{10}$/D'],
            'client_info.email' => ['required_if:provider,podeli', 'email:rfc', 'max:250'],
        ]);

        try {
            $updatedOrder = $attemptCreator->create($order, [
                'provider' => (string) ($validated['provider'] ?? 'test_provider'),
                'return_url' => (string) ($validated['return_url'] ?? 'https://example.com/return'),
                'cancel_url' => (string) ($validated['cancel_url'] ?? 'https://example.com/cancel'),
                'client_info' => $validated['client_info'] ?? [],
            ], $stateMachine);
        } catch (DomainException $e) {
            return $this->error($this->paymentAttemptErrorCode($e), $e->getMessage(), 409, $request);
        }

        $activeAttempt = PaymentAttempt::query()->find($updatedOrder->active_attempt_id);

        return response()->json([
            'attempt_id' => $activeAttempt?->id,
            'payment_status' => $updatedOrder->payment_status,
            'provider' => $activeAttempt?->provider,
            'provider_payment_id' => $activeAttempt?->provider_payment_id,
            'provider_payment_url' => $activeAttempt?->provider_payment_url,
            'amount' => $activeAttempt?->amount,
            'currency' => $activeAttempt?->currency,
            'provider_payload' => $activeAttempt ? $this->publicAttemptPayload($activeAttempt)['provider_payload'] : null,
        ], 201);
    }

    private function denyUnlessOrderAccessible(Request $request, Order $order): ?JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return $this->error('PAYMENT_ORDER_FORBIDDEN', 'Order is not available for this user.', 403, $request);
        }

        if (in_array($user->role, ['admin_full', 'admin_limited', 'teacher'], true)) {
            return null;
        }

        if ($order->user_id !== null && (int) $order->user_id === (int) $user->id) {
            return null;
        }

        if (
            $user->role === 'manager'
            && $order->user_id !== null
            && User::query()
                ->whereKey($order->user_id)
                ->where('manager_id', $user->id)
                ->exists()
        ) {
            return null;
        }

        return $this->error('PAYMENT_ORDER_FORBIDDEN', 'Order is not available for this user.', 403, $request);
    }

    private function denyWithoutVerifiedPhone(Request $request): ?JsonResponse
    {
        $user = $request->user();
        if (! $user || $user->usesAdminWorkspace() || $user->phone_verified_at) {
            return null;
        }

        return $this->error(
            'VERIFY_PHONE_REQUIRED',
            'Сначала подтвердите телефон перед оплатой.',
            403,
            $request,
        );
    }

    private function paymentAttemptErrorCode(DomainException $e): string
    {
        $message = $e->getMessage();

        return str_starts_with($message, 'PAYMENT_')
            ? $message
            : 'PAYMENT_INVALID_TRANSITION';
    }

    /**
     * Return only the provider fields the checkout client needs. Secrets and
     * the internal provider_substatus envelope must stay server-side.
     *
     * @return array<string, mixed>
     */
    private function publicAttemptPayload(PaymentAttempt $attempt): array
    {
        $substatus = is_array($attempt->provider_substatus) ? $attempt->provider_substatus : [];
        $providerPayload = null;

        if ($attempt->provider === 'cloudpayments' && ($substatus['mode'] ?? null) === 'widget') {
            $providerPayload = [
                'mode' => 'widget',
                'public_id' => (string) ($substatus['public_id'] ?? ''),
                'invoice_id' => (string) ($substatus['invoice_id'] ?? $attempt->order_id),
                'amount' => (string) ($substatus['amount'] ?? number_format(((int) $attempt->amount) / 100, 2, '.', '')),
                'currency' => strtoupper((string) ($substatus['currency'] ?? $attempt->currency)),
                'description' => (string) ($substatus['description'] ?? ''),
            ];

            if ($providerPayload['public_id'] === '') {
                $providerPayload = null;
            }
        }

        return [
            'id' => (int) $attempt->id,
            'provider' => (string) $attempt->provider,
            'status' => (string) $attempt->status,
            'provider_payment_id' => $attempt->provider_payment_id,
            'provider_payment_url' => $attempt->provider_payment_url,
            'amount' => (int) $attempt->amount,
            'currency' => strtoupper((string) $attempt->currency),
            'provider_payload' => $providerPayload,
        ];
    }

    public function cancel(
        Request $request,
        Order $order,
        PaymentStateMachine $stateMachine,
        PaymentOutboxService $outbox
    ): JsonResponse {
        try {
            $order = DB::transaction(function () use ($request, $order, $stateMachine, $outbox): Order {
                $order = Order::query()->lockForUpdate()->findOrFail($order->id);
                $attempt = $order->activeAttempt()->lockForUpdate()->first();
                if ($attempt?->status === 'pending' && ($attempt->provider === 'cloudpayments' && $attempt->provider_payment_id !== null
                    || in_array($attempt->provider, ['yandex_split', 'podeli', 'robokassa'], true))) {
                    throw new DomainException('Дождитесь подтверждения платежа от банка перед отменой заказа.');
                }
                if (OrderSettlement::query()->where('order_id', $order->id)
                    ->where('status', OrderSettlement::STATUS_CONFIRMED)->exists()) {
                    throw new DomainException('A settled order cannot be cancelled.');
                }

                $fromStatus = $order->payment_status;
                app(OrderDiscountService::class)->releaseForOrder($order, 'cancelled');
                $stateMachine->transition($order, 'cancelled');
                $outbox->publishTransition($order, $order->active_attempt_id, null, $fromStatus, 'cancelled', [
                    'source' => 'manual_cancel',
                ]);
                PaymentAuditLog::query()->create([
                    'order_id' => $order->id,
                    'action' => 'manual_cancel',
                    'from_status' => $fromStatus,
                    'to_status' => 'cancelled',
                    'actor_type' => 'staff',
                    'actor_id' => $request->user()?->id,
                    'reason' => (string) $request->input('reason', 'manual cancel'),
                ]);

                return $order;
            });
        } catch (DomainException $e) {
            return $this->error('PAYMENT_INVALID_TRANSITION', $e->getMessage(), 409, $request);
        }

        return response()->json([
            'status' => 'ok',
            'payment_status' => $order->payment_status,
        ]);
    }

    public function refund(
        Request $request,
        Order $order,
        PaymentStateMachine $stateMachine,
        PaymentOutboxService $outbox,
        EntitlementGrantService $entitlements,
        CloudpaymentsRefundService $cloudpaymentsRefunds,
        YandexSplitRefundService $yandexSplitRefunds,
        RobokassaRefundService $robokassaRefunds,
        PodeliRefundService $podeliRefunds
    ): JsonResponse {
        $validated = $request->validate([
            'refund_type' => ['required', 'in:full,partial'],
            'amount' => ['nullable', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:255'],
            'policy_access_action' => ['required', 'in:keep,revoke'],
        ]);

        $provider = $order->activeAttempt()->value('provider');
        if ($order->settlement_type === OrderSettlement::TYPE_SETTLED_ZERO_CASH) {
            try {
                $refund = app(ZeroCashRefundService::class)->request($order, $validated,
                    (string) $request->header('Idempotency-Key', ''), (int) $request->user()->id);
            } catch (DomainException $exception) {
                return $this->error($exception->getMessage(), 'Refund request could not be accepted.', 409, $request);
            }
            $order->refresh();

            return response()->json(['refund_id' => $refund->id, 'payment_status' => $order->payment_status,
                'access_status' => $order->access_status, 'status' => $refund->status]);
        }
        if (in_array($provider, ['cloudpayments', 'yandex_split', 'robokassa', 'podeli'], true)) {
            try {
                $refundService = match ($provider) {
                    'cloudpayments' => $cloudpaymentsRefunds,
                    'yandex_split' => $yandexSplitRefunds,
                    'robokassa' => $robokassaRefunds,
                    'podeli' => $podeliRefunds,
                };
                $refund = $refundService->request($order, $validated,
                    (string) $request->header('Idempotency-Key', ''), (int) $request->user()->id);
            } catch (DomainException $exception) {
                return $this->error($exception->getMessage(), 'Refund request could not be accepted.', 409, $request);
            }

            $order->refresh();

            return response()->json([
                'refund_id' => $refund->id,
                'payment_status' => $order->payment_status,
                'access_status' => $order->access_status,
                'status' => $refund->status,
            ], 202);
        }
        if ($provider !== null && $provider !== 'test_provider') {
            return $this->error('PAYMENT_PROVIDER_REFUND_UNAVAILABLE', 'Provider refund is not available.', 409, $request);
        }
        if (! app()->environment(['local', 'testing'])) {
            return $this->error('PAYMENT_PROVIDER_REFUND_UNAVAILABLE', 'A confirmed provider refund is required.', 409, $request);
        }

        $refundAmount = (int) ($validated['amount'] ?? $order->amount_paid);
        if ($validated['refund_type'] === 'full') {
            $refundAmount = $order->amount_paid - $order->amount_refunded;
        }

        try {
            $updatedOrder = DB::transaction(function () use ($order, $refundAmount, $validated, $stateMachine, $outbox, $entitlements): Order {
                /** @var Order $lockedOrder */
                $lockedOrder = Order::query()
                    ->whereKey($order->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($lockedOrder->payment_status !== 'paid') {
                    throw new DomainException('PAYMENT_REFUND_FORBIDDEN');
                }

                $remaining = $lockedOrder->amount_paid - $lockedOrder->amount_refunded;
                if ($remaining < $refundAmount) {
                    throw new DomainException('PAYMENT_REFUND_AMOUNT_EXCEEDED');
                }

                $newRefunded = $lockedOrder->amount_refunded + $refundAmount;
                $isFullRefund = $newRefunded >= $lockedOrder->amount_paid || $validated['refund_type'] === 'full';
                $nextStatus = $isFullRefund ? 'refunded' : 'paid';
                $fromStatus = $lockedOrder->payment_status;
                $previousAccessStatus = (string) $lockedOrder->access_status;
                if (! $stateMachine->canTransition($lockedOrder->payment_status, $nextStatus) && $nextStatus !== 'paid') {
                    throw new DomainException("Invalid payment transition: {$lockedOrder->payment_status} -> {$nextStatus}");
                }

                $refund = Refund::query()->create([
                    'order_id' => $lockedOrder->id,
                    'attempt_id' => $lockedOrder->active_attempt_id,
                    'refund_type' => $validated['refund_type'],
                    'amount' => $refundAmount,
                    'currency' => $lockedOrder->currency,
                    'status' => $isFullRefund ? 'completed' : 'pending',
                    'reason' => $validated['reason'],
                    'policy_access_action' => $validated['policy_access_action'],
                    'created_by' => request()->user()?->id,
                    'completed_at' => $isFullRefund ? now() : null,
                ]);

                $lockedOrder->amount_refunded = $newRefunded;
                $grantRevoke = [
                    'matched' => 0,
                    'revoked' => 0,
                    'already_inactive' => 0,
                ];
                if ($validated['policy_access_action'] === 'revoke') {
                    $orderItemIds = $lockedOrder->items()
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->pluck('id')
                        ->map(static fn (mixed $id): int => (int) $id)
                        ->all();

                    if ($lockedOrder->user_id !== null) {
                        $grantRevoke = $entitlements->revokeOrderGrants(
                            (int) $lockedOrder->user_id,
                            (int) $lockedOrder->id,
                            $orderItemIds,
                            "refund:{$refund->id}:manual_refund",
                        );
                    }

                    if ($orderItemIds !== []) {
                        $lockedOrder->items()
                            ->whereIn('id', $orderItemIds)
                            ->update(['access_status' => 'revoked']);
                    }
                }

                $accessStatus = $validated['policy_access_action'] === 'revoke'
                    ? 'revoked'
                    : $previousAccessStatus;
                if ($nextStatus === 'refunded') {
                    $stateMachine->transition($lockedOrder, $nextStatus, $accessStatus);
                } else {
                    $lockedOrder->access_status = $accessStatus;
                    $lockedOrder->save();
                }

                $auditMeta = [
                    'refund_type' => $validated['refund_type'],
                    'amount' => $refundAmount,
                    'policy_access_action' => $validated['policy_access_action'],
                ];
                if ($validated['policy_access_action'] === 'revoke') {
                    $auditMeta['grant_revoke'] = $grantRevoke;
                }

                PaymentAuditLog::query()->create([
                    'order_id' => $lockedOrder->id,
                    'attempt_id' => $lockedOrder->active_attempt_id,
                    'refund_id' => $refund->id,
                    'action' => 'refund_requested',
                    'from_status' => 'paid',
                    'to_status' => $nextStatus,
                    'actor_type' => 'staff',
                    'actor_id' => request()->user()?->id,
                    'reason' => $validated['reason'],
                    'meta' => $auditMeta,
                ]);
                $outbox->publishTransition($lockedOrder->fresh(), $lockedOrder->active_attempt_id, $refund, $fromStatus, $nextStatus, [
                    'source' => 'manual_refund',
                ]);

                return $lockedOrder->fresh();
            }, 3);
        } catch (DomainException $e) {
            if ($e->getMessage() === 'PAYMENT_REFUND_FORBIDDEN') {
                return $this->error('PAYMENT_REFUND_FORBIDDEN', 'Refund is allowed only for paid orders.', 403, $request);
            }
            if ($e->getMessage() === 'PAYMENT_REFUND_AMOUNT_EXCEEDED') {
                return $this->error('PAYMENT_VALIDATION_FAILED', 'Refund amount exceeds remaining paid amount.', 422, $request);
            }

            return $this->error('PAYMENT_INVALID_TRANSITION', $e->getMessage(), 409, $request);
        }

        return response()->json([
            'refund_id' => Refund::query()->where('order_id', $updatedOrder->id)->latest('id')->value('id'),
            'payment_status' => $updatedOrder->payment_status,
            'access_status' => $updatedOrder->access_status,
            'status' => $updatedOrder->payment_status === 'refunded' ? 'completed' : 'pending',
        ], 202);
    }

    public function webhook(
        Request $request,
        string $provider,
        PaymentProviderRegistry $registry,
        PaymentOutboxService $outbox,
        OrderSettlementService $settlements
    ): JsonResponse {
        if (strtolower($provider) === 'cloudpayments') {
            return $this->error('PAYMENT_CLOUDPAYMENTS_TYPED_WEBHOOK_REQUIRED', 'Use the CloudPayments Check/Pay/Fail/Refund endpoint.', 422, $request);
        }
        $allowlist = array_values(array_filter(array_map(
            static fn (string $ip): string => trim($ip),
            (array) config("services.payments.providers.{$provider}.webhook_allowed_ips", [])
        )));
        $remoteIp = (string) ($request->ip() ?? '');

        if ($allowlist === []) {
            return $this->error(
                'PAYMENT_WEBHOOK_SOURCE_POLICY_MISSING',
                'Webhook source allowlist is not configured for this provider.',
                403,
                $request
            );
        }

        if (! in_array($remoteIp, $allowlist, true)) {
            return $this->error('PAYMENT_WEBHOOK_FORBIDDEN_IP', 'Webhook source is not allowed.', 403, $request);
        }

        $adapter = $registry->forProvider($provider);
        if (! $adapter) {
            return $this->error('PAYMENT_PROVIDER_UNKNOWN', 'Payment provider is not configured for webhook processing.', 404, $request);
        }

        $signature = $request->header('X-Payment-Signature')
            ?: $request->input('SignatureValue')
            ?: $request->header('X-Content-HMAC')
            ?: $request->header('Content-HMAC');
        if (! $adapter->verifyWebhookSignature((string) $request->getContent(), $signature)) {
            return $this->error('PAYMENT_SIGNATURE_INVALID', 'Webhook signature is invalid.', 403, $request);
        }

        $payload = $request->all();
        if ($payload === [] && $request->getContent() !== '') {
            $payload = ['_raw_body' => (string) $request->getContent()];
        }
        $parsedEvent = $adapter->parseWebhookEvent($payload);
        $intent = $adapter->mapProviderStatus($parsedEvent);

        $eventId = (string) ($parsedEvent['provider_event_id'] ?? '');
        $eventType = (string) ($parsedEvent['event_type'] ?? '');
        $providerStatus = (string) ($parsedEvent['provider_status'] ?? 'unknown');
        $orderId = $request->input('order_id', $parsedEvent['order_id'] ?? null);
        $attemptId = $request->input('attempt_id', $parsedEvent['attempt_id'] ?? null);

        if ($eventId === '' || $eventType === '' || $eventType === 'unknown') {
            return $this->error('PAYMENT_VALIDATION_FAILED', 'Webhook payload is missing required identifiers.', 422, $request);
        }

        if (! $this->isPositiveIntegerLike($orderId)) {
            return $this->error('PAYMENT_VALIDATION_FAILED', 'Webhook payload must reference an order.', 422, $request);
        }

        $webhookAmount = $this->webhookInteger($request->input('amount', $parsedEvent['amount'] ?? null));
        $webhookCurrency = strtoupper(trim((string) $request->input('currency', $parsedEvent['currency'] ?? '')));

        if ($webhookAmount === null || $webhookCurrency === '') {
            return $this->error('PAYMENT_VALIDATION_FAILED', 'Webhook payload must include amount and currency.', 422, $request);
        }

        $targetStatus = $intent['target_payment_status'] ?? null;

        return DB::transaction(function () use ($request, $provider, $eventId, $eventType, $orderId, $attemptId, $webhookAmount, $webhookCurrency, $targetStatus, $providerStatus, $outbox, $adapter, $intent, $settlements, $parsedEvent): JsonResponse {
            /** @var Order|null $order */
            $order = Order::query()->whereKey((int) $orderId)->lockForUpdate()->first();
            if (! $order) {
                return $this->error('PAYMENT_ORDER_NOT_FOUND', 'Webhook order was not found.', 404, $request);
            }

            /** @var PaymentAttempt|null $attempt */
            $attempt = $attemptId !== null
                ? PaymentAttempt::query()->whereKey((int) $attemptId)->lockForUpdate()->first()
                : ($order->activeAttempt()->lockForUpdate()->first());
            if (! $attempt) {
                return $this->error('PAYMENT_ATTEMPT_NOT_FOUND', 'Webhook payment attempt was not found.', 404, $request);
            }

            if ((int) $attempt->order_id !== (int) $order->id) {
                return $this->error('PAYMENT_VALIDATION_FAILED', 'Webhook payment attempt does not belong to the order.', 422, $request);
            }

            if ((string) $attempt->provider !== $adapter->provider()) {
                return $this->error('PAYMENT_VALIDATION_FAILED', 'Webhook payment attempt provider does not match.', 422, $request);
            }

            if ($order->active_attempt_id !== null && (int) $order->active_attempt_id !== (int) $attempt->id) {
                return $this->error('PAYMENT_VALIDATION_FAILED', 'Webhook payment attempt is not active for the order.', 422, $request);
            }

            if ((int) $attempt->amount !== $webhookAmount || strtoupper((string) $attempt->currency) !== $webhookCurrency) {
                return $this->error('PAYMENT_VALIDATION_FAILED', 'Webhook amount or currency does not match the payment attempt.', 422, $request);
            }

            $providerPaymentId = trim((string) $request->input('provider_payment_id', $parsedEvent['provider_payment_id'] ?? ''));
            if ($providerPaymentId !== '' && $providerPaymentId !== (string) $attempt->provider_payment_id) {
                return $this->error('PAYMENT_VALIDATION_FAILED', 'Webhook provider payment id does not match the payment attempt.', 422, $request);
            }

            $event = ProviderEvent::query()->createOrFirst(
                [
                    'provider' => $provider,
                    'provider_event_id' => $eventId,
                ],
                [
                    'event_type' => $eventType,
                    'order_id' => $order->id,
                    'attempt_id' => $attempt->id,
                    'signature_valid' => true,
                    'processed' => false,
                    'payload_raw' => $request->all(),
                    'received_at' => now(),
                ]
            );
            /** @var ProviderEvent $event */
            $event = ProviderEvent::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();

            if (
                (string) $event->event_type !== $eventType
                || (int) $event->order_id !== (int) $order->id
                || (int) $event->attempt_id !== (int) $attempt->id
            ) {
                return $this->error(
                    'PAYMENT_WEBHOOK_IDEMPOTENCY_DRIFT',
                    'Webhook event id was already received with different routing fields.',
                    409,
                    $request
                );
            }

            if ($event->processed) {
                return response()->json([
                    'status' => 'accepted',
                    'duplicate' => true,
                ]);
            }

            if ($targetStatus === null) {
                PaymentAuditLog::query()->create([
                    'order_id' => $order->id,
                    'attempt_id' => $attempt->id,
                    'event_id' => $event->id,
                    'action' => 'webhook_rejected',
                    'from_status' => $order->payment_status,
                    'to_status' => $order->payment_status,
                    'actor_type' => 'provider',
                    'reason' => 'OUT_OF_ORDER',
                    'meta' => [
                        'provider_status' => $providerStatus,
                        'diagnostic_code' => (string) ($intent['diagnostic_code'] ?? 'OUT_OF_ORDER'),
                    ],
                ]);

                $event->processed = true;
                $event->processed_at = now();
                $event->save();

                return response()->json([
                    'status' => 'accepted',
                    'duplicate' => false,
                ]);
            }

            $fromStatus = $order->payment_status;
            $stateMachine = app(PaymentStateMachine::class);

            if ($fromStatus !== $targetStatus && ! $stateMachine->canTransition($fromStatus, $targetStatus)) {
                PaymentAuditLog::query()->create([
                    'order_id' => $order->id,
                    'attempt_id' => $attempt->id,
                    'event_id' => $event->id,
                    'action' => 'webhook_rejected',
                    'from_status' => $fromStatus,
                    'to_status' => $fromStatus,
                    'actor_type' => 'provider',
                    'reason' => 'INVALID_TRANSITION',
                    'meta' => [
                        'provider_status' => $providerStatus,
                        'provider' => $adapter->provider(),
                        'diagnostic_code' => (string) ($intent['diagnostic_code'] ?? 'UNKNOWN'),
                    ],
                ]);

                $event->processed = true;
                $event->processed_at = now();
                $event->save();

                return response()->json([
                    'status' => 'accepted',
                    'duplicate' => false,
                ]);
            }

            if ($targetStatus === 'paid' && $fromStatus !== 'paid') {
                $settlement = $settlements->confirmPaid($order, [
                    'idempotency_key' => "provider_event:{$provider}:{$eventId}",
                    'amount' => $webhookAmount,
                    'currency' => $webhookCurrency,
                    'settlement_type' => OrderSettlement::TYPE_PROVIDER_PAYMENT,
                    'payment_attempt_id' => (int) $attempt->id,
                    'provider_event_id' => (int) $event->id,
                    'metadata' => [
                        'provider' => $adapter->provider(),
                        'provider_status' => $providerStatus,
                        'diagnostic_code' => (string) ($intent['diagnostic_code'] ?? 'UNKNOWN'),
                    ],
                ]);
                $order = $settlement->order()->firstOrFail();

                $outbox->publishTransition($order->fresh(), (int) $attempt->id, null, $fromStatus, $targetStatus, [
                    'source' => 'provider_webhook',
                    'provider_status' => $providerStatus,
                    'provider' => $adapter->provider(),
                ]);
            } elseif ($fromStatus !== $targetStatus && $stateMachine->canTransition($fromStatus, $targetStatus)) {
                $stateMachine->transition($order, $targetStatus, 'not_granted');
                $outbox->publishTransition($order->fresh(), (int) $attempt->id, null, $fromStatus, $targetStatus, [
                    'source' => 'provider_webhook',
                    'provider_status' => $providerStatus,
                    'provider' => $adapter->provider(),
                ]);
            }

            $attempt->status = (string) ($intent['target_attempt_status'] ?? ($targetStatus === 'paid' ? 'completed' : 'cancelled'));
            $attempt->completed_at = $targetStatus === 'paid' ? now() : null;
            $attempt->provider_substatus = [
                'provider_status' => $providerStatus,
                'diagnostic_code' => (string) ($intent['diagnostic_code'] ?? 'UNKNOWN'),
            ];
            $attempt->save();

            PaymentAuditLog::query()->create([
                'order_id' => $order->id,
                'attempt_id' => $attempt->id,
                'event_id' => $event->id,
                'action' => 'status_transition',
                'from_status' => $fromStatus,
                'to_status' => $order->fresh()->payment_status,
                'actor_type' => 'provider',
                'meta' => [
                    'provider_status' => $providerStatus,
                    'provider' => $adapter->provider(),
                    'diagnostic_code' => (string) ($intent['diagnostic_code'] ?? 'UNKNOWN'),
                ],
            ]);

            $event->processed = true;
            $event->processed_at = now();
            $event->save();

            return response()->json([
                'status' => 'accepted',
                'duplicate' => false,
            ]);
        });
    }

    public function replayEvent(Request $request, ProviderEvent $event): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:1024'],
            'incident_id' => ['required', 'string', 'max:128'],
        ]);

        if ($event->order_id === null) {
            return $this->error('PAYMENT_ORDER_NOT_FOUND', 'Replay requires event linked to order.', 404, $request);
        }

        $event->processed = false;
        $event->save();

        PaymentAuditLog::query()->create([
            'order_id' => $event->order_id,
            'attempt_id' => $event->attempt_id,
            'event_id' => $event->id,
            'action' => 'event_replay_requested',
            'actor_type' => 'staff',
            'actor_id' => $request->user()?->id,
            'reason' => $validated['reason'],
            'meta' => ['incident_id' => $validated['incident_id']],
        ]);

        return response()->json([
            'status' => 'accepted',
        ], 202);
    }

    public function cloudpaymentsWebhook(
        Request $request,
        string $type,
        CloudpaymentsWebhookHandler $handler
    ): JsonResponse {
        $type = strtolower($type);
        if (! in_array($type, self::CLOUDPAYMENTS_WEBHOOK_TYPES, true)) {
            return response()->json(['code' => 13]);
        }
        $allowlist = (array) config('services.payments.providers.cloudpayments.webhook_allowed_ips', []);
        if ($allowlist === [] || ! IpUtils::checkIp((string) $request->ip(), $allowlist)
            || ! $this->isValidCloudpaymentsSignature($request)) {
            return response()->json(['code' => 13]);
        }

        // Only signed body fields may affect a payment, never unsigned query parameters.
        $payload = $request->isJson() ? $request->json()->all() : $request->request->all();

        return response()->json(['code' => $handler->handle($type, $payload)]);
    }

    private function error(string $code, string $message, int $status, Request $request): JsonResponse
    {
        $requestId = (string) ($request->header('X-Request-Id') ?: Str::uuid());

        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
                'request_id' => $requestId,
            ],
        ], $status);
    }

    private function isPositiveIntegerLike(mixed $value): bool
    {
        if (is_int($value)) {
            return $value > 0;
        }

        if (! is_string($value) || $value === '' || ! ctype_digit($value)) {
            return false;
        }

        return (int) $value > 0;
    }

    private function webhookInteger(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && $value !== '' && ctype_digit($value)) {
            return (int) $value;
        }

        return null;
    }

    private function isValidCloudpaymentsSignature(Request $request): bool
    {
        $secret = (string) config('services.payments.providers.cloudpayments.webhook_secret', '');
        if ($secret === '') {
            return false;
        }

        $signature = (string) ($request->header('X-Content-HMAC') ?: $request->header('Content-HMAC') ?: '');
        if ($signature === '') {
            return false;
        }

        $raw = (string) $request->getContent();
        $decoded = urldecode($raw);
        $expectedRaw = base64_encode(hash_hmac('sha256', $raw, $secret, true));
        $expectedDecoded = base64_encode(hash_hmac('sha256', $decoded, $secret, true));

        return hash_equals($expectedRaw, $signature) || hash_equals($expectedDecoded, $signature);
    }
}
