<?php

namespace App\Services\Payments;

use App\Models\Order;
use App\Models\PaymentAuditLog;
use App\Models\PromoCode;
use App\Models\PromoReservation;
use App\Models\Refund;
use App\Models\SideEffectExecution;
use App\Services\Bonuses\PromoReservationService;
use App\Services\Bonuses\PurchaseRewardService;
use DomainException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class PaymentNotificationService
{
    public function __construct(private readonly PaymentNotificationTransport $transport) {}

    public function execute(int $id): void
    {
        if ($this->configured() && DB::transactionLevel() !== 0) {
            throw new DomainException('PAYMENT_NOTIFICATION_TRANSACTION_BOUNDARY_INVALID');
        }
        $claim = DB::transaction(function () use ($id): ?array {
            $effect = SideEffectExecution::query()->lockForUpdate()->findOrFail($id);
            $meta = $effect->meta ?? [];
            if (empty($meta['delivery_version']) || in_array($effect->status, ['completed', 'unknown', 'manual_review'], true)) {
                return null;
            }
            if ($effect->status === 'submitting') {
                if (now()->timestamp >= (int) ($meta['claim_until'] ?? 0)) {
                    $effect->update(['status' => 'unknown', 'processed_at' => now(),
                        'meta' => [...$meta, 'diagnostic' => 'NOTIFICATION_EXPIRED_CLAIM']]);
                }

                return null;
            }
            if (! $this->configured()) {
                $effect->update(['status' => 'blocked_configuration', 'processed_at' => now()]);

                return null;
            }
            if ($effect->handler === 'user_notification.purchase_promo') {
                try {
                    $current = $this->promoPayload($effect);
                } catch (Throwable) {
                    $effect->update(['status' => 'manual_review', 'meta' => [...$meta, 'diagnostic' => 'PROMO_NOTIFICATION_EVIDENCE_INVALID']]);

                    return null;
                }
                if (isset($meta['payload']) && $meta['payload'] !== $current) {
                    $effect->update(['status' => 'manual_review', 'meta' => [...$meta, 'diagnostic' => 'PROMO_NOTIFICATION_PAYLOAD_CHANGED']]);

                    return null;
                }
                $meta['payload'] = $current;
                $meta['payload_hash'] = hash('sha256', json_encode($current, JSON_THROW_ON_ERROR));
            }
            if (! isset($meta['payload'])) {
                $payload = $this->payload($effect);
                if ($payload === null) {
                    $effect->update(['status' => 'blocked_notification_data', 'processed_at' => now()]);

                    return null;
                }
                $meta['payload'] = $payload;
                $meta['payload_hash'] = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
            }
            if (! hash_equals((string) ($meta['payload_hash'] ?? ''), hash('sha256', json_encode($meta['payload'], JSON_THROW_ON_ERROR)))
                || $meta['payload']['mailer'] !== (string) config('payment_notifications.mailer')) {
                $effect->update(['status' => 'manual_review', 'processed_at' => now()]);

                return null;
            }
            $meta['claim_token'] = (string) Str::uuid();
            $meta['claim_until'] = now()->addMinutes(2)->timestamp;
            $effect->update(['status' => 'submitting', 'meta' => $meta, 'processed_at' => now()]);

            return $meta;
        });
        if ($claim === null) {
            return;
        }
        $sendPayload = $claim['payload'];
        $preparationFailed = false;
        if (($sendPayload['kind'] ?? null) === 'purchase_promo') {
            try {
                $effect = SideEffectExecution::query()->findOrFail($id);
                if ($this->promoPayload($effect) !== $sendPayload) {
                    throw new DomainException('PROMO_NOTIFICATION_PAYLOAD_CHANGED');
                }
                $issuance = PaymentAuditLog::query()->findOrFail($sendPayload['issuance_id']);
                $code = Crypt::decryptString($issuance->meta['encrypted_code']);
                if (! hash_equals($sendPayload['code_hash'], PromoReservationService::codeHash($code))) {
                    throw new DomainException('PROMO_NOTIFICATION_CODE_MISMATCH');
                }
                $sendPayload['body'] = 'Ваш промокод на следующую покупку: '.$code.'.'
                    ."\nСкидка: ".($sendPayload['discount_bps'] / 100).'%. Действует до '.$sendPayload['expires_at'].'.';
            } catch (Throwable) {
                $claim['diagnostic'] = 'PROMO_NOTIFICATION_EVIDENCE_INVALID';
                $preparationFailed = true;
            }
        }
        try {
            if ($preparationFailed) {
                $status = 'manual_review';
            } else {
                $claim['transport_message_id'] = $this->transport->send($sendPayload);
                $claim['acceptance'] = 'transport_accepted';
                $claim['accepted_at'] = now()->toIso8601String();
                $status = 'completed';
            }
        } catch (Throwable) {
            // SMTP can accept the message before the connection is lost.
            // A stable Message-ID does not guarantee recipient-side deduplication.
            $claim['diagnostic'] = 'NOTIFICATION_ACCEPTANCE_UNKNOWN';
            $status = 'unknown';
        }
        DB::transaction(function () use ($id, $claim, $status): void {
            $effect = SideEffectExecution::query()->lockForUpdate()->findOrFail($id);
            if ($effect->status !== 'submitting' || ($effect->meta['claim_token'] ?? null) !== $claim['claim_token']) {
                return;
            }
            unset($claim['claim_until']);
            // Existing schema uses processed_at as the last execution time, not delivery evidence.
            $effect->update(['status' => $status, 'meta' => $claim, 'processed_at' => now()]);
        });
    }

    /** Durable payload contains references only. The redeemable code is never persisted here. */
    private function promoPayload(SideEffectExecution $effect): array
    {
        $order = Order::query()->with('user')->findOrFail($effect->order_id);
        $source = SideEffectExecution::query()->where('dedup_key', "order:{$order->id}:bonus:reward")->first();
        $markers = PaymentAuditLog::query()->where('order_id', $order->id)->where('action', 'auto_purchase_promo_applied')->get();
        $issuance = $markers->count() === 1 ? $markers->sole() : null;
        $promo = PromoCode::query()->find($effect->meta['promo_code_id'] ?? null);
        $settlement = app(PurchaseRewardService::class)->confirmedSettlement($order);
        $email = $order->user?->email;
        $fingerprint = hash('sha256', json_encode([(int) $order->id, (int) $order->user_id, (int) $settlement->id,
            (int) $settlement->amount, $settlement->currency, $settlement->confirmed_at->toISOString()], JSON_THROW_ON_ERROR));
        if ($effect->handler !== 'user_notification.purchase_promo' || $effect->refund_id !== null
            || $effect->dedup_key !== "order:{$order->id}:notify:purchase_promo"
            || ($effect->meta['delivery_version'] ?? null) !== 1 || $source === null
            || $source->handler !== 'bonus.purchase_reward' || $source->status !== 'completed' || ($source->meta['verified'] ?? null) !== true
            || ($source->meta['delivery_version'] ?? null) !== 1 || ($source->meta['results'][1]['status'] ?? null) !== 'issued'
            || $source->order_id !== $effect->order_id || $source->outbox_event_id !== $effect->outbox_event_id
            || $source->attempt_id !== $effect->attempt_id || $source->refund_id !== null
            || $order->payment_status !== 'paid' || ($settlement->metadata['grant_status'] ?? null) !== 'granted'
            || $settlement->payment_attempt_id !== $effect->attempt_id || $issuance === null || $promo === null
            || ($issuance->meta['version'] ?? null) !== 1 || ($issuance->meta['fingerprint'] ?? null) !== $fingerprint
            || ($issuance->meta['result']['status'] ?? null) !== 'issued'
            || ($issuance->meta['result']['promo_code_id'] ?? null) !== (int) $promo->id
            || ($source->meta['results'][1]['promo_code_id'] ?? null) !== (int) $promo->id
            || ($issuance->meta['code_hash'] ?? null) !== $promo->code_hash
            || ! is_string($issuance->meta['encrypted_code'] ?? null)
            || $promo->status !== 'active' || $promo->expires_at->lessThanOrEqualTo(now())
            || $promo->starts_at?->isFuture() || $promo->rules_version !== 'auto-cash-promo-v1'
            || $promo->discount_kind !== 'percent_bps' || $promo->currency !== 'RUB'
            || ($issuance->meta['result']['discount_bps'] ?? null) !== $promo->discount_bps
            || ($issuance->meta['result']['expires_at'] ?? null) !== $promo->expires_at->toIso8601String()
            || ! is_string($email) || ! filter_var($email, FILTER_VALIDATE_EMAIL)
            || $promo->bound_email_hash !== hash('sha256', mb_strtolower(trim($email)))
            || PromoReservation::query()->where('promo_code_id', $promo->id)->where('status', 'committed')->exists()) {
            throw new DomainException('PROMO_NOTIFICATION_EVIDENCE_INVALID');
        }
        $sender = (string) config('mail.from.address');

        return ['kind' => 'purchase_promo', 'recipient' => $email, 'sender' => $sender,
            'subject' => 'Промокод на следующую покупку', 'issuance_id' => (int) $issuance->id,
            'promo_code_id' => (int) $promo->id, 'code_hash' => $promo->code_hash,
            'cipher_hash' => hash('sha256', $issuance->meta['encrypted_code']),
            'discount_bps' => $promo->discount_bps, 'expires_at' => $promo->expires_at->toIso8601String(),
            'mailer' => (string) config('payment_notifications.mailer'),
            'message_id' => 'payment-'.hash('sha256', $effect->dedup_key).'@'.substr(strrchr($sender, '@'), 1),
            'version' => (string) config('payment_notifications.version')];
    }

    private function configured(): bool
    {
        $mailer = (string) config('payment_notifications.mailer');
        $sender = (string) config('mail.from.address');
        $timeout = config('mail.mailers.'.$mailer.'.timeout');

        return config('payment_notifications.enabled') === true
            && config('mail.mailers.'.$mailer.'.transport') === 'smtp'
            && is_numeric($timeout) && $timeout > 0 && $timeout <= 30
            && filter_var($sender, FILTER_VALIDATE_EMAIL) !== false
            && ! str_ends_with(strtolower($sender), '@example.com');
    }

    private function payload(SideEffectExecution $effect): ?array
    {
        $order = Order::query()->with('user')->find($effect->order_id);
        $recipient = $order?->user?->email;
        if (! $order || ! is_string($recipient) || ! filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            return null;
        }
        $subject = match ($effect->handler) {
            'user_notification.payment_success' => 'Оплата заказа подтверждена',
            'user_notification.refund_processed' => 'Возврат по заказу подтверждён',
            'user_notification.payment_cancelled' => 'Попытка оплаты не завершилась',
            default => null,
        };
        if ($subject === null) {
            return null;
        }
        $amount = (int) $order->amount_paid;
        if ($effect->handler === 'user_notification.refund_processed') {
            $refund = Refund::query()->where('order_id', $order->id)->where('status', 'completed')->find($effect->refund_id);
            if (! $refund) {
                return null;
            }
            $amount = (int) $refund->amount;
        } elseif ($effect->handler === 'user_notification.payment_success' && $order->paid_at === null) {
            return null;
        }
        $body = $subject.'. Заказ №'.$order->id.'.';
        if ($effect->handler !== 'user_notification.payment_cancelled') {
            $body .= "\nСумма: ".number_format($amount / 100, 2, ',', ' ').' '.$order->currency.'.';
        }
        $body .= "\nАктуальный статус заказа доступен в личном кабинете Christmedschool.";
        $sender = (string) config('mail.from.address');

        return ['recipient' => $recipient, 'sender' => $sender, 'subject' => $subject, 'body' => $body,
            'mailer' => (string) config('payment_notifications.mailer'),
            'message_id' => 'payment-'.hash('sha256', $effect->dedup_key).'@'.substr(strrchr($sender, '@'), 1),
            'version' => (string) config('payment_notifications.version')];
    }
}
