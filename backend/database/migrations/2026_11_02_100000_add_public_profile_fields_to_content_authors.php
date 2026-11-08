<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_authors', function (Blueprint $table): void {
            $table->string('avatar_path')->nullable();
            $table->unsignedSmallInteger('home_sort_order')->nullable()->index();
            $table->string('home_role_text', 1000)->nullable();
            $table->string('home_cutout_key', 120)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('content_authors', function (Blueprint $table): void {
            $table->dropIndex(['home_sort_order']);
            $table->dropColumn(['avatar_path', 'home_sort_order', 'home_role_text', 'home_cutout_key']);
        });
    }
};
