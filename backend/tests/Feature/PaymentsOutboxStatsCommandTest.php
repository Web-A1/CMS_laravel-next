<?php

namespace Tests\Feature;

use App\Models\OutboxEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentsOutboxStatsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_stats_command_outputs_aggregates_for_window(): void
    {
        OutboxEvent::query()->create([
            'event_type' => 'payment.transition.applied',
            'status' => 'processed',
            'retry_count' => 0,
            'payload' => ['to_status' => 'paid'],
            'available_at' => now(),
            'processed_at' => now(),
        ]);

        OutboxEvent::query()->create([
            'event_type' => 'payment.transition.applied',
            'status' => 'failed',
            'retry_count' => 2,
            'payload' => ['to_status' => 'cancelled'],
            'available_at' => now(),
        ]);

        $this->artisan('payments:outbox:stats', ['--hours' => 24])
            ->expectsOutputToContain('Outbox stats for last 24 hours')
            ->expectsOutputToContain('Total: 2')
            ->expectsOutputToContain('Retried (>0): 1')
            ->assertSuccessful();
    }

    public function test_stats_command_supports_event_type_filter(): void
    {
        OutboxEvent::query()->create([
            'event_type' => 'payment.transition.applied',
            'status' => 'processed',
            'retry_count' => 1,
            'payload' => ['to_status' => 'paid'],
            'available_at' => now(),
        ]);

        OutboxEvent::query()->create([
            'event_type' => 'other.event',
            'status' => 'processed',
            'retry_count' => 0,
            'payload' => [],
            'available_at' => now(),
        ]);

        $this->artisan('payments:outbox:stats', [
            '--hours' => 24,
            '--event-type' => 'payment.transition.applied',
        ])
            ->expectsOutputToContain('Event type filter: payment.transition.applied')
            ->expectsOutputToContain('Total: 1')
            ->assertSuccessful();
    }
}
