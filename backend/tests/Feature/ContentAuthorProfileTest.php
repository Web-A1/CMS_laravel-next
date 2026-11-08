<?php

namespace Tests\Feature;

use App\Http\Resources\ArticleResource;
use App\Models\ArticleMediaAsset;
use App\Models\ContentAuthor;
use App\Models\ContentEntry;
use App\Models\User;
use App\Services\Articles\ArticleEditor;
use App\Services\Articles\ArticleMedia;
use App\Services\Articles\ArticleMediaLifecycle;
use App\Services\Articles\ArticlePreview;
use Dom\HTMLDocument;
use Dom\XPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ContentAuthorProfileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('public');
    }

    private function asset(string $letter): ArticleMediaAsset
    {
        $hash = str_repeat($letter, 64);

        return ArticleMediaAsset::create(['filename' => $hash.'.webp', 'disk' => 'public', 'key' => 'article-media/'.$hash.'.webp', 'sha256' => $hash, 'bytes' => 12, 'mime' => 'image/webp', 'origin' => ['kind' => 'test'], 'created_at' => now(), 'lease_at' => now()]);
    }

    public function test_home_order_and_public_allowlist_are_shared_with_article_and_preview(): void
    {
        $photo = $this->asset('a');
        $avatar = $this->asset('b');
        $avatar->update(['processing_status' => 'ready', 'variants' => [
            ['key' => 'article-media/variants/'.$avatar->sha256.'/v1/64.webp', 'format' => 'webp', 'width' => 64, 'height' => 64, 'bytes' => 12],
            ['key' => 'article-media/variants/'.$avatar->sha256.'/v1/128.avif', 'format' => 'avif', 'width' => 128, 'height' => 128, 'bytes' => 20],
        ]]);
        $user = User::factory()->create(['email' => 'private@example.test']);
        $second = ContentAuthor::create(['name' => 'Second', 'slug' => 'second', 'bio' => 'Private biography', 'photo_path' => $photo->key, 'avatar_path' => $avatar->key, 'home_sort_order' => 2, 'home_role_text' => 'Role', 'home_cutout_key' => 'demo-author-6']);
        DB::table('content_authors')->where('id', $second->id)->update(['user_id' => $user->id]);
        $first = ContentAuthor::create(['name' => 'First', 'slug' => 'first', 'home_sort_order' => 1]);
        ContentAuthor::create(['name' => 'Historical', 'slug' => 'historical']);
        $response = $this->getJson('/api/v1/content-authors?placement=home')->assertOk();
        $this->assertSame([$first->id, $second->id], array_column($response->json('data'), 'id'));
        $dto = $response->json('data.1');
        $this->assertSame(['id', 'name', 'slug', 'avatarUrl', 'avatarVariants', 'photoUrl', 'photoVariants', 'aboutUrl', 'homeRoleText', 'cutoutKey'], array_keys($dto));
        $this->assertSame('/storage/'.$avatar->key, $dto['avatarUrl']);
        $this->assertSame(64, $dto['avatarVariants'][0]['width']);
        $this->assertStringNotContainsString('Private biography', $response->getContent());
        $this->assertStringNotContainsString($user->email, $response->getContent());
        $entry = ContentEntry::create(['content_type' => 'article', 'slug' => 'test', 'title' => 'Test', 'body' => '<p>Body</p>', 'body_format' => 'html', 'content_author_id' => $second->id]);
        request()->attributes->remove('articles.mediaAssets');
        request()->attributes->remove('articles.mediaUrls');
        app(ArticleMedia::class)->prime([$entry->load('author')]);
        $this->assertArrayHasKey($avatar->key, request()->attributes->get('articles.mediaAssets'));
        $article = (new ArticleResource($entry->load('author')))->resolve(request());
        unset($dto['homeRoleText'], $dto['cutoutKey']);
        $this->assertSame($dto, $article['author']);
        $preview = app(ArticlePreview::class)->document(['title' => 'Preview', 'body' => '<p>Body</p>', 'content_author_id' => $second->id, 'published_at' => '2026-11-02 12:00:00'], []);
        $this->assertStringNotContainsString('/storage/'.$avatar->key, $preview);
        $this->assertStringContainsString('Second', $preview);
        $this->assertStringNotContainsString('Private biography', $preview);
        $dom = HTMLDocument::createFromString($preview, \Dom\HTML_NO_DEFAULT_NS);
        $xpath = new XPath($dom);
        $this->assertSame(0, $xpath->query('//header//picture | //header//img')->length);
        $this->assertSame('Preview', $xpath->evaluate('string(//header//h1)'));
        $this->assertSame('Second', $xpath->evaluate('string(//p[contains(@class,"article-preview-meta")]/span[1])'));
        $this->assertSame('02.11.2026', $xpath->evaluate('string(//p[contains(@class,"article-preview-meta")]/span/time)'));
        $this->getJson('/api/v1/content-authors')->assertUnprocessable();
        $this->getJson('/api/v1/content-authors?placement=all')->assertUnprocessable();
    }

    public function test_incomplete_home_profiles_are_rejected_for_create_and_full_or_partial_updates(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin_full']), 'web');
        $editor = app(ArticleEditor::class);
        foreach ([[], ['home_role_text' => 'Role'], ['home_cutout_key' => 'demo-author-6']] as $fields) {
            try {
                $editor->saveTaxonomy('author', null, ['name' => 'Author', 'slug' => 'author', 'home_sort_order' => 1, ...$fields]);
                $this->fail('Incomplete new home profile accepted');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey(array_key_exists('home_role_text', $fields) ? 'home_cutout_key' : 'home_role_text', $exception->errors());
                $this->assertDatabaseCount('content_authors', 0);
                $this->assertDatabaseCount('article_audit_events', 0);
            }
        }
        $author = ContentAuthor::create(['name' => 'Author', 'slug' => 'author', 'home_sort_order' => 1, 'home_role_text' => 'Role', 'home_cutout_key' => 'demo-author-6']);
        $before = $author->fresh()->getAttributes();
        $hash = hash('sha256', json_encode($before));
        foreach ([true, false] as $parametersOnly) {
            foreach (['home_role_text', 'home_cutout_key'] as $field) {
                foreach ([null, '', '   '] as $empty) {
                    try {
                        if ($parametersOnly) {
                            $editor->saveAuthorParameters($author->id, [$field => $empty], $hash);
                        } else {
                            $editor->saveTaxonomy('author', $author->id, ['name' => 'Author', 'slug' => 'author', $field => $empty], $hash);
                        }
                        $this->fail('Incomplete saved home profile accepted');
                    } catch (ValidationException $exception) {
                        $this->assertArrayHasKey($field, $exception->errors());
                        $this->assertSame($before, $author->fresh()->getAttributes());
                        $this->assertDatabaseCount('article_audit_events', 0);
                    }
                }
            }
        }
        $editor->saveAuthorParameters($author->id, ['home_sort_order' => null, 'home_role_text' => null, 'home_cutout_key' => null], $hash);
        $this->assertNull($author->fresh()->home_sort_order);
        $this->assertDatabaseCount('article_audit_events', 1);
        $this->getJson('/api/v1/content-authors?placement=home')->assertOk()->assertJsonCount(0, 'data');
        try {
            $editor->saveAuthorParameters($author->id, ['home_sort_order' => 1], hash('sha256', json_encode($author->fresh()->getAttributes())));
            $this->fail('Incomplete profile re-enabled');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('home_role_text', $exception->errors());
            $this->assertArrayHasKey('home_cutout_key', $exception->errors());
            $this->assertDatabaseCount('article_audit_events', 1);
        }
    }

    public function test_numeric_string_order_keeps_ack_hash_stable_for_next_patch_noop_and_clear(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin_full']), 'web');
        $author = ContentAuthor::create(['name' => 'Author', 'slug' => 'author', 'home_sort_order' => 1, 'home_role_text' => 'Role', 'home_cutout_key' => 'demo-author-6']);
        $editor = app(ArticleEditor::class);
        $revision = hash('sha256', json_encode($author->fresh()->getAttributes()));
        foreach (['2', '3'] as $order) {
            $saved = $editor->saveAuthorParameters($author->id, ['home_sort_order' => $order], $revision);
            $this->assertSame((int) $order, $saved->getAttributes()['home_sort_order']);
            $revision = hash('sha256', json_encode($saved->getAttributes()));
            $this->assertSame($revision, hash('sha256', json_encode($saved->fresh()->getAttributes())));
        }
        $this->assertDatabaseCount('article_audit_events', 2);
        $noop = $editor->saveAuthorParameters($author->id, ['home_sort_order' => '3'], $revision);
        $this->assertSame($revision, hash('sha256', json_encode($noop->getAttributes())));
        $this->assertSame($revision, hash('sha256', json_encode($noop->fresh()->getAttributes())));
        $this->assertDatabaseCount('article_audit_events', 2);
        $cleared = $editor->saveAuthorParameters($author->id, ['home_sort_order' => null], $revision);
        $this->assertNull($cleared->getAttributes()['home_sort_order']);
        $revision = hash('sha256', json_encode($cleared->getAttributes()));
        $this->assertSame($revision, hash('sha256', json_encode($cleared->fresh()->getAttributes())));
        $saved = $editor->saveAuthorParameters($author->id, ['home_role_text' => 'Updated role'], $revision);
        $this->assertSame('Updated role', $saved->home_role_text);
        $this->assertNull($saved->home_sort_order);
        $this->assertDatabaseCount('article_audit_events', 4);
    }

    public function test_avatar_and_home_patch_use_revision_audit_and_media_lifecycle_without_changing_photo(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin_full']), 'web');
        $photo = $this->asset('a');
        $avatar = $this->asset('b');
        $author = ContentAuthor::create(['name' => 'Author', 'slug' => 'author', 'photo_path' => $photo->key, 'bio' => 'Preserved']);
        $hash = hash('sha256', json_encode($author->fresh()->getAttributes()));
        $editor = app(ArticleEditor::class);
        $saved = $editor->saveAuthorParameters($author->id, ['avatar_path' => $avatar->key, 'home_sort_order' => 1, 'home_role_text' => 'Role', 'home_cutout_key' => 'demo-author-6'], $hash);
        $this->assertSame($photo->key, $saved->photo_path);
        $this->assertSame('Preserved', $saved->bio);
        $this->assertNull($saved->user_id);
        $this->assertNull($avatar->fresh()->lease_at);
        $this->assertDatabaseCount('article_audit_events', 1);
        $this->assertArrayHasKey($avatar->sha256, app(ArticleMediaLifecycle::class)->referencedHashes());
        app(ArticleMediaLifecycle::class)->scheduleUnused([$avatar->sha256]);
        $this->assertSame('active', $avatar->fresh()->status);
        try {
            $editor->saveAuthorParameters($author->id, ['home_role_text' => 'Stale'], $hash);
            $this->fail('Stale edit accepted');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('expectedHash', $exception->errors());
        }
        foreach ([['avatar_path' => 'article-media/'.str_repeat('c', 64).'.webp'], ['home_sort_order' => -1], ['home_cutout_key' => 'unknown']] as $patch) {
            try {
                $editor->saveAuthorParameters($author->id, $patch, hash('sha256', json_encode($author->fresh()->getAttributes())));
                $this->fail('Invalid profile patch accepted');
            } catch (ValidationException) {
                $this->assertDatabaseCount('article_audit_events', 1);
            }
        }
        $editor->saveAuthorParameters($author->id, ['avatar_path' => null], hash('sha256', json_encode($author->fresh()->getAttributes())));
        $this->assertSame('deleting', $avatar->fresh()->status);
        $this->assertSame('active', $photo->fresh()->status);
        $this->assertDatabaseCount('article_audit_events', 2);
    }
}
