<?php

namespace Tests\Feature;

use App\Jobs\ProcessOutboxEventJob;
use App\Models\Order;
use App\Models\OutboxEvent;
use App\Models\SideEffectExecution;
use App\Services\Payments\PaymentOutboxService;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\TestCase;

class PaymentOutboxRecoveryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // These scenarios need real commits, without a wrapping test transaction.
        $this->assertSame('testing', app()->environment());
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]));
        $this->freezeTime();
    }

    public function test_committed_event_recovers_after_initial_enqueue_failure_and_repeated_worker_is_safe(): void
    {
        $order = $this->order();
        Bus::shouldReceive('dispatch')->once()->andThrow(new RuntimeException('synthetic enqueue failure'));

        try {
            DB::transaction(fn () => app(PaymentOutboxService::class)->publishTransition(
                $order, null, null, 'pending_payment', 'paid',
            ));
            $this->fail('Dispatch must fail after the transaction commits.');
        } catch (RuntimeException $exception) {
            $this->assertSame('synthetic enqueue failure', $exception->getMessage());
        }

        $event = OutboxEvent::query()->firstOrFail();
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame('pending', $event->status);
        $this->assertDatabaseHas('orders', ['id' => $order->id]);
        Bus::fake();
        $this->travel(301)->seconds();
        $this->artisan('payments:outbox:retry', ['--event-id' => $event->id])
            ->expectsOutputToContain('Dispatched 1 retry jobs')
            ->assertSuccessful();
        Bus::assertDispatchedTimes(ProcessOutboxEventJob::class, 1);

        (new ProcessOutboxEventJob($event->id))->handle();
        $executions = SideEffectExecution::query()->orderBy('id')->get()->toArray();
        $this->assertCount(4, $executions);
        (new ProcessOutboxEventJob($event->id))->handle();

        $this->assertSame('pending', $event->fresh()->status);
        $this->assertSame($executions, SideEffectExecution::query()->orderBy('id')->get()->toArray());
    }

    public function test_retry_enqueue_failure_keeps_committed_claim_recoverable_after_the_age_guard(): void
    {
        $event = $this->event(['status' => 'failed', 'retry_count' => 2]);
        Bus::shouldReceive('dispatch')->once()->andReturnUsing(function (): never {
            $this->assertSame(0, DB::transactionLevel());
            throw new RuntimeException('synthetic recovery enqueue failure');
        });

        try {
            Artisan::call('payments:outbox:retry', ['--event-id' => $event->id]);
            $this->fail('Recovery dispatch must report the queue failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('synthetic recovery enqueue failure', $exception->getMessage());
        }

        $this->assertSame('pending', $event->fresh()->status);
        $this->assertSame(2, (int) $event->fresh()->retry_count);
        Bus::fake();
        $this->artisan('payments:outbox:retry', ['--event-id' => $event->id])->assertSuccessful();
        Bus::assertNothingDispatched();

        $this->travel(301)->seconds();
        $this->artisan('payments:outbox:retry', ['--event-id' => $event->id])->assertSuccessful();
        Bus::assertDispatchedTimes(ProcessOutboxEventJob::class, 1);
    }

    public function test_duplicate_transition_events_do_not_duplicate_business_side_effects(): void
    {
        $order = $this->order();
        $first = $this->event(['order_id' => $order->id]);
        $second = $this->event(['order_id' => $order->id]);

        (new ProcessOutboxEventJob($first->id))->handle();
        (new ProcessOutboxEventJob($second->id))->handle();

        $this->assertDatabaseCount('side_effect_executions', 4);
        $this->assertSame(4, SideEffectExecution::query()->distinct()->count('dedup_key'));
        $this->assertSame('pending', $first->fresh()->status);
        $this->assertSame('pending', $second->fresh()->status);
    }

    public function test_worker_failure_rolls_back_partial_effects_and_can_be_retried(): void
    {
        $event = $this->event();
        $created = 0;
        SideEffectExecution::creating(function () use (&$created): void {
            if (++$created === 2) {
                throw new RuntimeException('synthetic second effect failure');
            }
        });

        try {
            (new ProcessOutboxEventJob($event->id))->handle();
            $this->fail('The second marker must fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('synthetic second effect failure', $exception->getMessage());
        }

        $this->assertDatabaseCount('side_effect_executions', 0);
        $this->assertSame('failed', $event->fresh()->status);
        $this->assertSame(1, (int) $event->fresh()->retry_count);

        (new ProcessOutboxEventJob($event->id))->handle();

        $this->assertDatabaseCount('side_effect_executions', 4);
        $this->assertSame('pending', $event->fresh()->status);
    }

    public function test_failed_worker_cannot_downgrade_an_event_completed_after_its_rollback(): void
    {
        $event = $this->event();
        $failedOnce = false;
        $replayed = false;
        SideEffectExecution::creating(function () use (&$failedOnce): void {
            if (! $failedOnce) {
                $failedOnce = true;
                throw new RuntimeException('synthetic first worker failure');
            }
        });
        Event::listen(TransactionRolledBack::class, function (TransactionRolledBack $transaction) use ($event, &$replayed): void {
            if (! $replayed && $transaction->connection->transactionLevel() === 0) {
                $replayed = true;
                // Model the second worker finishing after the first releases its lock.
                (new ProcessOutboxEventJob($event->id))->handle();
                // Independently completed delivery, not synthetic materialization.
                OutboxEvent::query()->whereKey($event->id)->update(['status' => 'processed', 'processed_at' => now()]);
            }
        });

        try {
            (new ProcessOutboxEventJob($event->id))->handle();
            $this->fail('The first worker must still report its failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('synthetic first worker failure', $exception->getMessage());
        }

        $this->assertTrue($replayed);
        $this->assertSame('processed', $event->fresh()->status);
        $this->assertSame(0, (int) $event->fresh()->retry_count);
        $this->assertDatabaseCount('side_effect_executions', 4);
    }

    private function order(): Order
    {
        return Order::query()->create([
            'amount_total' => 1000,
            'amount_final' => 1000,
            'payment_status' => 'paid',
        ]);
    }

    private function event(array $attributes = []): OutboxEvent
    {
        return OutboxEvent::query()->create(array_merge([
            'event_type' => 'payment.transition.applied',
            'order_id' => $this->order()->id,
            'status' => 'pending',
            'payload' => ['from_status' => 'pending_payment', 'to_status' => 'paid'],
            'available_at' => now(),
        ], $attributes));
    }
}
