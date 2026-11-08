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
use App\Services\Payments\RobokassaClient;
use App\Services\Payments\RobokassaPaymentProviderAdapter;
use App\Services\Payments\RobokassaRefundService;
use App\Services\Payments\RobokassaWebhookHandler;
use App\Services\PaymentStateMachine;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RobokassaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        Http::preventStrayRequests();
        User::factory()->create(['id' => 1]);
        config(['robokassa' => ['enabled' => true, 'test_mode' => false, 'merchant_login' => 'fixture',
            'password1' => 'first', 'password2' => 'second', 'password3' => 'third', 'hash' => 'md5', 'refund_enabled' => true]]);
    }

    public function test_signed_redirect_uses_attempt_and_signed_order_without_network(): void
    {
        $result = $this->adapter()->startPayment(['order_id' => 6, 'attempt_id' => 8, 'amount' => 12345, 'currency' => 'RUB']);
        parse_str(parse_url($result['provider_payment_url'], PHP_URL_QUERY), $fields);
        $this->assertSame('8', $fields['InvId']);
        $this->assertSame('123.45', $fields['OutSum']);
        $this->assertSame(md5('fixture:123.45:8:first:Shp_order=6:Shp_test=0'), $fields['SignatureValue']);
        Http::assertNothingSent();
    }

    public function test_disabled_rejects_and_test_lookup_never_uses_production_endpoint(): void
    {
        config(['robokassa.enabled' => false]);
        $this->assertFalse($this->adapter()->verifyWebhookSignature('OutSum=1&InvId=1', 'bad'));
        config(['robokassa.enabled' => true, 'robokassa.test_mode' => true]);
        try {
            app(RobokassaClient::class)->operation('1');
            $this->fail('Test lookup is unavailable.');
        } catch (DomainException $e) {
            $this->assertSame('ROBOKASSA_TEST_LOOKUP_UNSUPPORTED', $e->getMessage());
        }
        Http::assertNothingSent();
    }

    public function test_signature_rejects_duplicates_custom_tamper_and_fractional_kopecks(): void
    {
        $body = $this->body('100.000000', '8', '6');
        $this->assertTrue($this->adapter()->verifyWebhookSignature($body, null));
        foreach ([$body.'&InvId=9', str_replace('Shp_order=6', 'Shp_order=7', $body), $this->body('100.000001', '8', '6')] as $bad) {
            $this->assertFalse($this->adapter()->verifyWebhookSignature($bad, null));
        }
        $this->assertSame(10000, RobokassaPaymentProviderAdapter::minor('100.000000'));
    }

    public function test_typed_handler_rejects_signed_amount_mismatch_without_mutation(): void
    {
        $order = $this->order();
        $this->expectExceptionMessage('ROBOKASSA_PAYMENT_MISMATCH');
        app(RobokassaWebhookHandler::class)->handle($this->body('99.00', (string) $order->active_attempt_id, (string) $order->id));
    }

    public function test_verified_result_settles_once_and_duplicate_after_refund_never_regrants(): void
    {
        $order = $this->pendingOrder();
        $body = $this->body('100.000000', (string) $order->active_attempt_id, (string) $order->id);
        $handler = app(RobokassaWebhookHandler::class);
        $this->assertSame('OK'.$order->active_attempt_id, $handler->handle($body));
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertSame('OK'.$order->active_attempt_id, $handler->handle($body));
        $this->assertDatabaseCount('order_settlements', 1);
        $this->assertDatabaseCount('user_entitlement_grants', 1);
        $order->update(['payment_status' => 'refunded', 'access_status' => 'revoked']);
        $handler->handle($body);
        $this->assertSame('refunded', $order->fresh()->payment_status);
        $this->assertDatabaseCount('order_settlements', 1);
    }

    public function test_result_route_rejects_foreign_source_and_query_forgery_then_accepts_exact_body(): void
    {
        config(['robokassa.allowed_ips' => ['185.59.216.65']]);
        $order = $this->pendingOrder();
        $body = $this->body('100.000000', (string) $order->active_attempt_id, (string) $order->id);
        $url = '/api/v1/payments/webhooks/robokassa';
        $this->call('POST', $url, [], [], [], ['REMOTE_ADDR' => '203.0.113.1', 'CONTENT_TYPE' => 'application/x-www-form-urlencoded'], $body)->assertStatus(403);
        $this->call('POST', $url.'?'.$body, [], [], [], ['REMOTE_ADDR' => '185.59.216.65', 'CONTENT_TYPE' => 'application/x-www-form-urlencoded'], 'OutSum=1')->assertStatus(409);
        $this->assertDatabaseCount('order_settlements', 0);
        $this->assertSame('pending_payment', $order->fresh()->payment_status);
        $this->call('POST', $url, [], [], [], ['REMOTE_ADDR' => '185.59.216.65', 'CONTENT_TYPE' => 'application/x-www-form-urlencoded'], $body)
            ->assertOk()->assertContent('OK'.$order->active_attempt_id);
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertDatabaseCount('order_settlements', 1);
    }

    public function test_creator_reuses_valid_pending_link_and_prevents_provider_switch(): void
    {
        $order = $this->pendingOrder();
        $order->activeAttempt()->update(['status' => 'pending']);
        $before = $order->activeAttempt()->firstOrFail();
        $creator = app(PaymentAttemptCreator::class);
        $intent = ['provider' => 'robokassa', 'return_url' => 'https://example.test/success', 'cancel_url' => 'https://example.test/cancel'];
        $result = $creator->create($order, $intent, app(PaymentStateMachine::class));
        $this->assertSame($before->id, (int) $result->active_attempt_id);
        $this->assertSame($before->provider_payment_url, $result->activeAttempt()->firstOrFail()->provider_payment_url);
        $this->assertDatabaseCount('payment_attempts', 1);
        config(['services.payments.providers.cloudpayments.webhook_secret' => 'fixture', 'services.payments.providers.cloudpayments.public_id' => 'fixture']);
        try {
            $creator->create($order, [...$intent, 'provider' => 'cloudpayments'], app(PaymentStateMachine::class));
            $this->fail('Provider switch while a signed link is valid must fail.');
        } catch (DomainException $exception) {
            $this->assertSame('PAYMENT_CONFIRMATION_PENDING', $exception->getMessage());
        }
        $this->assertDatabaseCount('payment_attempts', 1);
        Http::assertNothingSent();
    }

    public function test_refund_is_reserved_before_io_and_completed_only_after_finished_poll(): void
    {
        $order = $this->order();
        $id = '12345678-1234-1234-1234-123456789abc';
        Http::fake([
            '*OpStateExt*' => Http::response($this->xml($order)),
            '*/Create' => function ($request) use ($order, $id) {
                $this->assertDatabaseHas('refunds', ['order_id' => $order->id, 'status' => 'pending']);
                $parts = explode('.', $request->body());
                $expected = rtrim(strtr(base64_encode(hash_hmac('sha256', $parts[0].'.'.$parts[1], 'third', true)), '+/', '-_'), '=');
                $this->assertSame($expected, $parts[2]);
                $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
                $this->assertSame(['OpKey' => 'operation-1'], $payload);

                return Http::response(['success' => true, 'requestId' => $id]);
            },
            '*GetState*' => Http::sequence()->push(['requestId' => $id, 'amount' => '100.000000', 'label' => 'processing'])
                ->push(['requestId' => $id, 'amount' => '100.000000', 'label' => 'finished']),
        ]);
        $service = app(RobokassaRefundService::class);
        $refund = $service->request($order, $this->intent(), 'robokassa-refund-test-key', 1);
        $this->assertSame('submitted', $refund->status);
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertSame($refund->id, $service->request($order, $this->intent(), 'robokassa-refund-test-key', 1)->id);
        $this->assertSame('submitted', $service->reconcile($refund)->status);
        $this->assertSame('completed', $service->reconcile($refund)->status);
        $this->assertSame('refunded', $order->fresh()->payment_status);
        $this->assertSame(10000, (int) $order->fresh()->amount_refunded);
        $service->reconcile($refund);
        Http::assertSentCount(4);
    }

    public function test_unknown_money_request_never_retries_or_allows_another_refund(): void
    {
        $order = $this->order();
        Http::fake(['*OpStateExt*' => Http::response($this->xml($order)), '*/Create' => fn () => throw new ConnectionException('secret')]);
        $service = app(RobokassaRefundService::class);
        $refund = $service->request($order, $this->intent(), 'robokassa-unknown-key', 1);
        $this->assertSame('unknown', $refund->status);
        $this->assertSame($refund->id, $service->request($order, $this->intent(), 'robokassa-unknown-key', 1)->id);
        $this->expectExceptionMessage('PAYMENT_REFUND_CONFIRMATION_PENDING');
        $service->request($order, $this->intent(), 'robokassa-another-key', 1);
    }

    public function test_partial_refund_is_rejected_without_io(): void
    {
        $order = $this->order();
        try {
            app(RobokassaRefundService::class)->request($order, [...$this->intent(), 'refund_type' => 'partial', 'amount' => 1], 'robokassa-partial-key', 1);
            $this->fail('Partial unit is not accepted.');
        } catch (DomainException $e) {
            $this->assertSame('PAYMENT_REFUND_WHOLE_SERVICE_REQUIRED', $e->getMessage());
        }
        Http::assertNothingSent();
    }

    public function test_foreign_operation_does_not_dispatch_refund(): void
    {
        $order = $this->order();
        Http::fake(['*OpStateExt*' => Http::response(str_replace('<Value>'.$order->id.'</Value>', '<Value>9999</Value>', $this->xml($order)))]);
        $refund = app(RobokassaRefundService::class)->request($order, $this->intent(), 'robokassa-foreign-key', 1);
        $this->assertSame('failed', $refund->status);
        $this->assertSame('paid', $order->fresh()->payment_status);
        Http::assertSentCount(1);
    }

    public function test_refund_state_amount_mismatch_does_not_finalize_money_or_access(): void
    {
        $order = $this->order();
        $id = '12345678-1234-1234-1234-123456789abc';
        Http::fake(['*OpStateExt*' => Http::response($this->xml($order)),
            '*/Create' => Http::response(['success' => true, 'requestId' => $id]),
            '*GetState*' => Http::response(['requestId' => $id, 'label' => 'finished', 'amount' => '99.00'])]);
        $refund = app(RobokassaRefundService::class)->request($order, $this->intent(), 'robokassa-mismatch-key', 1);
        try {
            app(RobokassaRefundService::class)->reconcile($refund);
            $this->fail('Mismatching amount is not a full refund.');
        } catch (DomainException $exception) {
            $this->assertSame('ROBOKASSA_REFUND_AMOUNT_MISMATCH', $exception->getMessage());
        }
        $this->assertSame('submitted', $refund->fresh()->status);
        $this->assertSame(0, (int) $order->fresh()->amount_refunded);
    }

    public function test_id_only_refund_without_provenance_never_queries_or_finalizes(): void
    {
        $order = $this->order();
        $refund = Refund::query()->create(['order_id' => $order->id, 'attempt_id' => $order->active_attempt_id, 'refund_type' => 'full',
            'amount' => 10000, 'currency' => 'RUB', 'status' => 'submitted', 'provider_refund_id' => '12345678-1234-1234-1234-123456789abc', 'policy_access_action' => 'revoke']);
        try {
            app(RobokassaRefundService::class)->reconcile($refund);
            $this->fail('An ID alone must not prove refund ownership.');
        } catch (DomainException $exception) {
            $this->assertSame('ROBOKASSA_REFUND_PROVENANCE_MISSING', $exception->getMessage());
        }
        Http::assertNothingSent();
        $this->assertSame(0, (int) $order->fresh()->amount_refunded);
    }

    public function test_foreign_provider_id_cannot_reuse_another_submission_proof(): void
    {
        $order = $this->order();
        Http::fake(['*OpStateExt*' => Http::response($this->xml($order)), '*/Create' => Http::response(['success' => true, 'requestId' => '12345678-1234-1234-1234-123456789abc'])]);
        $service = app(RobokassaRefundService::class);
        $refund = $service->request($order, $this->intent(), 'robokassa-provenance-key', 1);
        $refund->update(['provider_refund_id' => '99999999-1234-1234-1234-123456789abc']);
        try {
            $service->reconcile($refund);
            $this->fail('Foreign request ID must be rejected before lookup.');
        } catch (DomainException $exception) {
            $this->assertSame('ROBOKASSA_REFUND_PROVENANCE_MISSING', $exception->getMessage());
        }
        Http::assertSentCount(2);
        $this->assertSame('submitted', $refund->fresh()->status);
    }

    public function test_reconcile_command_defaults_to_local_snapshot_without_io_or_mutation(): void
    {
        $order = $this->order();
        $refund = Refund::query()->create(['order_id' => $order->id, 'attempt_id' => $order->active_attempt_id, 'refund_type' => 'full',
            'amount' => 10000, 'currency' => 'RUB', 'status' => 'submitted', 'provider_refund_id' => '12345678-1234-1234-1234-123456789abc', 'policy_access_action' => 'revoke']);
        $before = $refund->fresh()->getAttributes();
        $orderBefore = $order->fresh()->getAttributes();
        $this->artisan('payments:robokassa:reconcile-refund', ['refund' => $refund->id])
            ->expectsOutputToContain('local_snapshot_only')->assertSuccessful();
        $this->assertSame($before, $refund->fresh()->getAttributes());
        $this->assertSame($orderBefore, $order->fresh()->getAttributes());
        Http::assertNothingSent();
        $this->artisan('payments:robokassa:reconcile-refund', ['refund' => $refund->id, '--apply' => true])
            ->expectsOutputToContain('ROBOKASSA_REFUND_PROVENANCE_MISSING')->assertFailed();
        Http::assertNothingSent();
    }

    public function test_reconcile_command_applies_finished_only_with_matching_submission_provenance(): void
    {
        $order = $this->order();
        $id = '12345678-1234-1234-1234-123456789abc';
        Http::fake(['*OpStateExt*' => Http::response($this->xml($order)),
            '*/Create' => Http::response(['success' => true, 'requestId' => $id]),
            '*GetState*' => Http::response(['requestId' => $id, 'label' => 'finished', 'amount' => '100.000000'])]);
        $refund = app(RobokassaRefundService::class)->request($order, $this->intent(), 'robokassa-command-key', 1);
        $this->assertSame('submitted', $refund->status);
        $this->artisan('payments:robokassa:reconcile-refund', ['refund' => $refund->id, '--apply' => true])
            ->expectsOutputToContain('completed')->assertSuccessful();
        $this->assertSame('completed', $refund->fresh()->status);
        $this->assertSame('refunded', $order->fresh()->payment_status);
        Http::assertSentCount(3);
    }

    private function adapter(): RobokassaPaymentProviderAdapter
    {
        return new RobokassaPaymentProviderAdapter('robokassa', '');
    }

    private function body(string $amount, string $invoice, string $order): string
    {
        return http_build_query(['OutSum' => $amount, 'InvId' => $invoice, 'Shp_order' => $order, 'Shp_test' => '0',
            'SignatureValue' => md5($amount.':'.$invoice.':second:Shp_order='.$order.':Shp_test=0')]);
    }

    private function intent(): array
    {
        return ['refund_type' => 'full', 'policy_access_action' => 'revoke', 'reason' => 'test'];
    }

    private function xml(Order $order): string
    {
        return '<OperationStateResponse><Result><Code>0</Code></Result><State><Code>100</Code></State><Info><OutSum>100.000000</OutSum><OpKey>operation-1</OpKey></Info><UserFields><Field><Name>Shp_order</Name><Value>'.$order->id.'</Value></Field><Field><Name>Shp_test</Name><Value>0</Value></Field></UserFields></OperationStateResponse>';
    }

    private function order(): Order
    {
        $order = Order::query()->create(['amount_total' => 10000, 'amount_final' => 10000, 'amount_paid' => 10000,
            'payment_status' => 'paid', 'settlement_type' => 'provider_payment', 'currency' => 'RUB']);
        $order->items()->create(['sellable_kind' => 'course', 'sellable_id' => 1, 'purchased_entity_type' => 'course',
            'purchased_entity_id' => 1, 'display_title' => 'Course', 'quantity' => 1, 'unit_amount' => 10000,
            'subtotal_amount' => 10000, 'final_amount' => 10000, 'snapshot_hash' => str_repeat('a', 64), 'grant_snapshot' => []]);
        $attempt = PaymentAttempt::query()->create(['order_id' => $order->id, 'provider' => 'robokassa', 'amount' => 10000,
            'currency' => 'RUB', 'status' => 'completed']);
        $attempt->update($this->adapter()->startPayment(['order_id' => $order->id, 'attempt_id' => $attempt->id, 'amount' => 10000, 'currency' => 'RUB']));
        $order->update(['active_attempt_id' => $attempt->id]);

        return $order;
    }

    private function pendingOrder(): Order
    {
        $subject = Subject::query()->create(['slug' => 'robo-subject', 'title' => 'Subject', 'is_active' => true]);
        $course = Course::query()->create(['subject_id' => $subject->id, 'slug' => 'robo-course', 'title' => 'Course', 'price_cents' => 10000, 'is_active' => true]);
        $lesson = Lesson::query()->create(['course_id' => $course->id, 'slug' => 'robo-lesson', 'title' => 'Lesson', 'is_active' => true]);
        $order = $this->order();
        $order->update(['user_id' => 1, 'payment_status' => 'pending_payment', 'amount_paid' => 0, 'settlement_type' => null]);
        $item = $order->items()->firstOrFail();
        $item->update(['grant_snapshot' => ['version' => 1, 'atoms' => [['kind' => 'lesson', 'id' => $lesson->id]]]]);
        $item->update(['snapshot_hash' => OrderItemSnapshotService::snapshotHash($item->getAttributesForSnapshot())]);

        return $order;
    }
}
