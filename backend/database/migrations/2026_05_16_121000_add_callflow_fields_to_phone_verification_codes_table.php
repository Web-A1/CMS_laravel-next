<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('phone_verification_codes', function (Blueprint $table): void {
            $table->uuid('verification_id')->nullable()->unique()->after('id');
            $table->string('status', 20)->default('pending')->after('attempts_left');
            $table->string('provider_event_id', 120)->nullable()->unique()->after('status');
            $table->json('provider_payload')->nullable()->after('provider_event_id');
            $table->timestamp('confirmed_at')->nullable()->after('consumed_at');
        });
    }

    public function down(): void
    {
        Schema::table('phone_verification_codes', function (Blueprint $table): void {
            $table->dropColumn([
                'verification_id',
                'status',
                'provider_event_id',
                'provider_payload',
                'confirmed_at',
            ]);
        });
    }
};
