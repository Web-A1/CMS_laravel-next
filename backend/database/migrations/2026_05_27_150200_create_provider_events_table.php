<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_events', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 32);
            $table->string('provider_event_id', 128);
            $table->string('event_type', 64)->nullable();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('attempt_id')->nullable()->constrained('payment_attempts')->nullOnDelete();
            $table->boolean('signature_valid')->default(false);
            $table->boolean('processed')->default(false);
            $table->timestamp('processed_at')->nullable();
            $table->json('payload_raw')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_event_id', 'event_type', 'order_id', 'attempt_id'], 'provider_events_idempotency_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_events');
    }
};
