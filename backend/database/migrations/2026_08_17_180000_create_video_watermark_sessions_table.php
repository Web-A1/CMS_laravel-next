<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('video_watermark_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 13)->unique();
            $table->char('auth_session_hash', 64)->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('last_used_at');
            $table->timestamp('ended_at')->nullable();
            $table->enum('end_reason', ['logout', 'idle_timeout', 'revoked', 'orphan_expired'])->nullable();
            $table->timestamp('purge_after')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'started_at']);
            $table->index(['ended_at', 'last_used_at']);
            $table->index(['purge_after']);
        });

        Schema::create('video_watermark_lookup_audit_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->uuid('watermark_session_id')->nullable();
            $table->char('normalized_code', 11)->nullable();
            $table->enum('result', ['found', 'not_found']);
            $table->timestamp('created_at');

            $table->index(['actor_user_id', 'created_at']);
            $table->index(['normalized_code', 'created_at']);
        });

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement("ALTER TABLE video_watermark_sessions ADD CONSTRAINT video_watermark_sessions_code_check CHECK (code ~ '^CMS-[2-9A-HJKMNP-Z]{4}-[2-9A-HJKMNP-Z]{4}$')");
            DB::statement("ALTER TABLE video_watermark_lookup_audit_events ADD CONSTRAINT video_watermark_lookup_code_check CHECK (normalized_code ~ '^CMS[2-9A-HJKMNP-Z]{8}$')");
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION video_watermark_lookup_audit_events_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
                BEGIN
                    RAISE EXCEPTION 'Video watermark lookup audit events are append-only.';
                END;
                $$;

                CREATE TRIGGER video_watermark_lookup_audit_events_immutable_trigger
                BEFORE UPDATE OR DELETE ON video_watermark_lookup_audit_events
                FOR EACH ROW EXECUTE FUNCTION video_watermark_lookup_audit_events_immutable();

                CREATE TRIGGER video_watermark_lookup_audit_events_truncate_immutable_trigger
                BEFORE TRUNCATE ON video_watermark_lookup_audit_events
                FOR EACH STATEMENT EXECUTE FUNCTION video_watermark_lookup_audit_events_immutable();
                SQL);

            return;
        }

        if ($driver === 'sqlite') {
            DB::statement("CREATE TRIGGER video_watermark_lookup_audit_events_update_immutable BEFORE UPDATE ON video_watermark_lookup_audit_events BEGIN SELECT RAISE(ABORT, 'Video watermark lookup audit events are append-only.'); END");
            DB::statement("CREATE TRIGGER video_watermark_lookup_audit_events_delete_immutable BEFORE DELETE ON video_watermark_lookup_audit_events BEGIN SELECT RAISE(ABORT, 'Video watermark lookup audit events are append-only.'); END");
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS video_watermark_lookup_audit_events_immutable_trigger ON video_watermark_lookup_audit_events');
            DB::statement('DROP TRIGGER IF EXISTS video_watermark_lookup_audit_events_truncate_immutable_trigger ON video_watermark_lookup_audit_events');
            DB::statement('DROP FUNCTION IF EXISTS video_watermark_lookup_audit_events_immutable()');
        }

        if ($driver === 'sqlite') {
            DB::statement('DROP TRIGGER IF EXISTS video_watermark_lookup_audit_events_update_immutable');
            DB::statement('DROP TRIGGER IF EXISTS video_watermark_lookup_audit_events_delete_immutable');
        }

        Schema::dropIfExists('video_watermark_lookup_audit_events');
        Schema::dropIfExists('video_watermark_sessions');
    }
};
