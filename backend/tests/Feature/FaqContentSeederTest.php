<?php

namespace Tests\Feature;

use App\Models\ContentCategory;
use App\Models\ContentEntry;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\FaqContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\TestCase;

class FaqContentSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_bootstrap_imports_seven_pairs_and_refuses_to_overwrite_edits_or_restore_deletions(): void
    {
        $this->seed(FaqContentSeeder::class);
        $this->assertSame(7, ContentEntry::query()->faq()->count());
        $entry = ContentEntry::query()->faq()->firstOrFail();
        $entry->update(['body' => 'Редакторский ответ']);
        ContentEntry::query()->faq()->whereKeyNot($entry->id)->firstOrFail()->delete();

        try {
            $this->seed(FaqContentSeeder::class);
            $this->fail('Existing FAQ must prevent bootstrap.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('bootstrap', $e->getMessage());
        }
        $this->assertSame(6, ContentEntry::query()->faq()->count());
        $this->assertSame('Редакторский ответ', $entry->fresh()->body);
    }

    public function test_categories_alone_prevent_bootstrap_after_all_questions_were_deleted(): void
    {
        ContentCategory::query()->create(['content_type' => 'faq', 'slug' => 'kept', 'title' => 'Категория']);
        $this->expectException(RuntimeException::class);
        $this->seed(FaqContentSeeder::class);
    }

    public function test_unknown_category_is_rejected_before_any_write(): void
    {
        File::shouldReceive('get')->once()->andReturn(json_encode([
            'categories' => [['slug' => 'valid', 'title' => 'Valid']],
            'entries' => [['category' => 'missing', 'slug' => 'bad', 'question' => 'Bad?', 'answer' => 'Bad']],
        ], JSON_THROW_ON_ERROR));
        try {
            $this->seed(FaqContentSeeder::class);
            $this->fail('Invalid category must prevent import.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('missing', $e->getMessage());
        }
        $this->assertDatabaseCount('content_categories', 0);
        $this->assertDatabaseCount('content_entries', 0);
    }

    public function test_standard_seeder_does_not_call_faq_bootstrap(): void
    {
        $seeder = new class extends DatabaseSeeder
        {
            public array $calledSeeders = [];

            public function call($classes, $silent = false, array $parameters = [])
            {
                $this->calledSeeders = [...$this->calledSeeders, ...(array) $classes];

                return $this;
            }
        };
        $seeder->run();
        $this->assertNotContains(FaqContentSeeder::class, $seeder->calledSeeders);
    }
}
