<?php

namespace Tests\Feature\Payments;

use App\Models\Course;
use App\Models\Lesson;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\Refund;
use App\Models\Subject;
use App\Models\User;
use App\Services\OrderItemSnapshotService;
use App\Services\Payments\PaymentAttemptCreator;
use App\Services\Payments\YandexSplitClient;
use App\Services\Payments\YandexSplitPaymentProviderAdapter;
use App\Services\Payments\YandexSplitRefundService;
use App\Services\Payments\YandexSplitWebhookHandler;
use App\Services\PaymentStateMachine;
use DomainException;
use Firebase\JWT\JWT;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class YandexSplitIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Remote dispatch tests require real commit boundaries, not the transaction
        // wrapper of RefreshDatabase. Rebuild only the isolated in-memory database.
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        config(['yandex_split.enabled' => true, 'yandex_split.sandbox' => true,
            'yandex_split.merchant_id' => 'merchant-test', 'yandex_split.api_key' => '',
            'yandex_split.return_url_hosts' => ['staging.example.test']]);
        Cache::flush();
        Queue::fake();
        Http::preventStrayRequests();
    }

    public function test_disabled_provider_never_calls_network(): void
    {
        config(['yandex_split.enabled' => false]);
        try {
            app(YandexSplitClient::class)->getOrder('cms-1-1');
            $this->fail('Expected disabled configuration');
        } catch (DomainException $e) {
            $this->assertSame('PAYMENT_PROVIDER_NOT_CONFIGURED', $e->getMessage());
        }
        Http::assertNothingSent();
    }

    public function test_create_uses_snapshot_exact_money_split_only_and_sandbox_merchant_auth(): void
    {
        Http::fake(['sandbox.pay.yandex.ru/api/merchant/v1/orders' => Http::response([
            'status' => 'success', 'data' => ['paymentUrl' => 'https://sandbox.pay.yandex.ru/pay/123'],
        ])]);
        $result = (new YandexSplitPaymentProviderAdapter('yandex_split', ''))->startPayment($this->startPayload());
        $this->assertSame('cms-1-2', $result['provider_payment_id']);
        Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Api-Key merchant-test')
            && $r['orderId'] === 'cms-1-2' && $r['cart']['total']['amount'] === '100.01'
            && $r['cart']['items'][0]['title'] === 'Purchased service'
            && $r['availablePaymentMethods'] === ['SPLIT'] && ! isset($r['cart']['items'][0]['receipt']));
    }

    public function test_untrusted_return_and_provider_urls_rejected(): void
    {
        $payload = $this->startPayload();
        $payload['return_url'] = 'https://attacker.example/steal';
        try {
            (new YandexSplitPaymentProviderAdapter('yandex_split', ''))->startPayment($payload);
            $this->fail('Expected rejected URL');
        } catch (DomainException $e) {
            $this->assertSame('PAYMENT_YANDEX_RETURN_URL_INVALID', $e->getMessage());
        }
        Http::assertNothingSent();
        Http::fake(['*' => Http::response(['status' => 'success', 'data' => ['paymentUrl' => 'https://attacker.example/pay']])]);
        $this->expectExceptionMessage('PAYMENT_YANDEX_PAYMENT_URL_INVALID');
        (new YandexSplitPaymentProviderAdapter('yandex_split', ''))->startPayment($this->startPayload());
    }

    public function test_jwt_uses_header_times_merchant_and_rotated_jwks(): void
    {
        [$private, $jwk] = $this->key('new-key');
        Cache::put('payments:yandex:jwks:'.hash('sha256', 'https://sandbox.pay.yandex.ru'), ['keys' => []], 600);
        Http::fake(['sandbox.pay.yandex.ru/api/jwks' => Http::response(['keys' => [$jwk]])]);
        $token = JWT::encode(['merchantId' => 'merchant-test', 'event' => 'ORDER_STATUS_UPDATED'], $private, 'ES256', 'new-key', [
            'iat' => (string) time(), 'exp' => (string) (time() + 300),
        ]);
        $this->assertSame('merchant-test', app(YandexSplitClient::class)->verifyToken($token)['merchantId']);
        Http::assertSentCount(1);
        app(YandexSplitClient::class)->verifyToken($token);
        Http::assertSentCount(1);
    }

    public function test_jwt_rejects_wrong_merchant_expired_header_and_untrusted_key_url(): void
    {
        [$private, $jwk] = $this->key();
        Http::fake(['sandbox.pay.yandex.ru/api/jwks' => Http::response(['keys' => [$jwk]])]);
        foreach ([
            [['merchantId' => 'other'], ['iat' => time(), 'exp' => time() + 300]],
            [['merchantId' => 'merchant-test'], ['iat' => time() - 400, 'exp' => time() - 1]],
            [['merchantId' => 'merchant-test'], ['iat' => time(), 'exp' => time() + 300, 'jku' => 'https://attacker.test/key']],
        ] as [$claims, $headers]) {
            try {
                app(YandexSplitClient::class)->verifyToken(JWT::encode($claims, $private, 'ES256', 'test-key', $headers));
                $this->fail('Expected rejected JWT');
            } catch (DomainException $e) {
                $this->assertSame('PAYMENT_YANDEX_UNAUTHORIZED', $e->getMessage());
            }
        }
    }

    public function test_signed_callback_readback_confirms_payment_once_and_late_failure_cannot_revoke(): void
    {
        [$order, $attempt] = $this->fixture();
        [$private, $jwk] = $this->key();
        $remote = $this->remote($attempt, 'CAPTURED');
        Http::fake([
            'sandbox.pay.yandex.ru/api/jwks' => Http::response(['keys' => [$jwk]]),
            'sandbox.pay.yandex.ru/api/merchant/v1/orders/*' => Http::sequence()
                ->push(['status' => 'success', 'data' => ['order' => $remote]])
                ->push(['status' => 'success', 'data' => ['order' => $remote]])
                ->push(['status' => 'success', 'data' => ['order' => $this->remote($attempt, 'FAILED')]]),
        ]);
        $token = JWT::encode(['merchantId' => 'merchant-test', 'event' => 'ORDER_STATUS_UPDATED',
            'eventTime' => now()->toIso8601String(), 'order' => ['orderId' => $attempt->provider_payment_id, 'paymentStatus' => 'CAPTURED']],
            $private, 'ES256', 'test-key', ['iat' => time(), 'exp' => time() + 300]);
        app(YandexSplitWebhookHandler::class)->handle($token);
        app(YandexSplitWebhookHandler::class)->handle($token);
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertDatabaseCount('order_settlements', 1);
        $this->assertDatabaseCount('user_entitlement_grants', 1);
        app(YandexSplitWebhookHandler::class)->reconcile($attempt->fresh());
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertSame('granted', $order->fresh()->access_status);
    }

    public function test_readback_mismatch_never_settles_even_if_callback_was_valid(): void
    {
        [$order, $attempt] = $this->fixture();
        $remote = $this->remote($attempt, 'CAPTURED');
        $remote['orderAmount'] = '999.99';
        Http::fake(['*' => Http::response(['status' => 'success', 'data' => ['order' => $remote]])]);
        try {
            app(YandexSplitWebhookHandler::class)->reconcile($attempt);
            $this->fail('Expected mismatch');
        } catch (DomainException $e) {
            $this->assertSame('PAYMENT_YANDEX_ORDER_MISMATCH', $e->getMessage());
        }
        $this->assertSame('pending_payment', $order->fresh()->payment_status);
        $this->assertDatabaseCount('order_settlements', 0);
    }

    public function test_refund_reserves_until_operation_success_and_duplicate_does_not_double_count(): void
    {
        [$order, $attempt] = $this->paidFixture();
        $key = (string) Str::uuid();
        $operation = ['operationId' => 'operation-1', 'externalOperationId' => $key,
            'orderId' => $attempt->provider_payment_id, 'operationType' => 'REFUND', 'amount' => '100.01', 'status' => 'PENDING'];
        Http::fake(['sandbox.pay.yandex.ru/api/merchant/v2/orders/*/refund' => Http::response([
            'status' => 'success', 'data' => ['operation' => $operation],
        ])]);
        $service = app(YandexSplitRefundService::class);
        $refund = $service->request($order, ['refund_type' => 'full', 'reason' => 'Test return', 'policy_access_action' => 'revoke'], $key, (int) $order->user_id);
        $service->request($order, ['refund_type' => 'full', 'reason' => 'Test return', 'policy_access_action' => 'revoke'], $key, (int) $order->user_id);
        Http::assertSentCount(1);
        $this->assertSame('submitted', $refund->status);
        $this->assertSame(0, (int) $order->fresh()->amount_refunded);
        $operation['status'] = 'SUCCESS';
        $service->applyOperation($attempt, $operation);
        $service->applyOperation($attempt, $operation);
        $this->assertSame(10001, (int) $order->fresh()->amount_refunded);
        $this->assertSame('revoked', $order->fresh()->access_status);
        $this->assertDatabaseCount('refunds', 1);
    }

    public function test_refund_timeout_stays_unknown_and_is_not_resent(): void
    {
        [$order] = $this->paidFixture();
        Http::fake(['*' => Http::failedConnection()]);
        $key = (string) Str::uuid();
        $service = app(YandexSplitRefundService::class);
        $input = ['refund_type' => 'full', 'reason' => 'Test', 'policy_access_action' => 'revoke'];
        $refund = $service->request($order, $input, $key, (int) $order->user_id);
        $this->assertSame('unknown', $refund->status);
        $service->request($order, $input, $key, (int) $order->user_id);
        $this->assertSame(0, (int) $order->fresh()->amount_refunded);
        $this->assertSame('granted', $order->fresh()->access_status);
        $this->assertDatabaseCount('refunds', 1);
    }

    public function test_external_partial_is_accounted_without_revoking_whole_service(): void
    {
        [$order, $attempt] = $this->paidFixture();
        app(YandexSplitRefundService::class)->applyOperation($attempt, [
            'operationId' => 'external-partial', 'orderId' => $attempt->provider_payment_id,
            'operationType' => 'REFUND', 'amount' => '10.00', 'status' => 'SUCCESS',
        ]);
        $this->assertSame(1000, (int) $order->fresh()->amount_refunded);
        $this->assertSame('granted', $order->fresh()->access_status);
        $this->assertDatabaseHas('payment_audit_log', ['action' => 'partial_service_refund_review']);
    }

    public function test_partial_request_is_rejected_without_http(): void
    {
        [$order] = $this->fixture();
        try {
            app(YandexSplitRefundService::class)->request($order, [
                'refund_type' => 'partial', 'policy_access_action' => 'revoke', 'reason' => 'Test',
            ], (string) Str::uuid(), (int) $order->user_id);
            $this->fail('Expected whole service contract');
        } catch (DomainException $exception) {
            $this->assertSame('PAYMENT_REFUND_WHOLE_SERVICE_REQUIRED', $exception->getMessage());
        }
        Http::assertNothingSent();
    }

    public function test_refund_io_is_outside_reservation_transaction_and_mismatched_response_is_unknown(): void
    {
        [$order, $attempt] = $this->paidFixture();
        $level = DB::transactionLevel();
        Http::fake(['sandbox.pay.yandex.ru/api/merchant/v2/orders/*/refund' => function () use ($level, $attempt) {
            $this->assertSame($level, DB::transactionLevel());
            $this->assertDatabaseHas('refunds', ['status' => 'pending']);

            return Http::response(['status' => 'success', 'data' => ['operation' => [
                'operationId' => 'wrong-operation', 'externalOperationId' => 'wrong-key', 'operationType' => 'REFUND',
                'orderId' => $attempt->provider_payment_id, 'amount' => '100.01', 'status' => 'SUCCESS',
            ]]]);
        }]);
        $refund = app(YandexSplitRefundService::class)->request($order, [
            'refund_type' => 'full', 'policy_access_action' => 'revoke', 'reason' => 'Test',
        ], (string) Str::uuid(), (int) $order->user_id);
        $this->assertSame('unknown', $refund->status);
        $this->assertSame(0, (int) $order->fresh()->amount_refunded);
    }

    public function test_operation_readback_uses_external_key_and_recovers_unknown_without_refund_post(): void
    {
        [$order, $attempt] = $this->paidFixture();
        $key = (string) Str::uuid();
        $refund = Refund::query()->create(['order_id' => $order->id, 'attempt_id' => $attempt->id,
            'refund_type' => 'full', 'amount' => 10001, 'currency' => 'RUB', 'status' => 'unknown',
            'reason' => 'Test', 'policy_access_action' => 'revoke', 'request_key' => $key]);
        Http::fake(['sandbox.pay.yandex.ru/api/merchant/v1/operations/'.$key => Http::response([
            'status' => 'success', 'data' => ['operation' => ['operationId' => 'recovered', 'externalOperationId' => $key,
                'orderId' => $attempt->provider_payment_id, 'amount' => '100.01', 'operationType' => 'REFUND', 'status' => 'SUCCESS']],
        ])]);
        $result = app(YandexSplitRefundService::class)->reconcile($refund);
        $this->assertSame('completed', $result->status);
        $this->assertSame('refunded', $order->fresh()->payment_status);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    private function startPayload(): array
    {
        return ['order_id' => 1, 'attempt_id' => 2, 'amount' => 10001, 'currency' => 'RUB',
            'return_url' => 'https://staging.example.test/return', 'cancel_url' => 'https://staging.example.test/cancel',
            'items' => [['sellable_sku' => 'course:test', 'display_title' => 'Purchased service', 'quantity' => 1, 'final_amount' => 10001]]];
    }

    public function test_same_environment_different_merchant_cannot_reconcile_existing_attempt(): void
    {
        [, $attempt] = $this->fixture();
        config(['yandex_split.merchant_id' => 'different-merchant']);
        try {
            app(YandexSplitWebhookHandler::class)->reconcile($attempt);
            $this->fail('Expected persisted merchant mismatch');
        } catch (DomainException $exception) {
            $this->assertSame('PAYMENT_YANDEX_ORDER_MISMATCH', $exception->getMessage());
        }
        Http::assertNothingSent();
    }

    public function test_remote_attempt_exists_before_callback_and_response_cannot_overwrite_paid(): void
    {
        [$order, $unused] = $this->fixture();
        $order->update(['active_attempt_id' => null]);
        $unused->delete();
        $level = DB::transactionLevel();
        Http::fake([
            'sandbox.pay.yandex.ru/api/merchant/v1/orders' => function ($request) use ($order, $level) {
                $this->assertSame($level, DB::transactionLevel());
                $attempt = $order->fresh()->activeAttempt;
                $this->assertNotNull($attempt);
                $this->assertSame($request['orderId'], $attempt->provider_payment_id);
                app(YandexSplitWebhookHandler::class)->reconcile($attempt);

                return Http::response(['status' => 'success', 'data' => ['paymentUrl' => 'https://sandbox.pay.yandex.ru/pay/test']]);
            },
            'sandbox.pay.yandex.ru/api/merchant/v1/orders/*' => function () use ($order) {
                return Http::response(['status' => 'success', 'data' => ['order' => $this->remote($order->fresh()->activeAttempt, 'CAPTURED')]]);
            },
        ]);
        $result = app(PaymentAttemptCreator::class)->create($order, [
            'provider' => 'yandex_split', 'return_url' => 'https://staging.example.test/return', 'cancel_url' => 'https://staging.example.test/cancel',
        ], app(PaymentStateMachine::class));
        $this->assertSame('paid', $result->payment_status);
        $this->assertSame('completed', $result->activeAttempt->status);
        $this->assertDatabaseCount('order_settlements', 1);
    }

    public function test_unknown_remote_create_reuses_identity_without_another_post(): void
    {
        [$order, $unused] = $this->fixture();
        $order->update(['active_attempt_id' => null]);
        $unused->delete();
        $posts = 0;
        Http::fake(['sandbox.pay.yandex.ru/api/merchant/v1/orders' => function () use (&$posts) {
            $posts++;
            throw new ConnectionException;
        }]);
        $input = ['provider' => 'yandex_split', 'return_url' => 'https://staging.example.test/return', 'cancel_url' => 'https://staging.example.test/cancel'];
        for ($i = 0; $i < 2; $i++) {
            try {
                app(PaymentAttemptCreator::class)->create($order->fresh(), $input, app(PaymentStateMachine::class));
                $this->fail('Expected confirmation pending');
            } catch (DomainException $exception) {
                $this->assertSame('PAYMENT_CONFIRMATION_PENDING', $exception->getMessage());
            }
        }
        $this->assertSame(1, $posts);
        $this->assertDatabaseCount('payment_attempts', 1);
        $this->assertSame('unknown', $order->fresh()->activeAttempt->provider_substatus['initialization']);
    }

    public function test_stale_failed_attempt_cannot_cancel_current_attempt(): void
    {
        [$order, $attempt] = $this->fixture();
        $replacement = PaymentAttempt::query()->create(['order_id' => $order->id, 'provider' => 'test_provider',
            'status' => 'pending', 'amount' => 10001, 'currency' => 'RUB']);
        $order->update(['active_attempt_id' => $replacement->id]);
        Http::fake(['*' => Http::response(['status' => 'success', 'data' => ['order' => $this->remote($attempt, 'FAILED')]])]);
        try {
            app(YandexSplitWebhookHandler::class)->reconcile($attempt);
            $this->fail('Expected stale attempt');
        } catch (DomainException $exception) {
            $this->assertSame('PAYMENT_YANDEX_STALE_ATTEMPT_REVIEW', $exception->getMessage());
        }
        $this->assertSame('pending_payment', $order->fresh()->payment_status);
        $this->assertSame('pending', $replacement->fresh()->status);
    }

    public function test_command_is_read_only_until_apply_and_refund_read_does_not_mutate(): void
    {
        [$order, $attempt] = $this->fixture();
        Http::fake(['sandbox.pay.yandex.ru/api/merchant/v1/orders/*' => Http::response([
            'status' => 'success', 'data' => ['order' => $this->remote($attempt, 'CAPTURED')],
        ])]);
        $before = $attempt->fresh()->getAttributes();
        $this->artisan('payments:yandex-split:reconcile', ['id' => (string) $attempt->id])->assertSuccessful();
        $this->assertSame('pending_payment', $order->fresh()->payment_status);
        $this->assertSame($before, $attempt->fresh()->getAttributes());
        $this->assertDatabaseCount('provider_events', 0);
        $this->artisan('payments:yandex-split:reconcile', ['id' => (string) $attempt->id, '--apply' => true])->assertSuccessful();
        $this->assertSame('paid', $order->fresh()->payment_status);
        $key = (string) Str::uuid();
        $refund = Refund::query()->create(['order_id' => $order->id, 'attempt_id' => $attempt->id,
            'refund_type' => 'full', 'amount' => 10001, 'currency' => 'RUB', 'status' => 'unknown',
            'reason' => 'Test', 'policy_access_action' => 'revoke', 'request_key' => $key]);
        Http::fake(['sandbox.pay.yandex.ru/api/merchant/v1/operations/'.$key => Http::response([
            'status' => 'success', 'data' => ['operation' => ['operationId' => 'read-only-operation', 'externalOperationId' => $key,
                'orderId' => $attempt->provider_payment_id, 'amount' => '100.01', 'operationType' => 'REFUND', 'status' => 'SUCCESS']],
        ])]);
        $this->artisan('payments:yandex-split:reconcile', ['id' => (string) $refund->id, '--refund' => true])->assertSuccessful();
        $this->assertSame('unknown', $refund->fresh()->status);
        $this->assertSame(0, (int) $order->fresh()->amount_refunded);
    }

    public function test_failed_refund_cannot_regress_to_pending_but_verified_success_can_recover(): void
    {
        [$order, $attempt] = $this->paidFixture();
        $key = (string) Str::uuid();
        $refund = Refund::query()->create(['order_id' => $order->id, 'attempt_id' => $attempt->id,
            'refund_type' => 'full', 'amount' => 10001, 'currency' => 'RUB', 'status' => 'failed',
            'reason' => 'Test', 'policy_access_action' => 'revoke', 'request_key' => $key, 'provider_refund_id' => 'stable-op']);
        $operation = ['operationId' => 'stable-op', 'externalOperationId' => $key, 'orderId' => $attempt->provider_payment_id,
            'operationType' => 'REFUND', 'amount' => '100.01', 'status' => 'PENDING'];
        app(YandexSplitRefundService::class)->applyOperation($attempt, $operation);
        $this->assertSame('failed', $refund->fresh()->status);
        $operation['status'] = 'SUCCESS';
        app(YandexSplitRefundService::class)->applyOperation($attempt, $operation);
        $this->assertSame('completed', $refund->fresh()->status);
    }

    private function key(string $kid = 'test-key'): array
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        openssl_pkey_export($key, $private);
        $details = openssl_pkey_get_details($key);

        return [$private, ['kty' => 'EC', 'crv' => 'P-256', 'kid' => $kid, 'alg' => 'ES256', 'use' => 'sig',
            'x' => JWT::urlsafeB64Encode($details['ec']['x']), 'y' => JWT::urlsafeB64Encode($details['ec']['y'])]];
    }

    private function fixture(): array
    {
        $user = User::factory()->create();
        $subject = Subject::query()->create(['slug' => 'test-'.Str::uuid(), 'title' => 'Subject', 'sort_order' => 1, 'is_active' => true]);
        $course = Course::query()->create(['subject_id' => $subject->id, 'slug' => 'test-'.Str::uuid(),
            'title' => 'Course', 'short_title' => 'Course', 'price_cents' => 10001, 'sort_order' => 1, 'is_active' => true]);
        Lesson::query()->create(['course_id' => $course->id, 'slug' => 'lesson', 'title' => 'Lesson', 'sort_order' => 1, 'is_active' => true]);
        $order = Order::query()->create(['user_id' => $user->id, 'course_id' => $course->id,
            'payment_status' => 'pending_payment', 'access_status' => 'not_granted', 'currency' => 'RUB',
            'amount_total' => 10001, 'amount_discount' => 0, 'amount_final' => 10001, 'amount_paid' => 0, 'amount_refunded' => 0]);
        app(OrderItemSnapshotService::class)->createCourseItem($order, $course);
        $attempt = PaymentAttempt::query()->create(['order_id' => $order->id, 'provider' => 'yandex_split',
            'status' => 'pending', 'amount' => 10001, 'currency' => 'RUB', 'provider_substatus' => ['sandbox' => true, 'merchant_id' => 'merchant-test']]);
        $attempt->update(['provider_payment_id' => YandexSplitClient::orderId((int) $order->id, (int) $attempt->id)]);
        $order->update(['active_attempt_id' => $attempt->id]);

        return [$order, $attempt];
    }

    private function paidFixture(): array
    {
        [$order, $attempt] = $this->fixture();
        Http::fake(['sandbox.pay.yandex.ru/api/merchant/v1/orders/*' => Http::response(['status' => 'success', 'data' => ['order' => $this->remote($attempt, 'CAPTURED')]])]);
        app(YandexSplitWebhookHandler::class)->reconcile($attempt);

        return [$order->fresh(), $attempt->fresh()];
    }

    private function remote(PaymentAttempt $attempt, string $status): array
    {
        return ['orderId' => $attempt->provider_payment_id, 'merchantId' => 'merchant-test',
            'currencyCode' => 'RUB', 'orderAmount' => '100.01', 'paymentStatus' => $status];
    }
}
