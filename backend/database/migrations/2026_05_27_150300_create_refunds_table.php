<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refunds', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('attempt_id')->nullable()->constrained('payment_attempts')->nullOnDelete();
            $table->string('refund_type', 32)->default('partial');
            $table->unsignedBigInteger('amount');
            $table->string('currency', 3)->default('RUB');
            $table->string('reason')->nullable();
            $table->string('policy_access_action', 16)->default('keep');
            $table->string('provider_refund_id', 128)->nullable();
            $table->string('status', 32)->default('pending');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
    }
};
