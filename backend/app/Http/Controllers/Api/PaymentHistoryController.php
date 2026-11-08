<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Refund;
use App\Models\SideEffectExecution;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentHistoryController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $input = $request->validate(['before_id' => ['nullable', 'integer', 'min:1']]);
        $orders = Order::query()->where('user_id', $request->user()->id)
            ->when(isset($input['before_id']), fn ($query) => $query->where('id', '<', $input['before_id']))
            ->with('items')->orderByDesc('id')->limit(21)->get();
        $hasMore = $orders->count() > 20;
        $orders = $orders->take(20);
        $refunds = Refund::query()->whereIn('order_id', $orders->modelKeys())->orderBy('id')->get()->groupBy('order_id');
        $receipts = SideEffectExecution::query()->whereIn('order_id', $orders->modelKeys())
            ->whereIn('handler', ['receipt.income.issue', 'receipt.refund.issue'])->orderBy('id')->get()->groupBy('order_id');

        return response()->json([
            'orders' => $orders->map(fn (Order $order): array => [
                'id' => $order->id, 'created_at' => $order->created_at->toIso8601String(),
                'titles' => $order->items->pluck('display_title')->values(),
                'currency' => $order->currency, 'amount' => (int) $order->amount_final,
                'paid' => (int) $order->amount_paid, 'refunded' => (int) $order->amount_refunded,
                'payment_status' => $order->payment_status, 'access_status' => $order->access_status,
                'refunds' => ($refunds[$order->id] ?? collect())->map(fn (Refund $refund): array => [
                    'id' => $refund->id, 'amount' => (int) $refund->amount, 'status' => $refund->status,
                ])->values(),
                'receipts' => ($receipts[$order->id] ?? collect())->map(fn (SideEffectExecution $receipt): array => [
                    'kind' => $receipt->handler === 'receipt.income.issue' ? 'income' : 'refund',
                    'status' => $receipt->status === 'completed' && ($receipt->meta['verified'] ?? false) === true
                        && ! empty($receipt->meta['delivery_version']) ? 'issued' : 'pending_verification',
                ])->values(),
            ])->values(),
            'next_before_id' => $hasMore ? $orders->last()->id : null,
        ])->header('Cache-Control', 'private, no-store');
    }
}
