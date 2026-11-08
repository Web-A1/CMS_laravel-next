<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_authors', function (Blueprint $table): void {
            $table->id();
            $table->string('slug', 120)->unique();
            $table->string('name');
            $table->string('photo_path')->nullable();
            $table->text('bio')->nullable();
            $table->timestamps();
        });
        Schema::table('content_entries', function (Blueprint $table): void {
            $table->foreignId('content_author_id')->nullable()->constrained('content_authors')->nullOnDelete();
            $table->string('body_format', 16)->default('text');
            $table->text('excerpt')->nullable();
            $table->string('cover_path')->nullable();
            $table->string('cover_alt')->nullable();
            $table->unsignedInteger('cover_width')->nullable();
            $table->unsignedInteger('cover_height')->nullable();
            $table->json('tags')->nullable();
            $table->timestamp('content_updated_at')->nullable();
            $table->unsignedInteger('revision')->default(0);
        });
        Schema::create('article_audit_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('entity_type');
            $table->unsignedBigInteger('entity_id');
            $table->string('action');
            $table->json('before')->nullable();
            $table->json('after');
            $table->timestamp('created_at');
        });
        Schema::create('article_media_assets', function (Blueprint $table): void {
            $table->id();
            $table->string('filename', 100)->unique();
            $table->string('disk', 64);
            $table->string('key');
            $table->char('sha256', 64);
            $table->unsignedBigInteger('bytes');
            $table->string('mime', 120);
            $table->json('origin');
            $table->timestamp('created_at');
            $table->unique(['disk', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('article_audit_events');
        Schema::dropIfExists('article_media_assets');
        Schema::table('content_entries', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('content_author_id');
            $table->dropColumn(['body_format', 'excerpt', 'cover_path', 'cover_alt', 'cover_width', 'cover_height', 'tags', 'content_updated_at', 'revision']);
        });
        Schema::dropIfExists('content_authors');
    }
};
