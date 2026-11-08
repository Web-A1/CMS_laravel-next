<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('review_evidence', function (Blueprint $table): void {
            $table->unique(
                ['review_source_id', 'type', 'sha256'],
                'review_evidence_source_type_sha256_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('review_evidence', function (Blueprint $table): void {
            $table->dropUnique('review_evidence_source_type_sha256_unique');
        });
    }
};
