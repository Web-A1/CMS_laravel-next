<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('course_id')->nullable();
            $table->string('currency', 3)->default('RUB');
            $table->unsignedBigInteger('amount_total');
            $table->unsignedBigInteger('amount_discount')->default(0);
            $table->unsignedBigInteger('amount_final')->default(0);
            $table->unsignedBigInteger('amount_paid')->default(0);
            $table->unsignedBigInteger('amount_refunded')->default(0);
            $table->string('payment_status', 32)->default('draft')->index();
            $table->string('access_status', 32)->default('not_granted')->index();
            $table->unsignedBigInteger('active_attempt_id')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['payment_status', 'access_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
