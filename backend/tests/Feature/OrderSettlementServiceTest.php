<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Lesson;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderSettlement;
use App\Models\SellableEntity;
use App\Models\SellableGrantRule;
use App\Models\Subject;
use App\Models\User;
use App\Models\UserEntitlementGrant;
use App\Services\AccessResolver;
use App\Services\EntitlementGrantService;
use App\Services\OrderItemSnapshotService;
use App\Services\OrderSettlementService;
use App\Services\PaymentStateMachine;
use DomainException;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class OrderSettlementServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_without_backend_payment_confirmation_does_not_grant_access(): void
    {
        $user = User::factory()->create(['role' => 'user_no_purchase']);
        [, $course] = $this->createCourseWithLessons(lessonCount: 1);
        $this->createPendingCourseOrder($user, $course);

        $access = app(AccessResolver::class)->check($user, AccessResolver::ENTITY_COURSE, $course->id);

        $this->assertSame(AccessResolver::ACCESS_NONE, $access['access']);
        $this->assertDatabaseCount('user_entitlement_grants', 0);
    }

    public function test_confirmed_order_grants_snapshot_access(): void
    {
        $user = User::factory()->create(['role' => 'user_no_purchase']);
        [, $course, $lessons] = $this->createCourseWithLessons(lessonCount: 2);
        $order = $this->createPendingCourseOrder($user, $course);

        app(OrderSettlementService::class)->confirmPaid($order, [
            'idempotency_key' => 'settlement-confirmed-order',
            'amount' => 150000,
            'currency' => 'RUB',
            'settlement_type' => OrderSettlement::TYPE_BACKEND_CONFIRMATION,
        ]);

        $this->assertSame('paid', (string) $order->fresh()->payment_status);
        $this->assertSame('granted', (string) $order->fresh()->access_status);
        $this->assertDatabaseHas('user_entitlement_grants', [
            'user_id' => $user->id,
            'atom_kind' => UserEntitlementGrant::ATOM_LESSON,
            'atom_id' => $lessons[0]->id,
            'source_type' => UserEntitlementGrant::SOURCE_ORDER_ITEM,
            'order_id' => $order->id,
        ]);
        $this->assertDatabaseHas('user_entitlement_grants', [
            'user_id' => $user->id,
            'atom_kind' => UserEntitlementGrant::ATOM_LESSON,
            'atom_id' => $lessons[1]->id,
            'source_type' => UserEntitlementGrant::SOURCE_ORDER_ITEM,
            'order_id' => $order->id,
        ]);

        $access = app(AccessResolver::class)->check($user, AccessResolver::ENTITY_COURSE, $course->id);

        $this->assertSame(AccessResolver::ACCESS_FULL, $access['access']);
        $this->assertSame(2, $access['owned_atoms_count']);
    }

    public function test_confirmed_payment_stays_paid_and_marks_grant_failed_when_granting_fails(): void
    {
        $user = User::factory()->create(['role' => 'user_no_purchase']);
        [, $course] = $this->createCourseWithLessons(lessonCount: 1);
        $order = $this->createPendingCourseOrder($user, $course);

        $this->mock(EntitlementGrantService::class, function ($mock): void {
            $mock->shouldReceive('grantAtoms')
                ->once()
                ->andThrow(new RuntimeException('grant backend unavailable'));
        });

        $settlement = app(OrderSettlementService::class)->confirmPaid($order, [
            'idempotency_key' => 'settlement-grant-failure',
            'amount' => 150000,
            'currency' => 'RUB',
            'settlement_type' => OrderSettlement::TYPE_BACKEND_CONFIRMATION,
        ]);

        $this->assertSame('paid', (string) $order->fresh()->payment_status);
        $this->assertSame('grant_failed', (string) $order->fresh()->access_status);
        $this->assertSame('failed', $settlement->fresh()->metadata['grant_status']);
        $this->assertSame(RuntimeException::class, $settlement->fresh()->metadata['grant_error']['class']);
        $this->assertDatabaseHas('order_settlements', [
            'order_id' => $order->id,
            'status' => OrderSettlement::STATUS_CONFIRMED,
        ]);
        $this->assertDatabaseHas('order_items', [
            'order_id' => $order->id,
            'access_status' => OrderItem::ACCESS_GRANT_FAILED,
        ]);
        $this->assertDatabaseCount('user_entitlement_grants', 0);
    }

    public function test_grant_batch_rolls_back_completely_and_can_be_retried(): void
    {
        $user = User::factory()->create(['role' => 'user_no_purchase']);
        [$subject, $course, $lessons] = $this->createCourseWithLessons(lessonCount: 2);
        $order = $this->createPendingOrder($user, amount: 200000, course: $course);
        $this->createSnapshotItem(
            $order,
            OrderItem::KIND_SUBJECT,
            $subject->id,
            $subject->slug,
            'Subject snapshot',
            [['kind' => UserEntitlementGrant::ATOM_LESSON, 'id' => $lessons[0]->id]]
        );
        $this->createSnapshotItem(
            $order,
            OrderItem::KIND_BOX,
            9002,
            'box-atomic-grant',
            'Box snapshot',
            [['kind' => UserEntitlementGrant::ATOM_LESSON, 'id' => $lessons[1]->id]]
        );

        $realGrantService = new EntitlementGrantService(app(AccessResolver::class));
        $realGrantService->grantAtoms(
            $user->id,
            [['kind' => UserEntitlementGrant::ATOM_LESSON, 'id' => $lessons[0]->id]]
        );

        $grantCalls = 0;
        $failingGrantService = \Mockery::mock(EntitlementGrantService::class);
        $failingGrantService->shouldReceive('grantAtoms')
            ->twice()
            ->andReturnUsing(function (...$arguments) use (&$grantCalls, $realGrantService): array {
                $grantCalls++;
                if ($grantCalls === 2) {
                    throw new RuntimeException('second grant item failed');
                }

                return $realGrantService->grantAtoms(...$arguments);
            });
        $this->app->instance(EntitlementGrantService::class, $failingGrantService);

        $intent = [
            'idempotency_key' => 'settlement-atomic-grant-retry',
            'amount' => 200000,
            'currency' => 'RUB',
            'settlement_type' => OrderSettlement::TYPE_BACKEND_CONFIRMATION,
        ];
        $settlement = app(OrderSettlementService::class)->confirmPaid($order, $intent);

        $this->assertSame('paid', (string) $order->fresh()->payment_status);
        $this->assertSame('grant_failed', (string) $order->fresh()->access_status);
        $this->assertSame(0, $settlement->fresh()->metadata['granted_items_count']);
        $this->assertSame(0, $settlement->fresh()->metadata['created_grants_count']);
        $this->assertSame(2, OrderItem::query()
            ->where('order_id', $order->id)
            ->where('access_status', OrderItem::ACCESS_GRANT_FAILED)
            ->count());
        $this->assertSame(1, UserEntitlementGrant::query()->where('user_id', $user->id)->count());
        $this->assertDatabaseMissing('user_entitlement_grants', [
            'user_id' => $user->id,
            'source_type' => UserEntitlementGrant::SOURCE_ORDER_ITEM,
            'order_id' => $order->id,
        ]);

        $this->app->instance(EntitlementGrantService::class, $realGrantService);
        $retriedSettlement = app(OrderSettlementService::class)->confirmPaid($order->fresh(), $intent);

        $this->assertSame($settlement->id, $retriedSettlement->id);
        $this->assertSame('granted', (string) $order->fresh()->access_status);
        $this->assertSame('granted', $retriedSettlement->fresh()->metadata['grant_status']);
        $this->assertArrayNotHasKey('grant_error', $retriedSettlement->fresh()->metadata);
        $this->assertSame(2, OrderItem::query()
            ->where('order_id', $order->id)
            ->where('access_status', OrderItem::ACCESS_GRANTED)
            ->count());
        $this->assertSame(2, UserEntitlementGrant::query()
            ->where('user_id', $user->id)
            ->where('source_type', UserEntitlementGrant::SOURCE_ORDER_ITEM)
            ->where('order_id', $order->id)
            ->count());
        $this->assertSame(3, UserEntitlementGrant::query()->where('user_id', $user->id)->count());

        app(OrderSettlementService::class)->confirmPaid($order->fresh(), $intent);
        $this->assertSame(3, UserEntitlementGrant::query()->where('user_id', $user->id)->count());
    }

    public function test_failure_handler_does_not_downgrade_parallel_granted_settlement_after_rollback(): void
    {
        $user = User::factory()->create(['role' => 'user_no_purchase']);
        [, $course, $lessons] = $this->createCourseWithLessons(lessonCount: 1);
        $order = $this->createPendingCourseOrder($user, $course);
        $item = $order->items()->firstOrFail();
        $realGrantService = new EntitlementGrantService(app(AccessResolver::class));
        $interleavingApplied = false;

        Event::listen(
            TransactionRolledBack::class,
            function () use (
                &$interleavingApplied,
                $item,
                $lessons,
                $order,
                $realGrantService,
                $user
            ): void {
                if ($interleavingApplied) {
                    return;
                }

                $interleavingApplied = true;
                $settlement = OrderSettlement::query()->where('order_id', $order->id)->firstOrFail();
                $result = $realGrantService->grantAtoms(
                    $user->id,
                    [['kind' => UserEntitlementGrant::ATOM_LESSON, 'id' => $lessons[0]->id]],
                    UserEntitlementGrant::SOURCE_ORDER_ITEM,
                    "order_item:{$item->id}",
                    "order_item:{$item->id}:grant:v1",
                    UserEntitlementGrant::STATUS_ACTIVE,
                    $order->id,
                    $item->id,
                    [
                        'settlement_id' => $settlement->id,
                        'snapshot_hash' => (string) $item->snapshot_hash,
                        'sellable_kind' => (string) $item->sellable_kind,
                        'purchased_entity_type' => (string) $item->purchased_entity_type,
                    ]
                );

                $item->fresh()->forceFill(['access_status' => OrderItem::ACCESS_GRANTED])->save();
                $order->fresh()->forceFill(['access_status' => 'granted'])->save();
                $settlement->forceFill([
                    'metadata' => [
                        ...($settlement->metadata ?? []),
                        'grant_status' => 'granted',
                        'granted_items_count' => 1,
                        'created_grants_count' => $result['created_count'],
                    ],
                ])->save();
            }
        );

        $this->mock(EntitlementGrantService::class, function ($mock): void {
            $mock->shouldReceive('grantAtoms')
                ->once()
                ->andThrow(new RuntimeException('first attempt failed before parallel commit'));
        });

        try {
            $settlement = app(OrderSettlementService::class)->confirmPaid($order, [
                'idempotency_key' => 'settlement-parallel-success-after-rollback',
                'amount' => 150000,
                'currency' => 'RUB',
                'settlement_type' => OrderSettlement::TYPE_BACKEND_CONFIRMATION,
            ]);
        } finally {
            Event::forget(TransactionRolledBack::class);
        }

        $this->assertTrue($interleavingApplied);
        $this->assertSame('paid', (string) $order->fresh()->payment_status);
        $this->assertSame('granted', (string) $order->fresh()->access_status);
        $this->assertSame(OrderItem::ACCESS_GRANTED, (string) $item->fresh()->access_status);
        $this->assertSame('granted', $settlement->fresh()->metadata['grant_status']);
        $this->assertArrayNotHasKey('grant_error', $settlement->fresh()->metadata);
        $this->assertDatabaseHas('user_entitlement_grants', [
            'user_id' => $user->id,
            'source_type' => UserEntitlementGrant::SOURCE_ORDER_ITEM,
            'order_id' => $order->id,
            'order_item_id' => $item->id,
        ]);
    }

    public function test_repeated_confirmation_is_idempotent_and_does_not_duplicate_grants(): void
    {
        $user = User::factory()->create(['role' => 'user_no_purchase']);
        [, $course] = $this->createCourseWithLessons(lessonCount: 2);
        $order = $this->createPendingCourseOrder($user, $course);
        $intent = [
            'idempotency_key' => 'settlement-repeatable',
            'amount' => 150000,
            'currency' => 'RUB',
            'settlement_type' => OrderSettlement::TYPE_BACKEND_CONFIRMATION,
        ];

        $first = app(OrderSettlementService::class)->confirmPaid($order, $intent);
        $second = app(OrderSettlementService::class)->confirmPaid($order->fresh(), $intent);

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('order_settlements', 1);
        $this->assertDatabaseCount('user_entitlement_grants', 2);
    }

    public function test_settlement_replay_after_refund_revoke_does_not_regrant_access(): void
    {
        $user = User::factory()->create(['role' => 'user_no_purchase']);
        [, $course, [$lesson]] = $this->createCourseWithLessons(lessonCount: 1);
        $order = $this->createPendingCourseOrder($user, $course);
        $intent = [
            'idempotency_key' => 'settlement-replay-after-refund-revoke',
            'amount' => 150000,
            'currency' => 'RUB',
            'settlement_type' => OrderSettlement::TYPE_BACKEND_CONFIRMATION,
        ];

        app(OrderSettlementService::class)->confirmPaid($order, $intent);

        $grant = UserEntitlementGrant::query()
            ->where('order_id', $order->id)
            ->where('atom_id', $lesson->id)
            ->firstOrFail();
        $grant->forceFill([
            'status' => UserEntitlementGrant::STATUS_REVOKED,
            'revoked_at' => now(),
            'revoked_reason' => 'refund:1:manual_refund',
        ])->save();
        $order->fresh()->forceFill([
            'payment_status' => 'refunded',
            'access_status' => 'revoked',
            'amount_refunded' => 150000,
            'refunded_at' => now(),
        ])->save();
        $order->items()->update(['access_status' => 'revoked']);

        app(OrderSettlementService::class)->confirmPaid($order->fresh(), $intent);

        $this->assertSame('refunded', (string) $order->fresh()->payment_status);
        $this->assertSame('revoked', (string) $order->fresh()->access_status);
        $this->assertSame('revoked', (string) $order->items()->firstOrFail()->access_status);
        $this->assertSame(UserEntitlementGrant::STATUS_REVOKED, (string) $grant->fresh()->status);
        $this->assertSame(1, UserEntitlementGrant::query()
            ->where('order_id', $order->id)
            ->where('atom_id', $lesson->id)
            ->count());

        $access = app(AccessResolver::class)->check($user, AccessResolver::ENTITY_LESSON, (int) $lesson->id);
        $this->assertSame(AccessResolver::ACCESS_NONE, $access['access']);
    }

    public function test_settlement_rejects_order_item_total_mismatch(): void
    {
        $user = User::factory()->create(['role' => 'user_no_purchase']);
        [, $course] = $this->createCourseWithLessons(lessonCount: 1);
        $order = $this->createPendingCourseOrder($user, $course);
        $order->forceFill([
            'amount_total' => 149999,
            'amount_final' => 149999,
        ])->save();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('ORDER_SETTLEMENT_ITEM_TOTAL_MISMATCH');

        app(OrderSettlementService::class)->confirmPaid($order->fresh(), [
            'idempotency_key' => 'settlement-total-mismatch',
            'amount' => 149999,
            'currency' => 'RUB',
            'settlement_type' => OrderSettlement::TYPE_BACKEND_CONFIRMATION,
        ]);
    }

    public function test_settlement_rejects_order_item_currency_mismatch(): void
    {
        $user = User::factory()->create(['role' => 'user_no_purchase']);
        [, $course] = $this->createCourseWithLessons(lessonCount: 1);
        $order = $this->createPendingCourseOrder($user, $course);
        $order->forceFill(['currency' => 'USD'])->save();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('ORDER_SETTLEMENT_ITEM_CURRENCY_MISMATCH');

        app(OrderSettlementService::class)->confirmPaid($order->fresh(), [
            'idempotency_key' => 'settlement-currency-mismatch',
            'amount' => 150000,
            'currency' => 'USD',
            'settlement_type' => OrderSettlement::TYPE_BACKEND_CONFIRMATION,
        ]);
    }

    public function test_payment_state_machine_cannot_mark_paid_access_as_granted_directly(): void
    {
        $user = User::factory()->create(['role' => 'user_no_purchase']);
        $order = $this->createPendingOrder($user, amount: 150000);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('PAYMENT_ACCESS_GRANT_REQUIRES_SETTLEMENT');

        app(PaymentStateMachine::class)->transition($order, 'paid', 'granted');
    }

    public function test_reused_settlement_idempotency_key_with_drift_is_rejected(): void
    {
        $user = User::factory()->create(['role' => 'user_no_purchase']);
        [, $course] = $this->createCourseWithLessons(lessonCount: 1);
        $order = $this->createPendingCourseOrder($user, $course);
        $service = app(OrderSettlementService::class);

        $service->confirmPaid($order, [
            'idempotency_key' => 'settlement-drift',
            'amount' => 150000,
            'currency' => 'RUB',
            'settlement_type' => OrderSettlement::TYPE_BACKEND_CONFIRMATION,
        ]);

        $this->expectException(InvalidArgumentException::class);

        $service->confirmPaid($order->fresh(), [
            'idempotency_key' => 'settlement-drift',
            'amount' => 149999,
            'currency' => 'RUB',
            'settlement_type' => OrderSettlement::TYPE_BACKEND_CONFIRMATION,
        ]);
    }

    public function test_course_order_item_snapshots_active_lessons(): void
    {
        $user = User::factory()->create(['role' => 'user_no_purchase']);
        [, $course, $lessons] = $this->createCourseWithLessons(lessonCount: 3);
        $order = $this->createPendingCourseOrder($user, $course);

        $item = $order->items()->firstOrFail();

        $this->assertSame(OrderItem::KIND_COURSE, $item->sellable_kind);
        $this->assertSame(
            collect($lessons)->pluck('id')->map(fn (int $id): array => [
                'kind' => UserEntitlementGrant::ATOM_LESSON,
                'id' => $id,
            ])->all(),
            $item->grant_snapshot['atoms']
        );
        $this->assertSame(
            OrderItemSnapshotService::snapshotHash($item->getAttributesForSnapshot()),
            $item->snapshot_hash
        );
    }

    public function test_sellable_entity_order_item_settles_from_saved_grant_preview(): void
    {
        $user = User::factory()->create(['role' => 'user_no_purchase']);
        [, $course, $lessons] = $this->createCourseWithLessons(lessonCount: 2);
        $order = $this->createPendingOrder($user, amount: 150000);
        $sellable = SellableEntity::query()->create([
            'sellable_kind' => OrderItem::KIND_COURSE,
            'entity_type' => OrderItem::KIND_COURSE,
            'entity_id' => $course->id,
            'sku' => $course->itemId(),
            'title' => $course->title,
            'currency' => 'RUB',
            'price_cents' => 150000,
            'price_version' => 1,
            'status' => SellableEntity::STATUS_ACTIVE,
            'metadata' => ['slug' => $course->slug],
        ]);

        foreach ($lessons as $index => $lesson) {
            SellableGrantRule::query()->create([
                'sellable_entity_id' => $sellable->id,
                'atom_kind' => UserEntitlementGrant::ATOM_LESSON,
                'atom_id' => $lesson->id,
                'grant_snapshot_version' => 1,
                'sort_order' => $index + 1,
            ]);
        }

        $item = app(OrderItemSnapshotService::class)->createSellableItem($order, $sellable);

        app(OrderSettlementService::class)->confirmPaid($order->fresh(), [
            'idempotency_key' => 'settlement-sellable-preview',
            'amount' => 150000,
            'currency' => 'RUB',
            'settlement_type' => OrderSettlement::TYPE_BACKEND_CONFIRMATION,
        ]);

        $this->assertSame($sellable->id, $item->sellable_id);
        $this->assertSame($course->id, $item->purchased_entity_id);
        $this->assertSame($course->slug, $item->purchased_entity_slug);
        $this->assertSame(
            OrderItemSnapshotService::snapshotHash($item->fresh()->getAttributesForSnapshot()),
            $item->fresh()->snapshot_hash
        );
        $this->assertDatabaseHas('user_entitlement_grants', [
            'user_id' => $user->id,
            'atom_kind' => UserEntitlementGrant::ATOM_LESSON,
            'atom_id' => $lessons[0]->id,
            'source_type' => UserEntitlementGrant::SOURCE_ORDER_ITEM,
            'order_id' => $order->id,
            'order_item_id' => $item->id,
        ]);
        $this->assertDatabaseHas('user_entitlement_grants', [
            'user_id' => $user->id,
            'atom_kind' => UserEntitlementGrant::ATOM_LESSON,
            'atom_id' => $lessons[1]->id,
            'source_type' => UserEntitlementGrant::SOURCE_ORDER_ITEM,
            'order_id' => $order->id,
            'order_item_id' => $item->id,
        ]);

        $access = app(AccessResolver::class)->check($user, AccessResolver::ENTITY_COURSE, $course->id);

        $this->assertSame(AccessResolver::ACCESS_FULL, $access['access']);
    }

    public function test_subject_and_box_items_grant_only_saved_snapshot_atoms(): void
    {
        $user = User::factory()->create(['role' => 'user_no_purchase']);
        [$subject, $course, $lessons] = $this->createCourseWithLessons(lessonCount: 2);
        $order = $this->createPendingOrder($user, amount: 200000);

        $this->createSnapshotItem($order, OrderItem::KIND_SUBJECT, $subject->id, $subject->slug, 'Subject snapshot', [
            ['kind' => UserEntitlementGrant::ATOM_LESSON, 'id' => $lessons[0]->id],
        ]);
        $this->createSnapshotItem($order, OrderItem::KIND_BOX, 9001, 'box-snapshot', 'Box snapshot', [
            ['kind' => UserEntitlementGrant::ATOM_LESSON, 'id' => $lessons[1]->id],
        ]);
        $extraLesson = Lesson::query()->create([
            'course_id' => $course->id,
            'slug' => 'lesson-extra-'.Str::uuid(),
            'title' => 'Extra lesson after snapshot',
            'sort_order' => 99,
            'is_active' => true,
        ]);

        app(OrderSettlementService::class)->confirmPaid($order, [
            'idempotency_key' => 'settlement-subject-box-snapshot',
            'amount' => 200000,
            'currency' => 'RUB',
            'settlement_type' => OrderSettlement::TYPE_BACKEND_CONFIRMATION,
        ]);

        $this->assertDatabaseMissing('user_entitlement_grants', [
            'user_id' => $user->id,
            'atom_kind' => UserEntitlementGrant::ATOM_LESSON,
            'atom_id' => $extraLesson->id,
        ]);
        $this->assertDatabaseCount('user_entitlement_grants', 2);

        $boxAccess = app(AccessResolver::class)->check($user, AccessResolver::ENTITY_BOX, null, [
            ['kind' => UserEntitlementGrant::ATOM_LESSON, 'id' => $lessons[0]->id],
            ['kind' => UserEntitlementGrant::ATOM_LESSON, 'id' => $lessons[1]->id],
        ]);
        $subjectAccess = app(AccessResolver::class)->check($user, AccessResolver::ENTITY_SUBJECT, $subject->id);

        $this->assertSame(AccessResolver::ACCESS_FULL, $boxAccess['access']);
        $this->assertSame(AccessResolver::ACCESS_PARTIAL, $subjectAccess['access']);
        $this->assertSame(3, $subjectAccess['required_atoms_count']);
        $this->assertSame(2, $subjectAccess['owned_atoms_count']);
    }

    /**
     * @return array{0: Subject, 1: Course, 2: array<int, Lesson>}
     */
    private function createCourseWithLessons(int $lessonCount): array
    {
        $subject = Subject::query()->create([
            'slug' => 'subject-'.Str::uuid(),
            'title' => 'Анатомия',
            'sort_order' => 1,
            'is_active' => true,
        ]);
        $course = Course::query()->create([
            'subject_id' => $subject->id,
            'slug' => 'course-'.Str::uuid(),
            'title' => 'Остеология',
            'short_title' => 'Остеология',
            'price_cents' => 150000,
            'sort_order' => 1,
            'is_active' => true,
        ]);
        $lessons = [];
        for ($i = 1; $i <= $lessonCount; $i++) {
            $lessons[] = Lesson::query()->create([
                'course_id' => $course->id,
                'slug' => 'lesson-'.$i.'-'.Str::uuid(),
                'title' => 'Тема '.$i,
                'sort_order' => $i,
                'is_active' => true,
            ]);
        }

        return [$subject, $course, $lessons];
    }

    private function createPendingCourseOrder(User $user, Course $course): Order
    {
        $order = $this->createPendingOrder($user, amount: 150000, course: $course);

        app(OrderItemSnapshotService::class)->createCourseItem($order, $course);

        return $order->fresh();
    }

    private function createPendingOrder(User $user, int $amount, ?Course $course = null): Order
    {
        return Order::query()->create([
            'user_id' => $user->id,
            'course_id' => $course?->id,
            'payment_status' => 'pending_payment',
            'access_status' => 'not_granted',
            'currency' => 'RUB',
            'amount_total' => $amount,
            'amount_discount' => 0,
            'amount_final' => $amount,
            'amount_paid' => 0,
            'amount_refunded' => 0,
        ]);
    }

    /**
     * @param  array<int, array{kind:string,id:int}>  $atoms
     */
    private function createSnapshotItem(
        Order $order,
        string $kind,
        int $entityId,
        string $slug,
        string $title,
        array $atoms
    ): OrderItem {
        $attributes = [
            'order_id' => $order->id,
            'sellable_kind' => $kind,
            'sellable_id' => $entityId,
            'sellable_sku' => "{$kind}:{$slug}",
            'purchased_entity_type' => $kind,
            'purchased_entity_id' => $entityId,
            'purchased_entity_slug' => $slug,
            'display_title' => $title,
            'quantity' => 1,
            'currency' => 'RUB',
            'unit_amount' => 100000,
            'subtotal_amount' => 100000,
            'discount_amount' => 0,
            'final_amount' => 100000,
            'catalog_snapshot' => ['version' => 1, 'kind' => $kind, 'id' => $entityId],
            'price_snapshot' => ['version' => 1, 'currency' => 'RUB', 'final_amount' => 100000],
            'benefit_snapshot' => ['version' => 1, 'rules' => []],
            'grant_snapshot' => ['version' => 1, 'atoms' => $atoms],
            'access_status' => OrderItem::ACCESS_NOT_GRANTED,
        ];
        $attributes['snapshot_hash'] = OrderItemSnapshotService::snapshotHash($attributes);

        return OrderItem::query()->create($attributes);
    }
}
