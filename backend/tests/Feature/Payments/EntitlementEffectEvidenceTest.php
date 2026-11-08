<?php

namespace Tests\Feature\Payments;

use App\Jobs\ProcessPaymentSideEffectJob;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\Order;
use App\Models\OutboxEvent;
use App\Models\PaymentAttempt;
use App\Models\Refund;
use App\Models\SideEffectExecution;
use App\Models\Subject;
use App\Models\User;
use App\Models\UserEntitlementGrant;
use App\Services\EntitlementGrantService;
use App\Services\OrderItemSnapshotService;
use App\Services\OrderSettlementService;
use App\Services\Payments\EntitlementEffectEvidenceService;
use App\Services\Payments\FiscalReceiptService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class EntitlementEffectEvidenceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        $this->travelTo(now()->startOfSecond());
        Queue::fake();
    }

    public function test_normal_grant_completion_uses_existing_evidence_and_duplicate_is_noop(): void
    {
        [$order, $attempt] = $this->fixture();
        $effect = $this->effect($order, $attempt, 'grant');
        $before = UserEntitlementGrant::query()->get()->toArray();
        $this->execute($effect);
        $this->assertSame('completed', $effect->fresh()->status);
        $this->assertSame('grant_verified', $effect->fresh()->meta['evidence']['outcome']);
        $proof = $effect->fresh()->toArray();
        $this->travel(1)->minutes();
        $this->execute($effect);
        $this->assertSame($proof, $effect->fresh()->toArray());
        $this->assertSame($before, UserEntitlementGrant::query()->get()->toArray());
    }

    public function test_job_dispatches_verification_and_only_completes_fully_delivered_outbox(): void
    {
        foreach ([false, true] as $pendingSibling) {
            [$order, $attempt] = $this->fixture();
            $effect = $this->effect($order, $attempt, 'grant');
            if ($pendingSibling) {
                SideEffectExecution::query()->create([
                    'outbox_event_id' => $effect->outbox_event_id, 'order_id' => $order->id,
                    'attempt_id' => $attempt->id, 'handler' => 'manager_task.create',
                    'dedup_key' => "order:{$order->id}:to:paid:manager_task",
                    'status' => 'blocked_handler', 'meta' => ['delivery_version' => 1],
                ]);
            }
            $before = UserEntitlementGrant::query()->get()->toArray();
            (new ProcessPaymentSideEffectJob($effect->id))->handle(app(FiscalReceiptService::class));
            $this->assertSame('completed', $effect->fresh()->status);
            $this->assertTrue($effect->fresh()->meta['verified']);
            $this->assertSame($pendingSibling ? 'pending' : 'processed', OutboxEvent::findOrFail($effect->outbox_event_id)->status);
            $this->assertSame($before, UserEntitlementGrant::query()->get()->toArray());
        }
    }

    public function test_normal_full_refund_revoke_preserves_independent_access(): void
    {
        [$order, $attempt, $grant] = $this->fixture();
        app(EntitlementGrantService::class)->grantAtoms($order->user_id, [['kind' => $grant->atom_kind, 'id' => $grant->atom_id]],
            UserEntitlementGrant::SOURCE_ADMIN_GRANT, 'independent', 'independent');
        $refund = $this->refund($order, $attempt, 10000, true);
        $effect = $this->effect($order->fresh(), $attempt, 'revoke', $refund);
        $before = UserEntitlementGrant::query()->get()->toArray();
        $this->execute($effect);
        $this->assertSame('completed', $effect->fresh()->status);
        $this->assertSame('revocation_verified', $effect->fresh()->meta['evidence']['outcome']);
        $this->assertSame($before, UserEntitlementGrant::query()->get()->toArray());
        $this->assertDatabaseHas('user_entitlement_grants', ['idempotency_key' => 'independent', 'status' => 'active']);
    }

    public function test_normal_partial_refund_keep_uses_exact_completed_refund(): void
    {
        [$order, $attempt] = $this->fixture();
        $refund = $this->refund($order, $attempt, 1000, false);
        $effect = $this->effect($order->fresh(), $attempt, 'keep', $refund);
        $this->execute($effect);
        $this->assertSame('completed', $effect->fresh()->status);
        $this->assertSame('keep_verified', $effect->fresh()->meta['evidence']['outcome']);
        $this->assertDatabaseHas('user_entitlement_grants', ['order_id' => $order->id, 'status' => 'active']);
    }

    public function test_late_paid_effect_after_full_refund_never_regrants(): void
    {
        [$order, $attempt] = $this->fixture();
        $effect = $this->effect($order, $attempt, 'grant');
        $this->travel(1)->minutes();
        $this->refund($order, $attempt, 10000, true);
        $before = UserEntitlementGrant::query()->get()->toArray();
        $this->execute($effect);
        $this->assertSame('completed', $effect->fresh()->status);
        $this->assertSame('historical_grant_verified_then_refunded', $effect->fresh()->meta['evidence']['outcome']);
        $this->assertSame($before, UserEntitlementGrant::query()->get()->toArray());
        $this->assertSame('refunded', $order->fresh()->payment_status);
    }

    public function test_late_keep_effect_after_later_full_refund_is_historically_verified(): void
    {
        [$order, $attempt] = $this->fixture();
        $partial = $this->refund($order, $attempt, 1000, false);
        $effect = $this->effect($order->fresh(), $attempt, 'keep', $partial);
        $this->travel(1)->minutes();
        $this->refund($order->fresh(), $attempt, 9000, true);
        $this->execute($effect);
        $this->assertSame('completed', $effect->fresh()->status);
        $this->assertSame('keep_superseded_by_full_refund', $effect->fresh()->meta['evidence']['outcome']);
        $this->assertDatabaseHas('user_entitlement_grants', ['order_id' => $order->id, 'status' => 'revoked']);
    }

    public function test_missing_atoms_do_not_pass_using_independent_purchase_access(): void
    {
        [$order, $attempt, $grant] = $this->fixture();
        app(EntitlementGrantService::class)->grantAtoms($order->user_id, [['kind' => $grant->atom_kind, 'id' => $grant->atom_id]],
            UserEntitlementGrant::SOURCE_ADMIN_GRANT, 'other-source', 'other-source');
        $grant->delete();
        $effect = $this->effect($order, $attempt, 'grant');
        $this->execute($effect);
        $this->assertSame('manual_review', $effect->fresh()->status);
        $this->assertSame('ENTITLEMENT_GRANTS_MISSING', $effect->fresh()->meta['diagnostic']);
        $this->assertDatabaseCount('user_entitlement_grants', 1);
    }

    public function test_wrong_owner_and_wrong_settlement_source_are_not_proof(): void
    {
        foreach (['owner', 'settlement'] as $mutation) {
            [$order, $attempt, $grant] = $this->fixture();
            $grant->update($mutation === 'owner'
                ? ['user_id' => User::factory()->create()->id]
                : ['metadata' => [...$grant->metadata, 'settlement_id' => 999999]]);
            $effect = $this->effect($order, $attempt, 'grant');
            $this->execute($effect);
            $this->assertSame('manual_review', $effect->fresh()->status);
            $this->assertSame('ENTITLEMENT_GRANT_SOURCE_MISMATCH', $effect->fresh()->meta['diagnostic']);
        }
    }

    public function test_refund_from_another_order_and_effect_event_mismatch_reject(): void
    {
        [$order, $attempt] = $this->fixture();
        [$other, $otherAttempt] = $this->fixture();
        $foreignRefund = $this->refund($other, $otherAttempt, 1000, false);
        $effect = $this->effect($order, $attempt, 'keep', $foreignRefund);
        $this->execute($effect);
        $this->assertSame('manual_review', $effect->fresh()->status);
        $this->assertSame('ENTITLEMENT_REFUND_IDENTITY_MISMATCH', $effect->fresh()->meta['diagnostic']);
        $grantEffect = $this->effect($order, $attempt, 'grant');
        OutboxEvent::query()->whereKey($grantEffect->outbox_event_id)->update(['order_id' => $other->id]);
        $this->execute($grantEffect);
        $this->assertSame('ENTITLEMENT_EFFECT_IDENTITY_MISMATCH', $grantEffect->fresh()->meta['diagnostic']);
    }

    public function test_fake_summary_revocation_without_full_refund_is_not_completed(): void
    {
        [$order, $attempt] = $this->fixture();
        $refund = $this->refund($order, $attempt, 1000, true);
        $effect = $this->effect($order->fresh(), $attempt, 'revoke', $refund);
        $this->execute($effect);
        $this->assertSame('manual_review', $effect->fresh()->status);
        $this->assertSame('ENTITLEMENT_FULL_REFUND_NOT_PROVEN', $effect->fresh()->meta['diagnostic']);
    }

    public function test_pending_or_failed_grant_is_not_synthetic_completion(): void
    {
        foreach (['pending' => 'awaiting_entitlement', 'failed' => 'manual_review'] as $grantStatus => $expected) {
            [$order, $attempt] = $this->fixture();
            $settlement = $order->settlements()->sole();
            $settlement->update(['metadata' => [...$settlement->metadata, 'grant_status' => $grantStatus]]);
            $effect = $this->effect($order, $attempt, 'grant');
            $this->execute($effect);
            $this->assertSame($expected, $effect->fresh()->status);
            $this->assertFalse($effect->fresh()->meta['verified']);
        }
    }

    public function test_legacy_completed_marker_is_untouched_and_unverified_pending_is_not_replayed(): void
    {
        [$order, $attempt] = $this->fixture();
        $effect = $this->effect($order, $attempt, 'grant');
        $effect->update(['status' => 'completed', 'meta' => []]);
        $before = $effect->fresh()->toArray();
        $this->execute($effect);
        $this->assertSame($before, $effect->fresh()->toArray());
        $effect->update(['status' => 'pending']);
        $this->execute($effect);
        $this->assertSame('legacy_unverified', $effect->fresh()->status);
        $this->assertArrayNotHasKey('verified', $effect->fresh()->meta);
    }

    public function test_changed_atom_snapshot_and_wrong_atom_cannot_be_verified(): void
    {
        foreach (['snapshot', 'atom'] as $mutation) {
            [$order, $attempt, $grant] = $this->fixture();
            if ($mutation === 'snapshot') {
                $order->items()->update(['grant_snapshot' => ['atoms' => [['kind' => 'guide', 'id' => 999999]]]]);
            } else {
                $grant->update(['atom_id' => 999999]);
            }
            $effect = $this->effect($order, $attempt, 'grant');
            $this->execute($effect);
            $this->assertSame('manual_review', $effect->fresh()->status);
            $this->assertSame($mutation === 'snapshot' ? 'ENTITLEMENT_ITEM_SNAPSHOT_MISMATCH' : 'ENTITLEMENT_GRANT_ATOM_MISMATCH', $effect->fresh()->meta['diagnostic']);
        }
    }

    public function test_revocation_requires_timestamp_and_refund_causal_order(): void
    {
        foreach (['missing', 'future'] as $mutation) {
            [$order, $attempt, $grant] = $this->fixture();
            $refund = $this->refund($order, $attempt, 10000, true);
            $grant->fresh()->update(['revoked_at' => $mutation === 'missing' ? null : now()->addHour()]);
            $effect = $this->effect($order->fresh(), $attempt, 'revoke', $refund);
            $this->execute($effect);
            $this->assertSame('manual_review', $effect->fresh()->status);
            $this->assertSame('ENTITLEMENT_REVOCATION_NOT_PROVEN', $effect->fresh()->meta['diagnostic']);
        }
    }

    public function test_partial_refund_inconsistent_money_or_unconfirmed_status_is_not_proof(): void
    {
        foreach (['money', 'status'] as $mutation) {
            [$order, $attempt] = $this->fixture();
            $refund = $this->refund($order, $attempt, 1000, false);
            if ($mutation === 'money') {
                $order->update(['amount_refunded' => 2000]);
            } else {
                $refund->update(['status' => 'submitted']);
            }
            $effect = $this->effect($order->fresh(), $attempt, 'keep', $refund);
            $this->execute($effect);
            $this->assertSame('manual_review', $effect->fresh()->status);
            $this->assertFalse($effect->fresh()->meta['verified']);
        }
    }

    public function test_refund_completion_order_uses_completion_evidence_not_request_id(): void
    {
        [$order, $attempt] = $this->fixture();
        $earlyRequest = Refund::query()->create(['order_id' => $order->id, 'attempt_id' => $attempt->id,
            'refund_type' => 'full', 'amount' => 9000, 'currency' => 'RUB', 'status' => 'submitted',
            'reason' => 'early request', 'policy_access_action' => 'revoke']);
        $partial = $this->refund($order, $attempt, 1000, false);
        $effect = $this->effect($order->fresh(), $attempt, 'keep', $partial);
        $this->travel(1)->minutes();
        app(EntitlementGrantService::class)->revokeOrderGrants($order->user_id, $order->id,
            $order->items()->pluck('id')->all(), 'refund:'.$earlyRequest->id);
        $order->items()->update(['access_status' => 'revoked']);
        $order->update(['amount_refunded' => 10000, 'payment_status' => 'refunded', 'access_status' => 'revoked']);
        $earlyRequest->update(['status' => 'completed', 'completed_at' => now()]);
        $this->execute($effect);
        $this->assertSame('completed', $effect->fresh()->status);
        $this->assertSame('keep_superseded_by_full_refund', $effect->fresh()->meta['evidence']['outcome']);
    }

    private function fixture(): array
    {
        $user = User::factory()->create();
        $subject = Subject::query()->create(['slug' => 'evidence-'.Str::uuid(), 'title' => 'Subject', 'sort_order' => 1, 'is_active' => true]);
        $course = Course::query()->create(['subject_id' => $subject->id, 'slug' => 'evidence-'.Str::uuid(),
            'title' => 'Course', 'short_title' => 'Course', 'price_cents' => 10000, 'sort_order' => 1, 'is_active' => true]);
        Lesson::query()->create(['course_id' => $course->id, 'slug' => 'lesson', 'title' => 'Lesson', 'sort_order' => 1, 'is_active' => true]);
        $order = Order::query()->create(['user_id' => $user->id, 'course_id' => $course->id,
            'payment_status' => 'pending_payment', 'access_status' => 'not_granted', 'currency' => 'RUB',
            'amount_total' => 10000, 'amount_discount' => 0, 'amount_final' => 10000, 'amount_paid' => 0, 'amount_refunded' => 0]);
        app(OrderItemSnapshotService::class)->createCourseItem($order, $course);
        $attempt = PaymentAttempt::query()->create(['order_id' => $order->id, 'provider' => 'test',
            'status' => 'pending', 'amount' => 10000, 'currency' => 'RUB']);
        $order->update(['active_attempt_id' => $attempt->id]);
        app(OrderSettlementService::class)->confirmPaid($order, ['amount' => 10000, 'currency' => 'RUB',
            'idempotency_key' => 'settlement:'.$order->id, 'payment_attempt_id' => $attempt->id, 'settlement_type' => 'provider_payment']);

        return [$order->fresh(), $attempt, UserEntitlementGrant::query()->where('order_id', $order->id)->sole()];
    }

    private function refund(Order $order, PaymentAttempt $attempt, int $amount, bool $full): Refund
    {
        $refund = Refund::query()->create(['order_id' => $order->id, 'attempt_id' => $attempt->id,
            'refund_type' => $full ? 'full' : 'partial', 'amount' => $amount, 'currency' => 'RUB',
            'status' => 'completed', 'completed_at' => now(), 'reason' => 'fixture', 'policy_access_action' => $full ? 'revoke' : 'keep']);
        $order->update(['amount_refunded' => (int) $order->amount_refunded + $amount]);
        if ($full) {
            app(EntitlementGrantService::class)->revokeOrderGrants($order->user_id, $order->id, $order->items()->pluck('id')->all(), 'refund:'.$refund->id);
            $order->items()->update(['access_status' => 'revoked']);
            $order->update(['payment_status' => 'refunded', 'access_status' => 'revoked']);
        }

        return $refund;
    }

    private function effect(Order $order, PaymentAttempt $attempt, string $action, ?Refund $refund = null): SideEffectExecution
    {
        $event = OutboxEvent::query()->create(['order_id' => $order->id, 'attempt_id' => $attempt->id,
            'refund_id' => $refund?->id, 'event_type' => 'payment.transition.applied', 'status' => 'pending',
            'payload' => ['from_status' => $action === 'grant' ? 'pending_payment' : 'paid',
                'to_status' => $order->payment_status, 'access_status' => $order->access_status], 'available_at' => now()]);

        return SideEffectExecution::query()->create(['outbox_event_id' => $event->id, 'order_id' => $order->id,
            'attempt_id' => $attempt->id, 'refund_id' => $refund?->id, 'handler' => 'entitlement.'.$action,
            'dedup_key' => $action === 'grant' ? "order:{$order->id}:to:paid:entitlement"
                : "order:{$order->id}:refund:{$refund->id}:entitlement:{$order->access_status}",
            'status' => 'pending', 'meta' => ['delivery_version' => 1]]);
    }

    private function execute(SideEffectExecution $effect): void
    {
        app(EntitlementEffectEvidenceService::class)->execute($effect->id);
    }
}
