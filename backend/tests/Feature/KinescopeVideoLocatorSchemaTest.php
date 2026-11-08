<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class KinescopeVideoLocatorSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_kinescope_locator_is_separate_and_minimal(): void
    {
        $this->assertSame([
            'id',
            'lesson_material_id',
            'embed_locator',
            'provider_video_id',
        ], Schema::getColumnListing('lesson_material_kinescope_videos'));

        $indexes = collect(Schema::getIndexes('lesson_material_kinescope_videos'));
        $this->assertTrue($indexes->contains(
            fn (array $index): bool => $index['unique']
                && $index['columns'] === ['lesson_material_id']
        ));
        $this->assertTrue($indexes->contains(
            fn (array $index): bool => ! $index['unique']
                && $index['columns'] === ['embed_locator']
        ));
        $this->assertTrue($indexes->contains(
            fn (array $index): bool => ! $index['unique']
                && $index['columns'] === ['provider_video_id']
        ));

        $foreignKey = collect(Schema::getForeignKeys('lesson_material_kinescope_videos'))
            ->firstWhere('foreign_table', 'lesson_materials');
        $this->assertNotNull($foreignKey);
        $this->assertSame(['lesson_material_id'], $foreignKey['columns']);
        $this->assertSame('cascade', $foreignKey['on_delete']);
    }
}
