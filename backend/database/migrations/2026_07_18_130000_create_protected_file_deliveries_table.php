<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('protected_file_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_topic_material_file_id')
                ->constrained('course_topic_material_files')
                ->cascadeOnDelete();
            $table->char('source_sha256', 64);
            $table->string('copy_code', 32)->unique();
            $table->string('status', 20)->default('pending');
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->uuid('lease_token')->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->string('output_object_key', 1024)->nullable();
            $table->char('output_sha256', 64)->nullable();
            $table->unsignedBigInteger('output_size_bytes')->nullable();
            $table->string('last_error_code', 64)->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['user_id', 'course_topic_material_file_id', 'source_sha256'],
                'protected_file_deliveries_user_source_version_unique',
            );
            $table->index(
                ['status', 'lease_expires_at'],
                'protected_file_deliveries_status_lease_index',
            );
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE protected_file_deliveries ADD CONSTRAINT protected_file_deliveries_status_check CHECK (status IN ('pending', 'processing', 'ready', 'failed'))");
            DB::statement('ALTER TABLE protected_file_deliveries ADD CONSTRAINT protected_file_deliveries_attempt_count_check CHECK (attempt_count >= 0)');
            DB::statement('ALTER TABLE protected_file_deliveries ADD CONSTRAINT protected_file_deliveries_output_size_check CHECK (output_size_bytes IS NULL OR output_size_bytes > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('protected_file_deliveries');
    }
};
