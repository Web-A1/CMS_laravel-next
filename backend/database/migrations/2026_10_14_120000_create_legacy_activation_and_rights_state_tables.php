<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legacy_user_activations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('activation_id')->unique();
            $table->foreignId('legacy_user_profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('source_email');
            $table->char('source_fingerprint', 64);
            $table->char('capability_hash', 64);
            $table->timestampTz('expires_at');
            $table->string('email_code_hash')->nullable();
            $table->unsignedSmallInteger('attempts_left');
            $table->timestampTz('email_code_expires_at');
            $table->timestampTz('email_confirmed_at')->nullable();
            $table->string('password_hash')->nullable();
            $table->uuid('current_phone_attempt')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();
            $table->index(['legacy_user_profile_id', 'id'], 'legacy_activation_profile_sequence');
        });

        Schema::create('legacy_activation_phone_attempts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('verification_id')->unique();
            $table->foreignId('activation_id')->constrained('legacy_user_activations')->restrictOnDelete();
            $table->string('phone', 30);
            $table->string('user_phone_at_start', 30);
            $table->string('status', 24);
            $table->string('provider_uuid', 120)->nullable();
            $table->string('call_number', 30)->nullable();
            $table->string('provider_event_id', 120)->nullable();
            $table->timestampTz('expires_at');
            $table->timestampTz('confirmed_at')->nullable();
            $table->timestampTz('consumed_at')->nullable();
            $table->timestampsTz();
            $table->index('provider_uuid');
        });

        Schema::create('legacy_user_rights_authorities', function (Blueprint $table): void {
            $table->string('source_database', 64)->primary();
            $table->unsignedBigInteger('generation')->default(0);
            $table->char('manifest_sha256', 64)->nullable();
            $table->timestampsTz();
        });

        Schema::create('legacy_user_rights_generations', function (Blueprint $table): void {
            $table->id();
            $table->string('source_database', 64);
            $table->unsignedBigInteger('generation');
            $table->unsignedBigInteger('predecessor_generation');
            $table->char('manifest_sha256', 64);
            $table->char('snapshot_sha256', 64);
            $table->timestampTz('created_at');
            $table->unique(['source_database', 'generation'], 'legacy_rights_generation_unique');
            $table->unique(['source_database', 'manifest_sha256'], 'legacy_rights_manifest_unique');
            $table->unique(['source_database', 'snapshot_sha256'], 'legacy_rights_snapshot_unique');
        });

        Schema::create('legacy_user_rights_states', function (Blueprint $table): void {
            $table->foreignId('legacy_user_profile_id')->primary()->constrained()->restrictOnDelete();
            $table->string('source_database', 64);
            $table->unsignedBigInteger('generation');
            $table->char('manifest_sha256', 64);
            $table->jsonb('outcome');
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legacy_user_rights_states');
        Schema::dropIfExists('legacy_user_rights_generations');
        Schema::dropIfExists('legacy_user_rights_authorities');
        Schema::dropIfExists('legacy_activation_phone_attempts');
        Schema::dropIfExists('legacy_user_activations');
    }
};
