<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legacy_route_mappings', function (Blueprint $table): void {
            $table->id();
            $table->string('legacy_path', 512)->unique();
            $table->string('target_path', 512)->nullable();
            $table->string('source_surface', 32);
            $table->string('route_kind', 32);
            $table->string('action', 32);
            $table->string('parity_status', 32)->default('pending');
            $table->boolean('requires_auth')->default(false);
            $table->string('manual_review_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['source_surface', 'parity_status']);
            $table->index(['route_kind', 'action']);
        });

        Schema::create('legacy_import_mappings', function (Blueprint $table): void {
            $table->id();
            $table->string('batch_key', 96);
            $table->string('source_table', 128);
            $table->string('source_id', 128);
            $table->string('target_domain', 64);
            $table->string('target_type', 64)->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->string('mapping_status', 32)->default('pending');
            $table->string('validation_status', 32)->default('pending');
            $table->string('idempotency_key', 160)->unique();
            $table->char('source_fingerprint_hash', 64);
            $table->string('manual_review_reason')->nullable();
            $table->json('errors')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['source_table', 'source_id', 'target_domain'], 'legacy_import_mappings_source_unique');
            $table->index(['batch_key', 'mapping_status']);
            $table->index(['target_domain', 'validation_status']);
            $table->index(['target_type', 'target_id']);
        });

        Schema::create('sellable_entities', function (Blueprint $table): void {
            $table->id();
            $table->string('sellable_kind', 32);
            $table->string('entity_type', 32);
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->string('sku', 128)->unique();
            $table->string('title');
            $table->string('currency', 3)->default('RUB');
            $table->unsignedBigInteger('price_cents')->default(0);
            $table->unsignedInteger('price_version')->default(1);
            $table->string('status', 32)->default('draft');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['sellable_kind', 'status']);
            $table->index(['entity_type', 'entity_id']);
        });

        Schema::create('legacy_product_mappings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('legacy_product_id');
            $table->unsignedBigInteger('legacy_buyaction_id')->default(0);
            $table->foreignId('sellable_entity_id')->constrained()->cascadeOnDelete();
            $table->string('mapping_status', 32)->default('mapped');
            $table->char('legacy_payload_hash', 64)->nullable();
            $table->unsignedInteger('grant_snapshot_version')->default(1);
            $table->string('manual_review_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(
                ['legacy_product_id', 'legacy_buyaction_id'],
                'legacy_product_mappings_product_buyaction_unique'
            );
            $table->index(['mapping_status', 'grant_snapshot_version'], 'legacy_product_mappings_status_version_index');
        });

        Schema::create('sellable_grant_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sellable_entity_id')->constrained()->cascadeOnDelete();
            $table->string('atom_kind', 16);
            $table->unsignedBigInteger('atom_id');
            $table->unsignedInteger('grant_snapshot_version')->default(1);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(
                ['sellable_entity_id', 'atom_kind', 'atom_id', 'grant_snapshot_version'],
                'sellable_grant_rules_unique'
            );
            $table->index(['atom_kind', 'atom_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sellable_grant_rules');
        Schema::dropIfExists('legacy_product_mappings');
        Schema::dropIfExists('sellable_entities');
        Schema::dropIfExists('legacy_import_mappings');
        Schema::dropIfExists('legacy_route_mappings');
    }
};
