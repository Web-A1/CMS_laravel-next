<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('provider_events', function (Blueprint $table): void {
            $table->unique(['provider', 'provider_event_id'], 'provider_events_provider_event_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('provider_events', function (Blueprint $table): void {
            $table->dropUnique('provider_events_provider_event_id_unique');
        });
    }
};
