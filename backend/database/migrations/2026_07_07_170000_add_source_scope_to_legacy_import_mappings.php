<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('legacy_import_mappings', function (Blueprint $table): void {
            $table->string('source_scope_type', 32)->default('id_only')->after('source_id');
            $table->string('source_scope_id', 128)->default('id_only')->after('source_scope_type');
        });

        Schema::table('legacy_import_mappings', function (Blueprint $table): void {
            $table->dropUnique('legacy_import_mappings_source_unique');
            $table->unique(
                ['source_table', 'source_id', 'target_domain', 'source_scope_type', 'source_scope_id'],
                'legacy_import_mappings_scoped_source_unique'
            );
        });
    }

    public function down(): void
    {
        $hasScopedDuplicates = DB::table('legacy_import_mappings')
            ->select('source_table', 'source_id', 'target_domain')
            ->groupBy('source_table', 'source_id', 'target_domain')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($hasScopedDuplicates) {
            throw new RuntimeException(
                'Cannot rollback source-scoped legacy import mappings while duplicate source identities exist.'
            );
        }

        Schema::table('legacy_import_mappings', function (Blueprint $table): void {
            $table->dropUnique('legacy_import_mappings_scoped_source_unique');
            $table->dropColumn(['source_scope_type', 'source_scope_id']);
        });

        Schema::table('legacy_import_mappings', function (Blueprint $table): void {
            $table->unique(['source_table', 'source_id', 'target_domain'], 'legacy_import_mappings_source_unique');
        });
    }
};
