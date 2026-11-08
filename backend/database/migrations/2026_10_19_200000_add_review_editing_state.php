<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reviews', function (Blueprint $table): void {
            $table->boolean('media_managed')->default(false);
            $table->timestamp('content_edited_at')->nullable();
        });
        Schema::table('review_image_migrations', fn (Blueprint $table) => $table->timestamp('retired_at')->nullable());
        Schema::create('review_image_deletions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('review_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('asset_id')->nullable()->unique()->constrained('review_image_assets')->nullOnDelete();
            $table->json('files');
            $table->json('directories')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_image_deletions');
        Schema::table('review_image_migrations', fn (Blueprint $table) => $table->dropColumn('retired_at'));
        Schema::table('reviews', fn (Blueprint $table) => $table->dropColumn(['media_managed', 'content_edited_at']));
    }
};
