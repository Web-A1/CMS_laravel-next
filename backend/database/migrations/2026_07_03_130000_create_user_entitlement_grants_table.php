<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_entitlement_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('atom_kind', 16);
            $table->unsignedBigInteger('atom_id');
            $table->string('status', 16)->default('active');
            $table->string('source_type', 64);
            $table->string('source_id')->nullable();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('order_item_id')->nullable();
            $table->string('idempotency_key');
            $table->timestamp('granted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('revoked_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(
                ['user_id', 'atom_kind', 'atom_id', 'source_type', 'idempotency_key'],
                'user_entitlement_grants_idempotency_unique'
            );
            $table->index(['user_id', 'atom_kind', 'atom_id', 'status'], 'user_entitlement_grants_lookup_index');
            $table->index(['source_type', 'source_id'], 'user_entitlement_grants_source_index');
            $table->index('order_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_entitlement_grants');
    }
};
