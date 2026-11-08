<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promo_codes', function (Blueprint $table): void {
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->softDeletesTz();
        });
    }

    public function down(): void
    {
        if (DB::table('promo_codes')->whereNotNull('created_by')->orWhereNotNull('deleted_at')->exists()) {
            throw new RuntimeException('Promo ownership or deletion history exists; rollback refused.');
        }
        Schema::table('promo_codes', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('created_by');
            $table->dropSoftDeletes();
        });
    }
};
