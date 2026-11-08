<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->dropIndexIfExists('kinescope_legacy_video_allowlist_entries', 'kinescope_legacy_allowlist_batch_provider_video_unique', unique: true);

        Schema::table('kinescope_legacy_video_allowlist_entries', function (Blueprint $table): void {
            if (! Schema::hasIndex('kinescope_legacy_video_allowlist_entries', 'kinescope_legacy_allowlist_batch_provider_video_index')) {
                $table->index(
                    ['batch_id', 'provider_video_id'],
                    'kinescope_legacy_allowlist_batch_provider_video_index',
                );
            }
        });
    }

    public function down(): void
    {
        $this->dropIndexIfExists('kinescope_legacy_video_allowlist_entries', 'kinescope_legacy_allowlist_batch_provider_video_index');

        Schema::table('kinescope_legacy_video_allowlist_entries', function (Blueprint $table): void {
            if (! Schema::hasIndex('kinescope_legacy_video_allowlist_entries', 'kinescope_legacy_allowlist_batch_provider_video_unique')) {
                $table->unique(
                    ['batch_id', 'provider_video_id'],
                    'kinescope_legacy_allowlist_batch_provider_video_unique',
                );
            }
        });
    }

    private function dropIndexIfExists(string $tableName, string $indexName, bool $unique = false): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            DB::statement("DROP INDEX IF EXISTS {$indexName}");

            return;
        }

        if (Schema::hasIndex($tableName, $indexName)) {
            Schema::table($tableName, function (Blueprint $table) use ($indexName, $unique): void {
                $unique
                    ? $table->dropUnique($indexName)
                    : $table->dropIndex($indexName);
            });
        }
    }
};
