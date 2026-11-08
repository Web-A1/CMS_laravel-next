<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pre_registration_verifications', function (Blueprint $table): void {
            $table->string('status_token_hash')->nullable()->after('verification_token_hash');
        });
    }

    public function down(): void
    {
        Schema::table('pre_registration_verifications', function (Blueprint $table): void {
            $table->dropColumn('status_token_hash');
        });
    }
};
