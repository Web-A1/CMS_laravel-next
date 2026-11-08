<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('phone_verification_codes', function (Blueprint $table): void {
            $table->string('user_phone_at_start', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('phone_verification_codes', function (Blueprint $table): void {
            $table->dropColumn('user_phone_at_start');
        });
    }
};
