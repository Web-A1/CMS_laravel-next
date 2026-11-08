<?php

namespace App\Contracts\Payments;

interface PaymentProviderAdapter
{
    public function provider(): string;

    public function verifyWebhookSignature(string $rawPayload, ?string $signature): bool;

    /**
     * @param array{
     *   order_id:int,
     *   attempt_id:int,
     *   amount:int,
     *   currency:string,
     *   return_url:string,
     *   cancel_url:string
     * } $payload
     * @return array{
     *   provider_payment_id:?string,
     *   provider_payment_url:?string,
     *   provider_substatus:array<string,mixed>,
     *   expires_at:?string
     * }
     */
    public function startPayment(array $payload): array;

    /**
     * @return array{
     *   provider_event_id:string,
     *   event_type:string,
     *   provider_status:string,
     *   raw_payload:array<string,mixed>
     * }
     */
    public function parseWebhookEvent(array $payload): array;

    /**
     * @param array{
     *   provider_event_id:string,
     *   event_type:string,
     *   provider_status:string,
     *   raw_payload:array<string,mixed>
     * } $event
     * @return array{
     *   target_payment_status:?string,
     *   target_attempt_status:string,
     *   is_terminal:bool,
     *   diagnostic_code:string
     * }
     */
    public function mapProviderStatus(array $event): array;

    /**
     * @param array{
     *   order_id:int,
     *   attempt_id:?int,
     *   refund_type:string,
     *   amount:int,
     *   reason:string
     * } $payload
     * @return array{
     *   provider_refund_id:string,
     *   status:string,
     *   provider_substatus:array<string,mixed>
     * }
     */
    public function refund(array $payload): array;
}
