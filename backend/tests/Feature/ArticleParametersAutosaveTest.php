<?php

namespace Tests\Feature;

use App\Models\ArticleMediaAsset;
use App\Models\ContentAuthor;
use App\Models\ContentEntry;
use App\Models\Subject;
use App\Models\User;
use App\Services\Articles\ArticleEditor;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ArticleParametersAutosaveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('public');
        $this->actingAs(User::factory()->create(['role' => 'admin_full']), 'web');
    }

    private function entry(array $overrides = []): ContentEntry
    {
        return ContentEntry::create(array_replace([
            'content_type' => 'article', 'title' => 'Saved title', 'slug' => 'autosave-test',
            'body' => '<p>Saved body</p>', 'excerpt' => 'Saved excerpt', 'body_format' => 'html',
            'status' => 'draft', 'revision' => 1, 'payload' => ['untouched' => true],
        ], $overrides));
    }

    public function test_partial_update_preserves_manual_attributes_and_noop_has_no_audit_or_revision_delta(): void
    {
        $entry = $this->entry();
        $before = $entry->getAttributes();
        $saved = app(ArticleEditor::class)->saveParameters($entry->id, ['status' => 'hidden'], 1);
        foreach (['title', 'slug', 'body', 'excerpt', 'body_format', 'payload', 'seo_title', 'cover_alt'] as $key) {
            $this->assertSame($before[$key] ?? null, $saved->getAttributes()[$key] ?? null, $key);
        }
        $this->assertSame(2, $saved->revision);
        $this->assertSame($entry->tags, $saved->tags);
        $this->assertDatabaseCount('article_audit_events', 1);
        $same = app(ArticleEditor::class)->saveParameters($entry->id, ['status' => 'hidden'], 2);
        $this->assertSame($saved->getAttributes(), $same->getAttributes());
        $this->assertDatabaseCount('article_audit_events', 1);
    }

    public function test_whitelist_revision_and_publication_pair_are_atomic(): void
    {
        $entry = $this->entry();
        foreach ([['title' => 'Must not save'], ['status' => 'published'], ['tags' => [str_repeat('x', 121)]]] as $patch) {
            try {
                app(ArticleEditor::class)->saveParameters($entry->id, $patch, 1);
                $this->fail('Invalid patch accepted');
            } catch (ValidationException) {
                $this->assertSame(1, $entry->fresh()->revision);
                $this->assertDatabaseCount('article_audit_events', 0);
            }
        }
        $saved = app(ArticleEditor::class)->saveParameters($entry->id, ['status' => 'published', 'published_at' => '2026-10-31 09:30:00'], 1);
        $this->assertSame('published', $saved->status);
        foreach ([[['published_at' => null], 2], [['status' => 'published'], 1]] as [$patch, $revision]) {
            try {
                app(ArticleEditor::class)->saveParameters($entry->id, $patch, $revision);
                $this->fail('Invalid pair or stale noop accepted');
            } catch (ValidationException) {
                $this->assertSame(2, $entry->fresh()->revision);
            }
        }
    }

    public function test_cover_clear_veto_rolls_back_audit_revision_and_media_then_allows_shared_reference(): void
    {
        $hash = str_repeat('a', 64);
        $path = 'article-media/'.$hash.'.jpg';
        $asset = ArticleMediaAsset::create(['filename' => $hash.'.jpg', 'disk' => 'public', 'key' => $path,
            'sha256' => $hash, 'bytes' => 12, 'mime' => 'image/jpeg', 'origin' => ['kind' => 'test'], 'created_at' => now(), 'lease_at' => now()]);
        $entry = $this->entry(['cover_path' => $path]);
        try {
            app(ArticleEditor::class)->saveParameters($entry->id, ['cover_path' => null, 'status' => 'hidden'], 1, [$hash]);
            $this->fail('Unsafe clear accepted');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('data.cover_path', $exception->errors());
        }
        $this->assertSame(1, $entry->fresh()->revision);
        $this->assertSame($path, $entry->fresh()->cover_path);
        $this->assertDatabaseCount('article_audit_events', 0);
        $this->assertDatabaseCount('article_media_deletions', 0);
        $shared = $this->entry(['slug' => 'shared', 'body' => '<img src="/storage/'.$path.'">']);
        app(ArticleEditor::class)->saveParameters($entry->id, ['cover_path' => null], 1, [$hash]);
        $this->assertSame('active', $asset->fresh()->status);
        $shared->delete();
        app(ArticleEditor::class)->saveParameters($entry->id, ['cover_path' => $path], 2);
        $this->assertNull($asset->fresh()->lease_at);
        app(ArticleEditor::class)->saveParameters($entry->id, ['cover_path' => null], 3);
        $this->assertSame('deleting', $asset->fresh()->status);
        $this->assertSame(1, DB::table('article_media_deletions')->count());
    }

    public function test_lookup_rules_nullable_clear_and_registered_cover_fail_without_partial_writes(): void
    {
        $active = Subject::create(['slug' => 'active', 'title' => 'Active', 'is_active' => true]);
        $inactive = Subject::create(['slug' => 'inactive', 'title' => 'Inactive', 'is_active' => false]);
        $author = ContentAuthor::create(['slug' => 'author', 'name' => 'Author']);
        $entry = $this->entry(['subject_id' => $inactive->id]);
        $same = app(ArticleEditor::class)->saveParameters($entry->id, ['subject_id' => (string) $inactive->id], 1);
        $this->assertSame(1, $same->revision);
        app(ArticleEditor::class)->saveParameters($entry->id, ['subject_id' => $active->id, 'content_author_id' => $author->id], 1);
        foreach ([['subject_id' => $inactive->id], ['subject_id' => 99999], ['content_author_id' => 99999], ['cover_path' => 'article-media/'.str_repeat('f', 64).'.jpg']] as $patch) {
            try {
                app(ArticleEditor::class)->saveParameters($entry->id, $patch + ['status' => 'hidden'], 2);
                $this->fail('Invalid lookup or unregistered cover accepted');
            } catch (ValidationException) {
                $this->assertSame(2, $entry->fresh()->revision);
                $this->assertSame('draft', $entry->fresh()->status);
            }
        }
        app(ArticleEditor::class)->saveParameters($entry->id, ['subject_id' => null, 'content_author_id' => null], 2);
        $this->assertNull($entry->fresh()->subject_id);
        $this->assertNull($entry->fresh()->content_author_id);
        $this->assertNull($entry->fresh()->tags);
    }

    public function test_impersonation_and_non_article_scope_cannot_write(): void
    {
        $entry = $this->entry();
        request()->attributes->set('impersonation.active', true);
        try {
            app(ArticleEditor::class)->saveParameters($entry->id, ['status' => 'hidden'], 1);
            $this->fail('Impersonation write accepted');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        request()->attributes->remove('impersonation.active');
        $entry->update(['content_type' => 'guide']);
        try {
            app(ArticleEditor::class)->saveParameters($entry->id, ['status' => 'hidden'], 1);
            $this->fail('Non-article update accepted');
        } catch (ModelNotFoundException) {
            $this->assertSame('draft', $entry->fresh()->status);
            $this->assertSame(1, $entry->fresh()->revision);
        }
    }

    public function test_revoked_actor_cannot_write(): void
    {
        $entry = $this->entry();
        auth('web')->user()->update(['role' => 'student']);
        $this->expectException(HttpException::class);
        app(ArticleEditor::class)->saveParameters($entry->id, ['status' => 'hidden'], 1);
    }
}
