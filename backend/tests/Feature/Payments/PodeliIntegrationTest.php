<?php

namespace Tests\Feature\Payments;

use App\Http\Controllers\Api\PodeliWebhookController;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\Order;
use App\Models\OrderSettlement;
use App\Models\PaymentAttempt;
use App\Models\Refund;
use App\Models\Subject;
use App\Models\User;
use App\Services\OrderItemSnapshotService;
use App\Services\OrderSettlementService;
use App\Services\Payments\PaymentAttemptCreator;
use App\Services\Payments\PodeliClient;
use App\Services\Payments\PodeliPaymentProviderAdapter;
use App\Services\Payments\PodeliRefundService;
use App\Services\Payments\PodeliWebhookHandler;
use App\Services\PaymentStateMachine;
use DomainException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class PodeliIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        config(['podeli.enabled' => true, 'podeli.sandbox' => true, 'podeli.capture_mode' => 'single_stage',
            'podeli.login' => 'test-login', 'podeli.password' => 'test-password',
            'podeli.certificate_path' => __FILE__, 'podeli.private_key_path' => __FILE__,
            'podeli.return_url_hosts' => ['staging.example.test'], 'podeli.notification_url' => 'https://staging.example.test/callback']);
        Queue::fake();
        Http::preventStrayRequests();
    }

    public function test_missing_capture_mode_certificate_or_production_host_fail_closed(): void
    {
        foreach (['podeli.capture_mode', 'podeli.certificate_path'] as $key) {
            $old = config($key);
            config([$key => '']);
            $this->assertFalse(app(PodeliClient::class)->configured());
            config([$key => $old]);
        }
        config(['podeli.sandbox' => false, 'podeli.production_base_url' => '']);
        $this->assertFalse(app(PodeliClient::class)->configured());
        Http::assertNothingSent();
    }

    public function test_decimal_json_roundtrip_never_rounds_money_or_alters_quoted_text(): void
    {
        $json = PodeliClient::encode(['order' => ['amount' => '100.01', 'prepaidAmount' => '0.00',
            'items' => [['name' => 'Text "amount": 900.99, 12e3', 'amount' => '100.01', 'quantity' => '1']]]]);
        $this->assertStringContainsString('"amount":100.01', $json);
        $decoded = PodeliClient::decode($json);
        $this->assertSame('100.01', $decoded['order']['amount']);
        $this->assertSame('Text "amount": 900.99, 12e3', $decoded['order']['items'][0]['name']);
        $this->assertSame(10001, PodeliClient::minor($decoded['order']['amount']));
        $this->expectExceptionMessage('PAYMENT_PODELI_AMOUNT_INVALID');
        PodeliClient::minor(PodeliClient::decode('{"amount":1.0000000000000001}')['amount']);
    }

    public function test_create_sends_mtls_basic_unique_correlation_and_explicit_single_stage(): void
    {
        $ids = [];
        Http::fake(['api-sand.podeli.ru/partners/v1/orders/create' => function ($request, $options) use (&$ids) {
            $this->assertSame(__FILE__, $options['cert']);
            $this->assertSame(__FILE__, $options['ssl_key']);
            $this->assertTrue($options['verify']);
            $this->assertTrue($request->hasHeader('Authorization', 'Basic '.base64_encode('test-login:test-password')));
            $ids[] = $request->header('X-Correlation-ID')[0];
            $data = PodeliClient::decode($request->body());
            $this->assertFalse($data['order']['isTwoStagePayment']);
            $this->assertSame('100.01', $data['order']['amount']);
            $this->assertSame('Known', $data['clientInfo']['lastName']);

            return Http::response('{"status":"CREATED","amount":100.01,"redirectUrl":"https://pokupka-sand.podeli.ru/login?guid=test"}');
        }]);
        $adapter = new PodeliPaymentProviderAdapter('podeli', '');
        $result = $adapter->startPayment($this->startPayload());
        $this->assertSame('cms-1-2', $result['provider_payment_id']);
        $adapter->startPayment($this->startPayload());
        $this->assertNotSame($ids[0], $ids[1]);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{14}4[0-9a-f-]{21}$/', $ids[0]);
    }

    public function test_missing_explicit_name_rejects_before_network(): void
    {
        $payload = $this->startPayload();
        unset($payload['client_info']['last_name']);
        try {
            (new PodeliPaymentProviderAdapter('podeli', ''))->startPayment($payload);
            $this->fail('Expected explicit customer data');
        } catch (DomainException $exception) {
            $this->assertSame('PAYMENT_PODELI_CUSTOMER_REQUIRED', $exception->getMessage());
        }
        Http::assertNothingSent();
    }

    public function test_callback_source_ip_is_required_and_body_is_not_payment_authority(): void
    {
        [$order, $attempt] = $this->fixture();
        $payload = ['order' => ['id' => $attempt->provider_payment_id, 'statusCode' => 'COMPLETED', 'statusDateTime' => '2026-09-15T10:00:00Z']];
        $controller = app(PodeliWebhookController::class);
        $request = Request::create('/callback', 'POST', [], [], [], ['REMOTE_ADDR' => '203.0.113.1', 'CONTENT_TYPE' => 'application/json'], json_encode($payload));
        $this->assertSame(403, $controller($request, app(PodeliWebhookHandler::class))->status());
        Http::assertNothingSent();
        Http::fake(['*' => Http::response($this->info($attempt, 'APPROVED'))]);
        $request = Request::create('/callback', 'POST', [], [], [], ['REMOTE_ADDR' => '111.88.241.154', 'CONTENT_TYPE' => 'application/json'], json_encode($payload));
        $this->assertSame(200, $controller($request, app(PodeliWebhookHandler::class))->status());
        $this->assertSame('pending_payment', $order->fresh()->payment_status);
        $this->assertDatabaseCount('order_settlements', 0);
    }

    public function test_confirmed_info_settles_once_and_mismatched_amount_never_grants(): void
    {
        [$order, $attempt] = $this->fixture();
        Http::fake(['*' => Http::response($this->info($attempt, 'COMPLETED'))]);
        app(PodeliWebhookHandler::class)->reconcile($attempt);
        app(PodeliWebhookHandler::class)->reconcile($attempt->fresh());
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertDatabaseCount('order_settlements', 1);
        $this->assertDatabaseCount('user_entitlement_grants', 1);
        $this->assertDatabaseHas('provider_events', ['provider' => 'podeli', 'signature_valid' => false]);
    }

    public function test_info_wrong_item_and_money_rejected(): void
    {
        [$order, $attempt] = $this->fixture();
        $info = $this->info($attempt, 'COMPLETED');
        $info['order']['items'][0]['amount'] = '100.02';
        Http::fake(['*' => Http::response($info)]);
        try {
            app(PodeliWebhookHandler::class)->reconcile($attempt);
            $this->fail('Expected wrong price rejection');
        } catch (DomainException $exception) {
            $this->assertSame('PAYMENT_PODELI_ORDER_MISMATCH', $exception->getMessage());
        }
        $this->assertSame('pending_payment', $order->fresh()->payment_status);
        $this->assertDatabaseCount('order_settlements', 0);
    }

    public function test_info_cannot_apply_or_capture_after_local_snapshot_changes_during_http(): void
    {
        config(['podeli.capture_mode' => 'two_stage']);
        foreach (['COMPLETED', 'WAIT_FOR_COMMIT'] as $status) {
            foreach (['amount', 'item'] as $mutation) {
                [$order, $attempt] = $this->fixture();
                $info = $this->info($attempt, $status);
                Http::fake(function () use ($attempt, $order, $info, $mutation) {
                    if ($mutation === 'amount') {
                        PaymentAttempt::query()->whereKey($attempt->id)->update(['amount' => 10002]);
                    } else {
                        $order->items()->update(['sellable_sku' => 'changed-service']);
                    }

                    return Http::response($info);
                });
                try {
                    app(PodeliWebhookHandler::class)->reconcile($attempt);
                    $this->fail('Expected changed snapshot rejection');
                } catch (DomainException $exception) {
                    $this->assertSame('PAYMENT_PODELI_ORDER_MISMATCH', $exception->getMessage());
                }
                $this->assertSame('pending_payment', $order->fresh()->payment_status);
                $this->assertArrayNotHasKey('commit_state', $attempt->fresh()->provider_substatus);
                Http::assertSentCount(1);
            }
        }
        $this->assertDatabaseCount('order_settlements', 0);
        $this->assertDatabaseCount('provider_events', 0);
        $this->assertDatabaseCount('user_entitlement_grants', 0);
    }

    public function test_two_stage_commit_is_reserved_once_outside_transaction_and_requires_completed_info(): void
    {
        config(['podeli.capture_mode' => 'two_stage']);
        [$order, $attempt] = $this->fixture();
        $posts = 0;
        Http::fake([
            'api-sand.podeli.ru/partners/v1/orders/*/info' => Http::sequence()
                ->push($this->info($attempt, 'WAIT_FOR_COMMIT'))
                ->push($this->info($attempt, 'COMPLETED'))
                ->push($this->info($attempt, 'COMPLETED')),
            'api-sand.podeli.ru/partners/v1/orders/*/commit' => function () use (&$posts, $attempt) {
                $posts++;
                $this->assertSame(0, DB::transactionLevel());
                $this->assertSame('pending', $attempt->fresh()->provider_substatus['commit_state']);

                return Http::response(['order' => ['status' => 'WAIT_FOR_COMMIT']]);
            },
        ]);
        app(PodeliWebhookHandler::class)->reconcile($attempt);
        app(PodeliWebhookHandler::class)->reconcile($attempt->fresh());
        $this->assertSame(1, $posts);
        $this->assertSame('paid', $order->fresh()->payment_status);
    }

    public function test_unknown_commit_is_not_repeated_on_next_info(): void
    {
        config(['podeli.capture_mode' => 'two_stage']);
        [$order, $attempt] = $this->fixture();
        $posts = 0;
        Http::fake([
            'api-sand.podeli.ru/partners/v1/orders/*/info' => Http::response($this->info($attempt, 'WAIT_FOR_COMMIT')),
            'api-sand.podeli.ru/partners/v1/orders/*/commit' => function () use (&$posts) {
                $posts++;
                throw new ConnectionException;
            },
        ]);
        app(PodeliWebhookHandler::class)->reconcile($attempt);
        app(PodeliWebhookHandler::class)->reconcile($attempt->fresh());
        $this->assertSame(1, $posts);
        $this->assertSame('unknown', $attempt->fresh()->provider_substatus['commit_state']);
        $this->assertSame('pending_payment', $order->fresh()->payment_status);
    }

    public function test_refund_post_never_completes_until_processed_info_and_dedup_preserves_accounting(): void
    {
        [$order, $attempt] = $this->paidFixture();
        $key = (string) Str::uuid();
        Http::fake(['api-sand.podeli.ru/partners/v1/orders/*/refund' => function ($request) use ($attempt, $key) {
            $this->assertSame(0, DB::transactionLevel());
            $data = PodeliClient::decode($request->body());
            $this->assertSame('1', $data['order']['refund']['items'][0]['refundedQuantity']);
            $this->assertArrayNotHasKey('refundedSum', $data['order']['refund']['items'][0]);

            return Http::response(['order' => ['id' => $attempt->provider_payment_id, 'status' => 'REFUNDED'],
                'refund' => ['id' => $key, 'status' => 'PENDING']]);
        }]);
        $input = ['refund_type' => 'full', 'policy_access_action' => 'revoke', 'reason' => 'Test'];
        $service = app(PodeliRefundService::class);
        $refund = $service->request($order, $input, $key, (int) $order->user_id);
        $service->request($order, $input, $key, (int) $order->user_id);
        $this->assertSame('submitted', $refund->status);
        $this->assertSame(0, (int) $order->fresh()->amount_refunded);
        $entry = $this->refundEntry($attempt, $key);
        $service->applyRefund($attempt, $entry);
        $service->applyRefund($attempt, $entry);
        $this->assertSame(10001, (int) $order->fresh()->amount_refunded);
        $this->assertSame('revoked', $order->fresh()->access_status);
        $this->assertDatabaseCount('refunds', 1);
    }

    public function test_partial_service_request_rejects_and_unknown_refund_not_resent(): void
    {
        [$order] = $this->paidFixture();
        $posts = 0;
        Http::fake(['api-sand.podeli.ru/partners/v1/orders/*/refund' => function () use (&$posts) {
            $posts++;
            throw new ConnectionException;
        }]);
        $service = app(PodeliRefundService::class);
        try {
            $service->request($order, ['refund_type' => 'partial', 'policy_access_action' => 'revoke'], (string) Str::uuid(), (int) $order->user_id);
            $this->fail('Expected whole-service requirement');
        } catch (DomainException $exception) {
            $this->assertSame('PAYMENT_REFUND_WHOLE_SERVICE_REQUIRED', $exception->getMessage());
        }
        $key = (string) Str::uuid();
        $input = ['refund_type' => 'full', 'policy_access_action' => 'revoke', 'reason' => 'Test'];
        $refund = $service->request($order, $input, $key, (int) $order->user_id);
        $service->request($order, $input, $key, (int) $order->user_id);
        $this->assertSame('unknown', $refund->status);
        $this->assertSame(1, $posts);
        $this->assertSame('granted', $order->fresh()->access_status);
    }

    private function startPayload(): array
    {
        return ['order_id' => 1, 'attempt_id' => 2, 'amount' => 10001, 'currency' => 'RUB',
            'return_url' => 'https://staging.example.test/return', 'cancel_url' => 'https://staging.example.test/cancel',
            'items' => [['sellable_sku' => 'course:test', 'display_title' => 'Purchased service', 'quantity' => 1, 'final_amount' => 10001]],
            'client_info' => ['first_name' => 'Explicit', 'last_name' => 'Known', 'phone' => '79990000000', 'email' => 'test@example.test']];
    }

    public function test_create_response_preserves_callback_commit_reservation(): void
    {
        config(['podeli.capture_mode' => 'two_stage']);
        [$order, $unused] = $this->fixture();
        $order->update(['active_attempt_id' => null]);
        $unused->delete();
        $commits = 0;
        Http::fake([
            'api-sand.podeli.ru/partners/v1/orders/create' => function () use ($order) {
                app(PodeliWebhookHandler::class)->reconcile($order->fresh()->activeAttempt);

                return Http::response('{"status":"CREATED","amount":100.01,"redirectUrl":"https://pokupka-sand.podeli.ru/login?guid=test"}');
            },
            'api-sand.podeli.ru/partners/v1/orders/*/info' => function () use ($order) {
                return Http::response($this->info($order->fresh()->activeAttempt, 'WAIT_FOR_COMMIT'));
            },
            'api-sand.podeli.ru/partners/v1/orders/*/commit' => function () use (&$commits) {
                $commits++;
                throw new ConnectionException;
            },
        ]);
        $input = $this->startPayload();
        $input['provider'] = 'podeli';
        $result = app(PaymentAttemptCreator::class)->create($order, $input, app(PaymentStateMachine::class));
        $this->assertSame('unknown', $result->activeAttempt->provider_substatus['commit_state'] ?? null);
        app(PodeliWebhookHandler::class)->reconcile($result->activeAttempt);
        $this->assertSame(1, $commits);
    }

    public function test_same_environment_different_account_cannot_reconcile_existing_attempt(): void
    {
        [, $attempt] = $this->fixture();
        config(['podeli.login' => 'other-merchant']);
        try {
            app(PodeliWebhookHandler::class)->reconcile($attempt);
            $this->fail('Expected account mismatch');
        } catch (DomainException $exception) {
            $this->assertSame('PAYMENT_PODELI_ORDER_MISMATCH', $exception->getMessage());
        }
        Http::assertNothingSent();
    }

    public function test_lost_paid_callback_and_processed_full_refund_recover_atomically_without_live_access(): void
    {
        [$order, $attempt] = $this->fixture();
        $info = $this->info($attempt, 'REFUNDED');
        $info['refund'] = [$this->refundEntry($attempt, 'external-full')];
        Http::fake(['*' => Http::response($info)]);
        app(PodeliWebhookHandler::class)->reconcile($attempt);
        app(PodeliWebhookHandler::class)->reconcile($attempt->fresh());
        $this->assertSame('refunded', $order->fresh()->payment_status);
        $this->assertSame('revoked', $order->fresh()->access_status);
        $this->assertDatabaseCount('order_settlements', 1);
        $this->assertDatabaseCount('refunds', 1);
        $this->assertDatabaseCount('outbox_events', 2);
        $this->assertDatabaseMissing('user_entitlement_grants', ['order_id' => $order->id, 'status' => 'active']);
    }

    public function test_lost_paid_refund_recovery_rolls_back_everything_on_refund_mismatch(): void
    {
        [$order, $attempt] = $this->fixture();
        $info = $this->info($attempt, 'REFUNDED');
        $info['refund'] = [$this->refundEntry($attempt, 'external-full')];
        $info['refund'][0]['refundedItems'][0]['id'] = 'wrong-service';
        Http::fake(['*' => Http::response($info)]);
        try {
            app(PodeliWebhookHandler::class)->reconcile($attempt);
            $this->fail('Expected refund item mismatch');
        } catch (DomainException $exception) {
            $this->assertSame('PAYMENT_PODELI_REFUND_MISMATCH', $exception->getMessage());
        }
        $this->assertSame('pending_payment', $order->fresh()->payment_status);
        $this->assertDatabaseCount('order_settlements', 0);
        $this->assertDatabaseCount('user_entitlement_grants', 0);
        $this->assertDatabaseCount('outbox_events', 0);
    }

    public function test_command_default_is_read_only_and_apply_does_not_capture_without_flag(): void
    {
        config(['podeli.capture_mode' => 'two_stage']);
        [$order, $attempt] = $this->fixture();
        Http::fake(['api-sand.podeli.ru/partners/v1/orders/*/info' => Http::sequence()
            ->push($this->info($attempt, 'WAIT_FOR_COMMIT'))
            ->push($this->info($attempt, 'WAIT_FOR_COMMIT'))
            ->push($this->info($attempt, 'COMPLETED'))]);
        $before = $attempt->fresh()->getAttributes();
        $this->artisan('payments:podeli:reconcile', ['id' => (string) $attempt->id])
            ->expectsOutputToContain('WAIT_FOR_COMMIT')->assertSuccessful();
        $this->assertSame($before, $attempt->fresh()->getAttributes());
        $this->assertDatabaseCount('provider_events', 0);
        $this->artisan('payments:podeli:reconcile', ['id' => (string) $attempt->id, '--apply' => true])->assertSuccessful();
        $this->assertSame('pending_payment', $order->fresh()->payment_status);
        $this->assertArrayNotHasKey('commit_state', $attempt->fresh()->provider_substatus);
        $this->artisan('payments:podeli:reconcile', ['id' => (string) $attempt->id, '--apply' => true])->assertSuccessful();
        $this->assertSame('paid', $order->fresh()->payment_status);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public function test_command_capture_requires_explicit_apply(): void
    {
        $this->artisan('payments:podeli:reconcile', ['id' => '1', '--capture' => true])->assertFailed();
        Http::assertNothingSent();
    }

    public function test_refund_info_recovers_unknown_and_pending_info_does_not_complete(): void
    {
        [$order, $attempt] = $this->fixture();
        // Mark settled through the real domain path before replacing the provider
        // responses with a sequence for refund reconciliation.
        app(OrderSettlementService::class)->confirmPaid($order, [
            'idempotency_key' => 'test-podeli-settlement', 'amount' => 10001, 'currency' => 'RUB',
            'settlement_type' => OrderSettlement::TYPE_PROVIDER_PAYMENT, 'payment_attempt_id' => $attempt->id,
        ]);
        $key = (string) Str::uuid();
        $refund = Refund::query()->create(['order_id' => $order->id, 'attempt_id' => $attempt->id,
            'refund_type' => 'full', 'amount' => 10001, 'currency' => 'RUB', 'status' => 'unknown',
            'reason' => 'Test', 'policy_access_action' => 'revoke', 'request_key' => $key, 'provider_refund_id' => $key]);
        $pending = $this->info($attempt, 'REFUNDED');
        $pending['refund'] = [$this->refundEntry($attempt, $key)];
        $pending['refund'][0]['status'] = 'PENDING';
        $processed = $pending;
        $processed['refund'][0]['status'] = 'PROCESSED';
        Http::fake(['api-sand.podeli.ru/partners/v1/orders/*/info' => Http::sequence()->push($pending)->push($processed)]);
        $service = app(PodeliRefundService::class);
        $this->assertSame('unknown', $service->reconcile($refund)->status);
        $this->assertSame(0, (int) $order->fresh()->amount_refunded);
        $this->assertSame('completed', $service->reconcile($refund->fresh())->status);
        $this->assertSame('revoked', $order->fresh()->access_status);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public function test_stale_callback_cannot_cancel_new_attempt_and_environment_switch_cannot_query(): void
    {
        [$order, $attempt] = $this->fixture();
        $replacement = PaymentAttempt::query()->create(['order_id' => $order->id, 'provider' => 'test_provider',
            'status' => 'pending', 'amount' => 10001, 'currency' => 'RUB']);
        $order->update(['active_attempt_id' => $replacement->id]);
        Http::fake(['*' => Http::response($this->info($attempt, 'REJECTED'))]);
        try {
            app(PodeliWebhookHandler::class)->reconcile($attempt);
            $this->fail('Expected stale attempt review');
        } catch (DomainException $exception) {
            $this->assertSame('PAYMENT_PODELI_STALE_ATTEMPT_REVIEW', $exception->getMessage());
        }
        $this->assertSame('pending_payment', $order->fresh()->payment_status);
        config(['podeli.sandbox' => false]);
        try {
            app(PodeliWebhookHandler::class)->reconcile($attempt);
            $this->fail('Expected environment mismatch');
        } catch (DomainException $exception) {
            $this->assertSame('PAYMENT_PODELI_ORDER_MISMATCH', $exception->getMessage());
        }
        Http::assertSentCount(1);
    }

    public function test_external_partial_refund_records_money_but_keeps_whole_service_access(): void
    {
        [$order, $attempt] = $this->paidFixture();
        $entry = $this->refundEntry($attempt, 'external-partial');
        $entry['refundedAmountCredit'] = $entry['totalRefundedAmount'] = '10.00';
        $entry['refundedItems'][0]['itemRefundedAmount'] = '10.00';
        $entry['refundedItems'][0]['refundedQuantity'] = '0.1';
        app(PodeliRefundService::class)->applyRefund($attempt, $entry);
        $this->assertSame(1000, (int) $order->fresh()->amount_refunded);
        $this->assertSame('granted', $order->fresh()->access_status);
        $this->assertDatabaseHas('payment_audit_log', ['action' => 'partial_service_refund_review']);
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
        $attempt = PaymentAttempt::query()->create(['order_id' => $order->id, 'provider' => 'podeli',
            'status' => 'pending', 'amount' => 10001, 'currency' => 'RUB',
            'provider_substatus' => ['sandbox' => true, 'capture_mode' => config('podeli.capture_mode'),
                'account_fingerprint' => app(PodeliClient::class)->accountFingerprint()]]);
        $attempt->update(['provider_payment_id' => PodeliClient::orderId((int) $order->id, (int) $attempt->id)]);
        $order->update(['active_attempt_id' => $attempt->id]);

        return [$order, $attempt];
    }

    private function info(PaymentAttempt $attempt, string $status): array
    {
        return ['order' => ['id' => $attempt->provider_payment_id, 'amount' => '100.01', 'prepaidAmount' => '0.00',
            'status' => $status, 'date' => '2026-09-15T10:00:00Z', 'items' => [[
                'id' => PodeliClient::itemId($attempt->order->items()->sole()->sellable_sku),
                'amount' => '100.01', 'quantity' => '1', 'prepaidAmount' => '0.00',
            ]]], 'refund' => []];
    }

    private function paidFixture(): array
    {
        [$order, $attempt] = $this->fixture();
        Http::fake(['api-sand.podeli.ru/partners/v1/orders/*/info' => Http::response($this->info($attempt, 'COMPLETED'))]);
        app(PodeliWebhookHandler::class)->reconcile($attempt);

        return [$order->fresh(), $attempt->fresh()];
    }

    private function refundEntry(PaymentAttempt $attempt, string $key): array
    {
        return ['id' => $key, 'status' => 'PROCESSED', 'refundedAmountCredit' => '100.01', 'totalRefundedAmount' => '100.01',
            'refundedPrepaidAmount' => '0.00', 'refundedItems' => [[
                'id' => PodeliClient::itemId($attempt->order->items()->sole()->sellable_sku),
                'refundedQuantity' => '1', 'itemRefundedAmount' => '100.01', 'itemRefundedPrepaidAmount' => '0.00',
            ]]];
    }
}
