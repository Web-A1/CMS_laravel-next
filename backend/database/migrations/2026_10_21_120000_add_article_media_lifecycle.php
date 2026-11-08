<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('article_media_assets', function (Blueprint $table): void {
            $table->string('status', 24)->default('active')->index();
            $table->unsignedInteger('generation')->default(1);
            $table->string('processing_status', 24)->default('pending')->index();
            $table->json('variants')->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->timestamp('lease_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->text('processing_error')->nullable();
        });
        Schema::create('article_media_deletions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('asset_id')->constrained('article_media_assets');
            $table->unsignedInteger('generation');
            $table->string('status', 24)->default('pending')->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('retry_at')->nullable();
            $table->text('error')->nullable();
            $table->json('manifest');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['asset_id', 'generation']);
        });
        Schema::create('article_media_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('asset_id');
            $table->unsignedInteger('generation');
            $table->string('event', 64);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('article_media_events');
        Schema::dropIfExists('article_media_deletions');
        Schema::table('article_media_assets', function (Blueprint $table): void {
            $table->dropColumn(['status', 'generation', 'processing_status', 'variants', 'width', 'height', 'lease_at', 'deleted_at', 'processing_error']);
        });
    }
};
