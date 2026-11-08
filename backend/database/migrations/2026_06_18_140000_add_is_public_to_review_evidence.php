<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('review_evidence', function (Blueprint $table): void {
            $table->boolean('is_public')->default(false)->after('sha256');
            $table->index(['is_public', 'type'], 'review_evidence_public_type_index');
        });
    }

    public function down(): void
    {
        Schema::table('review_evidence', function (Blueprint $table): void {
            $table->dropIndex('review_evidence_public_type_index');
            $table->dropColumn('is_public');
        });
    }
};
