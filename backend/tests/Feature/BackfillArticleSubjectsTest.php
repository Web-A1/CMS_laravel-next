<?php

namespace Tests\Feature;

use App\Models\ContentEntry;
use App\Models\LegacyImportMapping;
use App\Models\Subject;
use App\Services\Articles\LegacyArticleImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\SeedsArticleSubjects;
use Tests\TestCase;

class BackfillArticleSubjectsTest extends TestCase
{
    use RefreshDatabase;
    use SeedsArticleSubjects;

    private array $backups = [];

    protected function tearDown(): void
    {
        foreach ($this->backups as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        parent::tearDown();
    }

    private function backupPath(): string
    {
        return $this->backups[] = base_path('../demo-imports/backups/taxonomy-test-'.bin2hex(random_bytes(8)).'.json');
    }

    public function test_preview_apply_backup_repeat_manual_classification_and_clear_conflict(): void
    {
        $this->seedArticles();
        $before = ContentEntry::query()->orderBy('id')->get()->map(fn ($e) => $e->getAttributes())->all();
        $mappings = LegacyImportMapping::query()->orderBy('id')->get()->map(fn ($m) => $m->getAttributes())->all();
        $this->artisan('articles:backfill-subjects')->expectsOutputToContain('146 changes proposed')->assertSuccessful();
        $this->assertSame($before, ContentEntry::query()->orderBy('id')->get()->map(fn ($e) => $e->getAttributes())->all());
        $path = $this->backupPath();
        $this->artisan('articles:backfill-subjects', ['--apply' => true, '--backup' => $path])->expectsOutputToContain('146 changes applied')->assertSuccessful();
        $backup = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($before, array_values($backup['articles']));
        $this->assertSame($mappings, $backup['mappings']);
        $this->assertSame(146, ContentEntry::whereNotNull('subject_id')->count());
        $this->assertSame(79, ContentEntry::whereNull('subject_id')->count());
        $this->assertSame(146, DB::table('article_audit_events')->where('action', 'backfill_subject')->count());
        foreach ($before as $attributes) {
            $current = ContentEntry::findOrFail($attributes['id'])->getAttributes();
            foreach (['subject_id', 'revision', 'updated_at'] as $field) {
                unset($current[$field], $attributes[$field]);
            }
            $this->assertSame($attributes, $current);
        }
        $this->assertSame($mappings, LegacyImportMapping::query()->orderBy('id')->get()->map(fn ($m) => $m->getAttributes())->all());
        $entry = ContentEntry::whereNotNull('subject_id')->firstOrFail();
        $entry->update(['subject_id' => 1, 'revision' => 2]);
        $this->artisan('articles:backfill-subjects', ['--apply' => true, '--backup' => $path])->expectsOutputToContain('0 changes applied')->assertSuccessful();
        $this->assertSame(1, $entry->fresh()->subject_id);
        $entry->update(['subject_id' => null, 'revision' => 3]);
        $this->artisan('articles:backfill-subjects', ['--apply' => true, '--backup' => $this->backupPath()])->assertFailed();
        $this->assertNull($entry->fresh()->subject_id);
    }

    public function test_identity_missing_mapping_and_backup_failure_refuse_all_writes(): void
    {
        $this->seedArticles();
        $mapping = LegacyImportMapping::query()->firstOrFail();
        $mapping->update(['source_fingerprint_hash' => str_repeat('0', 64)]);
        $path = $this->backupPath();
        $this->artisan('articles:backfill-subjects', ['--apply' => true, '--backup' => $path])->assertFailed();
        $this->assertFileDoesNotExist($path);
        $this->assertSame(0, ContentEntry::whereNotNull('subject_id')->count());
        $mapping->update(['source_fingerprint_hash' => hash('sha256', json_encode($mapping->metadata['source'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))]);
        $this->artisan('articles:backfill-subjects', ['--apply' => true, '--backup' => '/tmp/not-allowed-taxonomy.json'])->assertFailed();
        $this->assertDatabaseCount('article_audit_events', 0);
        $mapping->delete();
        $this->artisan('articles:backfill-subjects', ['--apply' => true, '--backup' => $path])->assertFailed();
        $this->assertSame(0, ContentEntry::whereNotNull('subject_id')->count());
    }

    public function test_audit_failure_rolls_back_entire_backfill_after_verified_backup(): void
    {
        $this->seedArticles();
        DB::statement("CREATE TRIGGER reject_subject_audit BEFORE INSERT ON article_audit_events WHEN NEW.entity_id = 5 BEGIN SELECT RAISE(ABORT, 'audit unavailable'); END");
        $path = $this->backupPath();
        $this->artisan('articles:backfill-subjects', ['--apply' => true, '--backup' => $path])->assertFailed();
        $this->assertFileExists($path);
        $this->assertSame(0, ContentEntry::whereNotNull('subject_id')->count());
        $this->assertDatabaseCount('article_audit_events', 0);
    }

    public function test_unmapped_hidden_article_outside_verified_import_is_preserved(): void
    {
        $this->seedArticles();
        $extra = ContentEntry::create(['slug' => 'old-qa', 'title' => 'Unmapped hidden QA', 'body' => 'Preserve', 'content_type' => 'article', 'status' => 'hidden']);
        $before = $extra->fresh()->getAttributes();
        $this->artisan('articles:backfill-subjects')->expectsOutputToContain('146 changes proposed')->assertSuccessful();
        $path = $this->backupPath();
        $this->artisan('articles:backfill-subjects', ['--apply' => true, '--backup' => $path])->expectsOutputToContain('146 changes applied')->assertSuccessful();
        $this->assertSame($before, $extra->fresh()->getAttributes());
        $this->assertSame(146, ContentEntry::whereNotNull('subject_id')->count());
        $this->assertSame(80, ContentEntry::whereNull('subject_id')->count());
        $this->assertCount(225, json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR)['articles']);
    }

    public function test_import_hash_ignores_only_null_subject_and_preserves_editor_conflicts(): void
    {
        $entry = ContentEntry::create(['content_type' => 'article', 'slug' => 'hash', 'title' => 'Hash', 'body' => 'Body']);
        $importer = app(LegacyArticleImport::class);
        $method = new \ReflectionMethod($importer, 'targetHash');
        $old = $entry->fresh()->getAttributes();
        unset($old['created_at'], $old['updated_at'], $old['subject_id']);
        ksort($old);
        $hash = hash('sha256', json_encode($old, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->assertSame($hash, $method->invoke($importer, $entry->fresh()));
        $subject = Subject::create(['slug' => 'hash-subject', 'title' => 'Hash']);
        $entry->update(['subject_id' => $subject->id]);
        $this->assertNotSame($hash, $method->invoke($importer, $entry->fresh()));
        $entry->update(['subject_id' => null, 'revision' => 1]);
        $this->assertNotSame($hash, $method->invoke($importer, $entry->fresh()));
    }
}
