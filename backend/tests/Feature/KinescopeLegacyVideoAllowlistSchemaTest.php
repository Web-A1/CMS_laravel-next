<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class KinescopeLegacyVideoAllowlistSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_versioned_allowlist_schema_is_minimal_and_rollback_safe(): void
    {
        $this->assertSame([
            'id',
            'source_count',
            'source_sha256',
            'is_active',
            'activated_at',
            'created_at',
            'updated_at',
        ], Schema::getColumnListing('kinescope_legacy_video_allowlist_batches'));
        $this->assertSame([
            'batch_id',
            'embed_locator',
            'provider_video_id',
        ], Schema::getColumnListing('kinescope_legacy_video_allowlist_entries'));

        $batchIndexes = collect(Schema::getIndexes('kinescope_legacy_video_allowlist_batches'));
        $this->assertTrue($batchIndexes->contains(
            fn (array $index): bool => $index['unique'] && $index['columns'] === ['source_sha256']
        ));
        $this->assertTrue($batchIndexes->contains(
            fn (array $index): bool => $index['columns'] === ['is_active']
        ));

        $entryIndexes = collect(Schema::getIndexes('kinescope_legacy_video_allowlist_entries'));
        $this->assertTrue($entryIndexes->contains(
            fn (array $index): bool => $index['primary'] && $index['columns'] === ['batch_id', 'embed_locator']
        ));
        $this->assertTrue($entryIndexes->contains(
            fn (array $index): bool => ! $index['unique']
                && $index['columns'] === ['batch_id', 'provider_video_id']
        ));
        $this->assertFalse($entryIndexes->contains(
            fn (array $index): bool => $index['unique']
                && $index['columns'] === ['batch_id', 'provider_video_id']
        ));

        $foreignKey = collect(Schema::getForeignKeys('kinescope_legacy_video_allowlist_entries'))
            ->firstWhere('foreign_table', 'kinescope_legacy_video_allowlist_batches');
        $this->assertNotNull($foreignKey);
        $this->assertSame(['batch_id'], $foreignKey['columns']);
        $this->assertSame('cascade', strtolower($foreignKey['on_delete']));

        $migration = require database_path('migrations/2026_07_17_170000_create_kinescope_legacy_video_allowlist_tables.php');
        $migration->down();

        $this->assertFalse(Schema::hasTable('kinescope_legacy_video_allowlist_entries'));
        $this->assertFalse(Schema::hasTable('kinescope_legacy_video_allowlist_batches'));
        $this->assertTrue(Schema::hasTable('lesson_material_kinescope_videos'));
    }
}
