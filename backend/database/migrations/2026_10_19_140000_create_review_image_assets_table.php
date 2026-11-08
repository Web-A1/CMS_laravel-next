<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('review_image_assets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('review_id')->nullable()->constrained('reviews')->nullOnDelete();
            $table->string('kind', 20);
            $table->string('status', 20)->index();
            $table->string('disk');
            $table->string('primary_path')->unique();
            $table->string('source_path')->nullable();
            $table->string('source_mime', 40);
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->json('variants')->nullable();
            $table->string('error')->nullable();
            $table->timestamps();
            $table->index(['review_id', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_image_assets');
    }
};
