<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('phone_verification_codes', function (Blueprint $table): void {
            $table->string('provider_uuid', 120)->nullable()->unique()->after('provider_event_id');
            $table->string('call_number', 40)->nullable()->after('provider_uuid');
        });

        Schema::table('pre_registration_verifications', function (Blueprint $table): void {
            $table->string('provider_uuid', 120)->nullable()->unique()->after('provider_event_id');
            $table->string('call_number', 40)->nullable()->after('provider_uuid');
        });
    }

    public function down(): void
    {
        Schema::table('phone_verification_codes', function (Blueprint $table): void {
            $table->dropColumn(['provider_uuid', 'call_number']);
        });

        Schema::table('pre_registration_verifications', function (Blueprint $table): void {
            $table->dropColumn(['provider_uuid', 'call_number']);
        });
    }
};
