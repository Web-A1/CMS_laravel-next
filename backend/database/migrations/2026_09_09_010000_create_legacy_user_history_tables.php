<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legacy_user_import_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('source_database', 64);
            $table->string('snapshot_id', 96);
            $table->unsignedInteger('mapping_version');
            $table->char('manifest_sha256', 64);
            $table->char('approved_report_sha256', 64);
            $table->string('target_environment', 32);
            $table->string('target_database', 128);
            $table->string('status', 32);
            $table->jsonb('checkpoints');
            $table->jsonb('report')->nullable();
            $table->timestampTz('started_at');
            $table->timestampTz('finished_at')->nullable();
            $table->index(['snapshot_id', 'mapping_version']);
        });

        Schema::create('legacy_user_profiles', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('source_member_id')->unique();
            $table->foreignId('user_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->string('display_name');
            $table->string('source_email')->index();
            $table->text('source_phone')->nullable();
            $table->unsignedBigInteger('source_manager_id')->nullable();
            $table->string('mapping_status', 32);
            $table->timestampTz('source_created_at')->nullable();
            $table->jsonb('payload');
            $table->jsonb('managed_values');
            $table->jsonb('issues');
            $this->sourceRevision($table);
            $table->index(['mapping_status', 'id']);
        });

        Schema::create('legacy_user_orders', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('source_order_id')->unique();
            $this->owner($table);
            $table->smallInteger('source_status');
            $table->bigInteger('amount_minor');
            $table->decimal('amount_decimal', 18, 2);
            $table->char('currency', 3);
            $table->string('currency_basis', 96);
            $table->timestampTz('recorded_at')->nullable();
            $table->jsonb('payload');
            $table->jsonb('issues');
            $this->sourceRevision($table);
            $table->index(['legacy_user_profile_id', 'recorded_at', 'id'], 'legacy_user_orders_profile_time');
            $table->index(['source_status', 'id']);
        });

        Schema::create('legacy_user_order_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('source_item_id')->unique();
            $table->unsignedBigInteger('source_order_id');
            $table->foreignId('legacy_user_order_id')->nullable()->constrained()->restrictOnDelete();
            $this->owner($table);
            $table->unsignedBigInteger('source_product_id')->nullable();
            $table->text('title');
            $table->unsignedInteger('quantity');
            $table->bigInteger('unit_amount_minor');
            $table->bigInteger('coupon_unit_amount_minor')->nullable();
            $table->bigInteger('cost_minor');
            $table->jsonb('payload');
            $table->jsonb('issues');
            $this->sourceRevision($table);
            $table->index(['legacy_user_order_id', 'id'], 'legacy_user_items_order_id');
            $table->index(['legacy_user_profile_id', 'id'], 'legacy_user_items_profile_id');
        });

        Schema::create('legacy_user_accesses', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('source_access_id')->unique();
            $this->owner($table);
            $table->unsignedBigInteger('source_page_id')->nullable();
            $table->unsignedBigInteger('source_subject_id')->nullable();
            // A historical candidate never grants current access and may cease to exist in the catalog.
            $table->unsignedBigInteger('candidate_lesson_id')->nullable();
            $table->string('mapping_status', 40);
            $table->timestampTz('recorded_at')->nullable();
            $table->jsonb('payload');
            $table->jsonb('issues');
            $this->sourceRevision($table);
            $table->index(['legacy_user_profile_id', 'recorded_at', 'id'], 'legacy_user_accesses_profile_time');
            $table->index(['mapping_status', 'id']);
        });

        Schema::create('legacy_user_records', function (Blueprint $table): void {
            $table->id();
            $table->string('source_table', 64);
            $table->unsignedBigInteger('source_id');
            $this->owner($table);
            $table->unsignedBigInteger('source_order_id')->nullable();
            $table->unsignedBigInteger('source_actor_id')->nullable();
            $table->foreignId('legacy_user_order_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('category', 32);
            $table->timestampTz('recorded_at')->nullable();
            $table->jsonb('payload');
            $table->jsonb('issues');
            $this->sourceRevision($table);
            $table->unique(['source_table', 'source_id']);
            $table->index(['legacy_user_profile_id', 'category', 'recorded_at', 'id'], 'legacy_user_records_profile_category_time');
            $table->index(['legacy_user_order_id', 'id'], 'legacy_user_records_order_id');
            $table->index(['ownership_status', 'category', 'id'], 'legacy_user_records_unresolved');
        });

        Schema::create('legacy_user_record_versions', function (Blueprint $table): void {
            $table->id();
            $table->string('record_type', 32);
            $table->unsignedBigInteger('record_id');
            $table->char('source_fingerprint', 64);
            $table->uuid('replaced_by_run_id');
            $table->foreign('replaced_by_run_id')->references('id')->on('legacy_user_import_runs')->restrictOnDelete();
            $table->jsonb('previous_data');
            $table->timestampTz('created_at');
            $table->index(['record_type', 'record_id', 'id'], 'legacy_user_versions_record');
        });
    }

    private function owner(Blueprint $table): void
    {
        $table->foreignId('legacy_user_profile_id')->nullable()->constrained()->restrictOnDelete();
        $table->unsignedBigInteger('source_member_id')->nullable();
        $table->string('ownership_status', 32);
    }

    private function sourceRevision(Blueprint $table): void
    {
        $table->char('source_fingerprint', 64);
        $table->uuid('first_import_run_id');
        $table->uuid('last_import_run_id');
        $table->foreign('first_import_run_id')->references('id')->on('legacy_user_import_runs')->restrictOnDelete();
        $table->foreign('last_import_run_id')->references('id')->on('legacy_user_import_runs')->restrictOnDelete();
        $table->timestampsTz();
    }

    public function down(): void
    {
        Schema::dropIfExists('legacy_user_record_versions');
        Schema::dropIfExists('legacy_user_records');
        Schema::dropIfExists('legacy_user_accesses');
        Schema::dropIfExists('legacy_user_order_items');
        Schema::dropIfExists('legacy_user_orders');
        Schema::dropIfExists('legacy_user_profiles');
        Schema::dropIfExists('legacy_user_import_runs');
    }
};
