<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('content_type', 64);
            $table->string('slug', 120);
            $table->string('title');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['content_type', 'slug']);
            $table->index(['content_type', 'is_active', 'sort_order']);
        });

        Schema::create('content_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('content_category_id')->nullable()->constrained('content_categories')->nullOnDelete();
            $table->string('content_type', 64);
            $table->string('slug', 120);
            $table->string('title');
            $table->text('body');
            $table->string('status', 32)->default('draft');
            $table->unsignedInteger('sort_order')->default(0);
            $table->json('payload')->nullable();
            $table->string('seo_title')->nullable();
            $table->string('seo_description', 500)->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(['content_type', 'slug']);
            $table->index(['content_type', 'status', 'published_at']);
            $table->index(['content_category_id', 'status']);
            $table->index(['content_type', 'sort_order']);
        });

        Schema::create('content_placements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('content_entry_id')->constrained('content_entries')->cascadeOnDelete();
            $table->string('placement', 120);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();

            $table->unique(['content_entry_id', 'placement']);
            $table->index(['placement', 'is_enabled', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_placements');
        Schema::dropIfExists('content_entries');
        Schema::dropIfExists('content_categories');
    }
};
