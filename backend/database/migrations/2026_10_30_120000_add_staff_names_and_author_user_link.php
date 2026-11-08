<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('first_name', 120)->nullable();
            $table->string('last_name', 120)->nullable();
        });
        Schema::table('content_authors', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->unique()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('content_authors', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
        });
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['first_name', 'last_name']));
    }
};
