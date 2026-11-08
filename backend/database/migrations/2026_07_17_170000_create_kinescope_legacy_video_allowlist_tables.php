<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kinescope_legacy_video_allowlist_batches', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('source_count');
            $table->char('source_sha256', 64)->unique();
            $table->boolean('is_active')->default(false)->index();
            $table->timestampTz('activated_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('kinescope_legacy_video_allowlist_entries', function (Blueprint $table): void {
            $table->foreignId('batch_id')
                ->constrained('kinescope_legacy_video_allowlist_batches')
                ->cascadeOnDelete();
            $table->string('video_id');

            $table->primary(
                ['batch_id', 'video_id'],
                'kinescope_legacy_video_allowlist_entries_primary',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kinescope_legacy_video_allowlist_entries');
        Schema::dropIfExists('kinescope_legacy_video_allowlist_batches');
    }
};
