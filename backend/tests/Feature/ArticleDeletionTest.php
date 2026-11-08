<?php

namespace Tests\Feature;

use App\Models\ArticleMediaAsset;
use App\Models\ContentAuthor;
use App\Models\ContentEntry;
use App\Models\User;
use App\Services\Articles\ArticleEditor;
use App\Services\Articles\ArticleMediaLifecycle;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ArticleDeletionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        Queue::fake();
        Storage::fake('public');
        $this->actingAs(User::factory()->create(['role' => 'admin_full']), 'web');
    }

    private function entry(array $extra = []): ContentEntry
    {
        return ContentEntry::create(array_replace(['content_type' => 'article', 'slug' => 'article-'.uniqid(), 'title' => 'Private title', 'body' => 'Private body', 'status' => 'draft', 'revision' => 1], $extra));
    }

    private function asset(string $letter): ArticleMediaAsset
    {
        $sha = str_repeat($letter, 64);
        $key = 'article-media/'.$sha.'.jpg';
        $variant = 'article-media/variants/'.$sha.'/v1/64.webp';
        Storage::disk('public')->put($key, 'original');
        Storage::disk('public')->put($variant, 'variant');

        return ArticleMediaAsset::create(['filename' => $sha.'.jpg', 'disk' => 'public', 'key' => $key, 'sha256' => $sha, 'bytes' => 8, 'mime' => 'image/jpeg', 'origin' => ['kind' => 'test'], 'variants' => [['key' => $variant]], 'created_at' => now()]);
    }

    public function test_delete_purges_content_and_exact_dependencies_without_snapshots_and_deletes_all_asset_files(): void
    {
        $asset = $this->asset('a');
        $entry = $this->entry(['cover_path' => $asset->key]);
        $entry->placements()->create(['placement' => 'articles.index', 'is_enabled' => true]);
        foreach ([ContentEntry::class, ContentAuthor::class] as $type) {
            DB::table('article_audit_events')->insert(['entity_type' => $type, 'entity_id' => $entry->id, 'action' => 'update', 'before' => json_encode(['body' => 'Private body']), 'after' => '{}', 'created_at' => now()]);
        }
        $mapping = ['batch_key' => 'test', 'source_table' => 'news', 'source_id' => '1', 'target_domain' => 'articles', 'target_type' => ContentEntry::class, 'target_id' => $entry->id, 'idempotency_key' => 'delete-me', 'source_fingerprint_hash' => str_repeat('a', 64)];
        DB::table('legacy_import_mappings')->insert($mapping);
        DB::table('legacy_import_mappings')->insert(array_replace($mapping, ['source_table' => 'pages_author', 'idempotency_key' => 'keep-me']));
        $route = ['legacy_path' => '/old-article', 'target_path' => '/articles/'.$entry->slug, 'source_surface' => 'public', 'route_kind' => 'public', 'action' => 'redirect', 'metadata' => json_encode(['import' => 'articles-v1'])];
        DB::table('legacy_route_mappings')->insert($route);
        DB::table('legacy_route_mappings')->insert(array_replace($route, ['legacy_path' => '/unrelated', 'metadata' => '{}']));
        app(ArticleEditor::class)->delete($entry->id, 1);
        $this->assertDatabaseMissing('content_entries', ['id' => $entry->id]);
        $this->assertDatabaseCount('content_placements', 0);
        $this->assertDatabaseMissing('legacy_import_mappings', ['idempotency_key' => 'delete-me']);
        $this->assertDatabaseHas('legacy_import_mappings', ['idempotency_key' => 'keep-me']);
        $this->assertDatabaseMissing('legacy_route_mappings', ['legacy_path' => '/old-article']);
        $this->assertDatabaseHas('legacy_route_mappings', ['legacy_path' => '/unrelated']);
        $audit = DB::table('article_audit_events')->where('entity_type', ContentEntry::class)->sole();
        $this->assertSame('delete', $audit->action);
        $this->assertNull($audit->before);
        $this->assertSame(['id' => $entry->id, 'revision' => 1], json_decode($audit->after, true));
        $this->assertDatabaseHas('article_audit_events', ['entity_type' => ContentAuthor::class, 'action' => 'update']);
        $deletion = DB::table('article_media_deletions')->sole();
        app(ArticleMediaLifecycle::class)->delete($deletion->id);
        app(ArticleMediaLifecycle::class)->delete($deletion->id);
        Storage::disk('public')->assertMissing($asset->key);
        Storage::disk('public')->assertMissing($asset->variants[0]['key']);
        $this->assertDatabaseHas('article_media_deletions', ['id' => $deletion->id, 'status' => 'completed']);
        try {
            app(ArticleEditor::class)->delete($entry->id, 1);
            $this->fail('Repeated delete should report missing article');
        } catch (ModelNotFoundException) {
            $this->assertDatabaseCount('article_media_deletions', 1);
        }
    }

    public function test_shared_article_and_author_files_survive_and_payload_attachments_are_released(): void
    {
        $shared = $this->asset('a');
        $authorFile = $this->asset('b');
        $attachment = $this->asset('c');
        $author = ContentAuthor::create(['name' => 'Author', 'slug' => 'author', 'photo_path' => $authorFile->key]);
        $other = $this->entry(['body' => '<img src="/storage/'.$shared->key.'">', 'status' => 'hidden']);
        $entry = $this->entry(['cover_path' => $shared->key, 'body' => '<img src="/storage/'.$authorFile->key.'">', 'payload' => ['attachments' => [['url' => '/storage/'.$attachment->key]]]]);
        app(ArticleEditor::class)->delete($entry->id, 1);
        $this->assertSame('active', $shared->fresh()->status);
        $this->assertSame('active', $authorFile->fresh()->status);
        $this->assertSame('deleting', $attachment->fresh()->status);
        $this->assertNotNull($other->fresh());
        $this->assertNotNull($author->fresh());
        Storage::disk('public')->assertExists($shared->key);
        Storage::disk('public')->assertExists($authorFile->key);
    }

    public function test_stale_revision_and_forbidden_actor_and_faq_never_delete(): void
    {
        $entry = $this->entry();
        try {
            app(ArticleEditor::class)->delete($entry->id, 0);
            $this->fail('Stale delete accepted');
        } catch (ValidationException) {
            $this->assertNotNull($entry->fresh());
        }
        $faq = $this->entry(['content_type' => 'faq']);
        try {
            app(ArticleEditor::class)->delete($faq->id, 1);
            $this->fail('FAQ deleted');
        } catch (ModelNotFoundException) {
            $this->assertNotNull($faq->fresh());
        }
        $this->actingAs(User::factory()->create(['role' => 'teacher']), 'web');
        try {
            app(ArticleEditor::class)->delete($entry->id, 1);
            $this->fail('Forbidden delete accepted');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
            $this->assertNotNull($entry->fresh());
        }
    }

    public function test_storage_failure_retries_after_article_is_removed_and_outer_rollback_restores_article(): void
    {
        $asset = $this->asset('a');
        $entry = $this->entry(['cover_path' => $asset->key]);
        DB::beginTransaction();
        app(ArticleEditor::class)->delete($entry->id, 1);
        DB::rollBack();
        $this->assertNotNull($entry->fresh());
        $this->assertSame('active', $asset->fresh()->status);
        $this->assertDatabaseCount('article_media_deletions', 0);
        app(ArticleEditor::class)->delete($entry->id, 1);
        $id = DB::table('article_media_deletions')->value('id');
        $disk = Storage::disk('public');
        $broken = \Mockery::mock(FilesystemAdapter::class);
        $broken->shouldReceive('allFiles')->andReturn([]);
        $broken->shouldReceive('exists')->with($asset->key)->andReturn(true);
        $broken->shouldReceive('delete')->with($asset->key)->andReturn(false);
        Storage::set('public', $broken);
        app(ArticleMediaLifecycle::class)->delete($id);
        $this->assertDatabaseHas('article_media_deletions', ['id' => $id, 'status' => 'pending', 'attempts' => 1]);
        Storage::set('public', $disk);
        DB::table('article_media_deletions')->where('id', $id)->update(['retry_at' => now()->subSecond()]);
        app(ArticleMediaLifecycle::class)->delete($id);
        $this->assertDatabaseHas('article_media_deletions', ['id' => $id, 'status' => 'completed']);
        Storage::disk('public')->assertMissing($asset->key);
    }
}
