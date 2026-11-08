<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_topics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->string('slug');
            $table->string('title');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('legacy_product_id')->nullable();
            $table->string('legacy_url')->nullable();
            $table->unsignedInteger('legacy_price_cents')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['course_id', 'slug']);
            $table->index(['course_id', 'is_active', 'sort_order']);
            $table->index('legacy_product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_topics');
    }
};
