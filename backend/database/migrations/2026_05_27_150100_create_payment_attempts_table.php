<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 32);
            $table->string('provider_payment_id', 128)->nullable();
            $table->string('provider_payment_url')->nullable();
            $table->string('status', 32)->default('created');
            $table->unsignedBigInteger('amount');
            $table->string('currency', 3)->default('RUB');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->json('provider_substatus')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'created_at']);
            $table->unique(['provider', 'provider_payment_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_attempts');
    }
};
