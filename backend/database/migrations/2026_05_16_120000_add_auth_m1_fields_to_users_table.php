<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('legacy_auto_verified')->default(false)->after('email_verified_at');
            $table->string('password_state', 20)->default('set')->after('password');
            $table->string('last_auth_method', 20)->nullable()->after('password_state');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['legacy_auto_verified', 'password_state', 'last_auth_method']);
        });
    }
};
