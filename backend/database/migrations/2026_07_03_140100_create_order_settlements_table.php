<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_settlements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_attempt_id')->nullable()->constrained('payment_attempts')->nullOnDelete();
            $table->foreignId('provider_event_id')->nullable()->constrained('provider_events')->nullOnDelete();
            $table->string('settlement_type', 32);
            $table->string('idempotency_key');
            $table->unsignedBigInteger('amount');
            $table->string('currency', 3);
            $table->string('status', 32)->default('confirmed');
            $table->char('fingerprint_hash', 64);
            $table->timestamp('confirmed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique('idempotency_key');
            $table->index(['order_id', 'status']);
            $table->index(['settlement_type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_settlements');
    }
};
