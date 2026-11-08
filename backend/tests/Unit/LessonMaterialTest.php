<?php

namespace Tests\Unit;

use App\Models\LessonMaterial;
use PHPUnit\Framework\TestCase;

class LessonMaterialTest extends TestCase
{
    public function test_model_contract_contains_only_current_registry_states(): void
    {
        $material = new LessonMaterial;

        $this->assertFalse($material->usesTimestamps());
        $this->assertSame(['video', 'pdf', 'notes', 'file'], LessonMaterial::KINDS);
        $this->assertSame([
            'lesson_id',
            'guide_id',
            'kind',
            'title',
            'sort_order',
            'is_published',
        ], $material->getFillable());
        $this->assertSame('integer', $material->getCasts()['sort_order']);
        $this->assertSame('boolean', $material->getCasts()['is_published']);
    }
}
