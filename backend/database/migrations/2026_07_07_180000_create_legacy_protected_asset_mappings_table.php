<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legacy_protected_asset_mappings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('course_topic_learning_content_id');
            $table->unsignedBigInteger('course_topic_id');
            $table->string('source_table', 128)->nullable();
            $table->string('source_id', 128)->nullable();
            $table->string('source_scope_type', 32)->default('id_only');
            $table->string('source_scope_id', 128)->default('id_only');
            $table->string('asset_kind', 32);
            $table->unsignedBigInteger('legacy_asset_id');
            $table->unsignedInteger('reference_count')->default(1);
            $table->unsignedInteger('first_block_index')->default(0);
            $table->string('readiness_status', 32)->default('needs_mapping');
            $table->string('validation_status', 32)->default('pending');
            $table->string('internal_asset_type', 64)->nullable();
            $table->unsignedBigInteger('internal_asset_id')->nullable();
            $table->string('manual_review_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(
                ['course_topic_learning_content_id', 'asset_kind', 'legacy_asset_id'],
                'legacy_protected_asset_mappings_unique'
            );
            $table->index(['asset_kind', 'readiness_status'], 'legacy_protected_asset_mappings_kind_status_index');
            $table->index(['course_topic_id', 'asset_kind'], 'legacy_protected_asset_mappings_topic_kind_index');
            $table->index(['source_table', 'source_id'], 'legacy_protected_asset_mappings_source_index');
            $table->index(['source_scope_type', 'source_scope_id'], 'legacy_protected_asset_mappings_scope_index');
            $table->index(['internal_asset_type', 'internal_asset_id'], 'legacy_protected_asset_mappings_internal_index');

            $table
                ->foreign('course_topic_learning_content_id', 'legacy_protected_asset_mappings_content_fk')
                ->references('id')
                ->on('course_topic_learning_contents')
                ->cascadeOnDelete();
            $table
                ->foreign('course_topic_id', 'legacy_protected_asset_mappings_topic_fk')
                ->references('id')
                ->on('course_topics')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legacy_protected_asset_mappings');
    }
};
