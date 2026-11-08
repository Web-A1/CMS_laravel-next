<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('side_effect_executions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('outbox_event_id')->constrained('outbox_events')->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('attempt_id')->nullable()->constrained('payment_attempts')->nullOnDelete();
            $table->foreignId('refund_id')->nullable()->constrained('refunds')->nullOnDelete();
            $table->string('handler', 64);
            $table->string('dedup_key', 191)->unique();
            $table->string('status', 32)->default('completed');
            $table->json('meta')->nullable();
            $table->timestamp('processed_at')->useCurrent();
            $table->timestamps();

            $table->index(['handler', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('side_effect_executions');
    }
};
