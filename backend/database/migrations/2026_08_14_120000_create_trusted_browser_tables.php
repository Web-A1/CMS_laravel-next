<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trusted_browser_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->char('cookie_key', 32)->unique();
            $table->timestamps();
        });

        Schema::create('trusted_browser_slots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained('trusted_browser_accounts')->cascadeOnDelete();
            $table->unsignedTinyInteger('slot_number');
            $table->enum('status', ['active', 'revoked_locked', 'risk_locked', 'released']);
            $table->unsignedInteger('current_generation')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();

            $table->unique(['account_id', 'slot_number']);
            $table->index(['account_id', 'status']);
        });

        Schema::create('trusted_browser_credentials', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('slot_id')->constrained('trusted_browser_slots')->cascadeOnDelete();
            $table->unsignedInteger('generation');
            $table->char('selector', 22)->unique();
            $table->char('secret_hash', 64);
            $table->timestamp('expires_at');
            $table->timestamp('last_refreshed_at');
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('invalidated_at')->nullable();
            $table->string('invalidation_reason', 64)->nullable();
            $table->string('browser_label', 80)->nullable();
            $table->string('os_label', 80)->nullable();
            $table->string('device_label', 80)->nullable();
            $table->char('user_agent_hash', 64)->nullable();
            $table->char('issued_ip_hash', 64)->nullable();
            $table->char('last_ip_hash', 64)->nullable();
            $table->timestamps();

            $table->unique(['slot_id', 'generation']);
            $table->index(['slot_id', 'expires_at']);
        });

        Schema::create('trusted_browser_challenges', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('account_id')->constrained('trusted_browser_accounts')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('purpose', ['enroll', 'revoke', 'support_release']);
            $table->foreignId('target_slot_id')->nullable()->constrained('trusted_browser_slots')->cascadeOnDelete();
            $table->foreignId('initiated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('phone_verification_code_id')
                ->nullable()
                ->unique()
                ->constrained('phone_verification_codes')
                ->nullOnDelete();
            $table->char('session_hash', 64);
            $table->char('email_hash', 64);
            $table->string('code_hash');
            $table->unsignedTinyInteger('attempts_left')->default(5);
            $table->unsignedTinyInteger('max_attempts')->default(5);
            $table->enum('status', ['pending', 'verified', 'failed', 'expired', 'completed'])->default('pending');
            $table->timestamp('expires_at');
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('used_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('phone_proof_bound_at')->nullable();
            $table->char('send_ip_hash', 64);
            $table->char('last_verify_ip_hash', 64)->nullable();
            $table->string('case_id', 120)->nullable();
            $table->text('reason')->nullable();
            $table->char('idempotency_key_hash', 64)->nullable();
            $table->json('metadata')->nullable();
            $table->json('result_payload')->nullable();
            $table->timestamps();

            $table->unique(
                ['initiated_by_user_id', 'idempotency_key_hash'],
                'trusted_browser_challenges_operator_idempotency_unique',
            );
            $table->index(['user_id', 'purpose', 'created_at']);
            $table->index(['session_hash', 'created_at']);
            $table->index(['send_ip_hash', 'created_at']);
        });

        Schema::create('trusted_browser_session_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained('trusted_browser_accounts')->cascadeOnDelete();
            $table->foreignId('slot_id')->constrained('trusted_browser_slots')->cascadeOnDelete();
            $table->foreignId('credential_id')->constrained('trusted_browser_credentials')->cascadeOnDelete();
            $table->string('session_id', 255)->unique();
            $table->timestamp('bound_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['slot_id', 'revoked_at']);
        });

        Schema::create('trusted_browser_audit_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->nullable()->constrained('trusted_browser_accounts')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('slot_id')->nullable()->constrained('trusted_browser_slots')->nullOnDelete();
            $table->foreignId('credential_id')->nullable()->constrained('trusted_browser_credentials')->nullOnDelete();
            $table->foreignUuid('challenge_id')->nullable()->constrained('trusted_browser_challenges')->nullOnDelete();
            $table->string('event_type', 80);
            $table->string('mode', 16)->nullable();
            $table->string('state', 64)->nullable();
            $table->char('session_hash', 64)->nullable();
            $table->char('ip_hash', 64)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at');

            $table->index(['event_type', 'created_at']);
            $table->index(['actor_user_id', 'event_type', 'created_at']);
            $table->index(['session_hash', 'event_type', 'created_at']);
            $table->index(['ip_hash', 'event_type', 'created_at']);
        });

        $this->addDatabaseConstraints();
    }

    public function down(): void
    {
        Schema::dropIfExists('trusted_browser_audit_events');
        Schema::dropIfExists('trusted_browser_session_links');
        Schema::dropIfExists('trusted_browser_challenges');
        Schema::dropIfExists('trusted_browser_credentials');
        Schema::dropIfExists('trusted_browser_slots');
        Schema::dropIfExists('trusted_browser_accounts');
    }

    private function addDatabaseConstraints(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE trusted_browser_slots ADD CONSTRAINT trusted_browser_slots_number_check CHECK (slot_number BETWEEN 1 AND 3)');
            DB::statement('ALTER TABLE trusted_browser_slots ADD CONSTRAINT trusted_browser_slots_generation_check CHECK (current_generation IS NULL OR current_generation > 0)');
            DB::statement('ALTER TABLE trusted_browser_credentials ADD CONSTRAINT trusted_browser_credentials_generation_check CHECK (generation > 0)');
            DB::statement('ALTER TABLE trusted_browser_challenges ADD CONSTRAINT trusted_browser_challenges_attempts_check CHECK (max_attempts > 0 AND attempts_left <= max_attempts)');

            return;
        }

        if ($driver === 'sqlite') {
            DB::statement("CREATE TRIGGER trusted_browser_slots_number_insert_check BEFORE INSERT ON trusted_browser_slots WHEN NEW.slot_number < 1 OR NEW.slot_number > 3 BEGIN SELECT RAISE(ABORT, 'trusted_browser slot_number must be between 1 and 3'); END");
            DB::statement("CREATE TRIGGER trusted_browser_slots_number_update_check BEFORE UPDATE OF slot_number ON trusted_browser_slots WHEN NEW.slot_number < 1 OR NEW.slot_number > 3 BEGIN SELECT RAISE(ABORT, 'trusted_browser slot_number must be between 1 and 3'); END");
            DB::statement("CREATE TRIGGER trusted_browser_credentials_generation_insert_check BEFORE INSERT ON trusted_browser_credentials WHEN NEW.generation < 1 BEGIN SELECT RAISE(ABORT, 'trusted_browser generation must be positive'); END");
            DB::statement("CREATE TRIGGER trusted_browser_challenges_attempts_insert_check BEFORE INSERT ON trusted_browser_challenges WHEN NEW.max_attempts < 1 OR NEW.attempts_left > NEW.max_attempts BEGIN SELECT RAISE(ABORT, 'trusted_browser attempts are invalid'); END");
        }
    }
};
