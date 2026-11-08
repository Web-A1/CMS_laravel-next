<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('review_image_migrations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('review_id')->constrained('reviews')->cascadeOnDelete();
            $table->string('source_key', 64)->unique();
            $table->text('source_url');
            $table->string('source_sha256', 64);
            $table->json('references');
            $table->foreignUuid('asset_id')->nullable()->constrained('review_image_assets')->nullOnDelete();
            $table->timestamp('switched_at')->nullable();
            $table->timestamp('pruned_at')->nullable();
            $table->timestamps();
            $table->index(['review_id', 'source_sha256']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_image_migrations');
    }
};
