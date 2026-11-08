<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coin_reservations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->string('idempotency_key', 128)->unique();
            $table->string('intent_hash', 64);
            $table->unsignedBigInteger('units');
            $table->string('status', 24);
            $table->timestampTz('expires_at');
            $table->timestampTz('committed_at')->nullable();
            $table->timestampTz('released_at')->nullable();
            $table->string('release_reason', 64)->nullable();
            $table->json('proof');
            $table->timestampsTz();
            $table->index(['order_id', 'status']);
            $table->index(['user_id', 'status', 'expires_at']);
        });
        Schema::create('coin_ledger_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('reservation_id')->nullable()->constrained('coin_reservations')->restrictOnDelete();
            $table->foreignId('lot_id')->nullable()->constrained('coin_ledger_entries')->restrictOnDelete();
            $table->string('event_type', 24);
            $table->unsignedBigInteger('units');
            $table->string('idempotency_key', 160)->unique();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampsTz();
            $table->index(['user_id', 'event_type', 'created_at']);
            $table->unique(['reservation_id', 'lot_id', 'event_type'], 'coin_ledger_reservation_lot_event_unique');
        });
        Schema::create('coin_reservation_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('reservation_id')->constrained('coin_reservations')->restrictOnDelete();
            $table->foreignId('lot_id')->constrained('coin_ledger_entries')->restrictOnDelete();
            $table->unsignedBigInteger('units');
            $table->timestampsTz();
            $table->unique(['reservation_id', 'lot_id']);
        });
        Schema::create('promo_codes', function (Blueprint $table): void {
            $table->id();
            $table->string('code_hash', 64)->unique();
            $table->string('status', 24);
            $table->string('discount_kind', 24);
            $table->unsignedBigInteger('discount_minor')->nullable();
            $table->unsignedInteger('discount_bps')->nullable();
            $table->string('currency', 3);
            $table->timestampTz('starts_at')->nullable();
            $table->timestampTz('expires_at');
            $table->unsignedInteger('activation_limit');
            $table->unsignedInteger('per_user_limit');
            $table->string('bound_email_hash', 64)->nullable();
            $table->string('rules_version', 64);
            $table->timestampsTz();
        });
        Schema::create('promo_reservations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('promo_code_id')->constrained()->restrictOnDelete();
            $table->string('idempotency_key', 128)->unique();
            $table->string('intent_hash', 64);
            $table->unsignedBigInteger('discount_minor');
            $table->string('status', 24);
            $table->timestampTz('expires_at');
            $table->timestampTz('committed_at')->nullable();
            $table->timestampTz('released_at')->nullable();
            $table->string('release_reason', 64)->nullable();
            $table->json('proof');
            $table->timestampsTz();
            $table->index(['order_id', 'status']);
            $table->index(['promo_code_id', 'status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promo_reservations');
        Schema::dropIfExists('promo_codes');
        Schema::dropIfExists('coin_reservation_allocations');
        Schema::dropIfExists('coin_ledger_entries');
        Schema::dropIfExists('coin_reservations');
    }
};
