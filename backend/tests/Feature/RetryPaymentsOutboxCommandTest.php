<?php

namespace Tests\Feature;

use App\Jobs\ProcessOutboxEventJob;
use App\Models\OutboxEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RetryPaymentsOutboxCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_supports_dry_run_without_dispatch(): void
    {
        Queue::fake();

        OutboxEvent::query()->create([
            'event_type' => 'payment.transition.applied',
            'status' => 'failed',
            'retry_count' => 1,
            'payload' => ['from_status' => 'pending_payment', 'to_status' => 'paid'],
            'available_at' => now(),
        ]);

        $this->artisan('payments:outbox:retry', ['--dry-run' => true])
            ->expectsOutputToContain('Found 1 failed events.')
            ->expectsOutputToContain('Dry-run mode: no jobs dispatched.')
            ->assertSuccessful();

        Queue::assertNothingPushed();
        $this->assertSame('failed', OutboxEvent::query()->firstOrFail()->status);
    }

    public function test_command_marks_failed_event_pending_and_dispatches_job(): void
    {
        Queue::fake();

        $event = OutboxEvent::query()->create([
            'event_type' => 'payment.transition.applied',
            'status' => 'failed',
            'retry_count' => 2,
            'payload' => ['from_status' => 'pending_payment', 'to_status' => 'cancelled'],
            'available_at' => now(),
        ]);

        $this->artisan('payments:outbox:retry', ['--event-id' => $event->id])
            ->expectsOutputToContain('Dispatched 1 retry jobs')
            ->assertSuccessful();

        Queue::assertPushed(ProcessOutboxEventJob::class, 1);
        $this->assertSame('pending', OutboxEvent::query()->findOrFail($event->id)->status);
    }

    public function test_command_recovers_stale_pending_once_and_preserves_queue_and_limit(): void
    {
        Queue::fake();
        $this->freezeTime();
        $first = $this->pendingEvent();
        $second = $this->pendingEvent();
        $this->travel(301)->seconds();

        $this->artisan('payments:outbox:retry', ['--limit' => 1, '--queue' => 'recovery'])
            ->expectsOutputToContain('Dispatched 1 retry jobs to queue "recovery".')
            ->assertSuccessful();
        Queue::assertPushedOn('recovery', ProcessOutboxEventJob::class);
        Queue::assertPushed(ProcessOutboxEventJob::class, 1);
        $this->assertSame(now()->toDateTimeString(), $first->fresh()->updated_at->toDateTimeString());
        $this->assertTrue($second->fresh()->updated_at->lessThan(now()));

        $this->artisan('payments:outbox:retry', ['--event-id' => $first->id])
            ->assertSuccessful();
        Queue::assertPushed(ProcessOutboxEventJob::class, 1);
    }

    public function test_explicit_event_id_does_not_bypass_pending_age_due_time_or_processed_status(): void
    {
        Queue::fake();
        $this->freezeTime();
        $deferred = $this->pendingEvent(['available_at' => now()->addHour()]);
        $processed = $this->pendingEvent(['status' => 'processed', 'processed_at' => now()]);
        $this->travel(301)->seconds();
        $fresh = $this->pendingEvent();

        foreach ([$deferred, $processed, $fresh] as $event) {
            $before = $event->fresh()->getAttributes();
            $this->artisan('payments:outbox:retry', ['--event-id' => $event->id])
                ->assertSuccessful();
            $this->assertSame($before, $event->fresh()->getAttributes());
        }

        Queue::assertNothingPushed();
    }

    public function test_stale_pending_without_available_at_is_recoverable(): void
    {
        Queue::fake();
        $this->freezeTime();
        $event = $this->pendingEvent(['available_at' => null]);
        $this->travel(301)->seconds();

        $this->artisan('payments:outbox:retry', ['--event-id' => $event->id])
            ->expectsOutputToContain('Dispatched 1 retry jobs')
            ->assertSuccessful();

        Queue::assertPushed(ProcessOutboxEventJob::class, 1);
    }

    public function test_failed_event_keeps_immediate_retry_behavior_even_with_future_available_at(): void
    {
        Queue::fake();
        $event = $this->pendingEvent(['status' => 'failed', 'available_at' => now()->addHour()]);

        $this->artisan('payments:outbox:retry', ['--event-id' => $event->id])
            ->expectsOutputToContain('Found 1 failed events.')
            ->expectsOutputToContain('Dispatched 1 retry jobs')
            ->assertSuccessful();

        Queue::assertPushed(ProcessOutboxEventJob::class, 1);
        $this->assertSame('pending', $event->fresh()->status);
    }

    public function test_dry_run_includes_stale_pending_without_refreshing_its_recovery_age(): void
    {
        Queue::fake();
        $this->freezeTime();
        $event = $this->pendingEvent();
        $before = $event->fresh()->getAttributes();
        $this->travel(301)->seconds();

        $this->artisan('payments:outbox:retry', ['--dry-run' => true])
            ->expectsOutputToContain('Found 1 stale pending events.')
            ->expectsOutputToContain('Dry-run mode: no jobs dispatched.')
            ->assertSuccessful();

        Queue::assertNothingPushed();
        $this->assertSame($before, $event->fresh()->getAttributes());
    }

    public function test_command_rechecks_an_event_processed_after_candidate_selection(): void
    {
        Queue::fake();
        $event = $this->pendingEvent(['status' => 'failed']);
        $processed = false;
        OutboxEvent::retrieved(function (OutboxEvent $candidate) use ($event, &$processed): void {
            if (! $processed && $candidate->id === $event->id) {
                $processed = true;
                DB::table('outbox_events')->where('id', $event->id)->update([
                    'status' => 'processed',
                    'processed_at' => now(),
                ]);
            }
        });

        $this->artisan('payments:outbox:retry', ['--event-id' => $event->id])
            ->expectsOutputToContain('Dispatched 0 retry jobs')
            ->assertSuccessful();

        Queue::assertNothingPushed();
        $this->assertSame('processed', $event->fresh()->status);
    }

    private function pendingEvent(array $attributes = []): OutboxEvent
    {
        return OutboxEvent::query()->create(array_merge([
            'event_type' => 'payment.transition.applied',
            'status' => 'pending',
            'payload' => ['from_status' => 'pending_payment', 'to_status' => 'cancelled'],
            'available_at' => now(),
        ], $attributes));
    }
}
