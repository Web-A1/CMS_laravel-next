<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbox_events', function (Blueprint $table): void {
            $table->id();
            $table->string('event_type', 64);
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('attempt_id')->nullable()->constrained('payment_attempts')->nullOnDelete();
            $table->foreignId('refund_id')->nullable()->constrained('refunds')->nullOnDelete();
            $table->string('status', 32)->default('pending')->index();
            $table->unsignedSmallInteger('retry_count')->default(0);
            $table->timestamp('available_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->json('payload');
            $table->timestamps();

            $table->index(['event_type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_events');
    }
};
