<?php

namespace Tests\Feature;

use App\Models\ArticleMediaAsset;
use App\Models\ContentAuthor;
use App\Models\ContentEntry;
use App\Models\User;
use App\Services\Articles\ArticleEditor;
use App\Services\Articles\ArticleMedia;
use App\Services\Articles\ArticleMediaGuard;
use App\Services\Articles\ArticleMediaLifecycle;
use App\Services\Articles\ArticleMediaVariants;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ArticleMediaLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('public');
        $this->actingAs(User::factory()->create(['role' => 'admin_full']), 'web');
    }

    public function test_shared_draft_body_and_cover_survive_until_last_detach_then_all_files_delete(): void
    {
        $asset = $this->image();
        app(ArticleMediaVariants::class)->process($asset->id);
        $asset->refresh();
        $this->assertNotEmpty($asset->variants);
        $editor = app(ArticleEditor::class);
        $first = $editor->save(null, $this->input('first') + ['cover_path' => $asset->key]);
        $second = $editor->save(null, array_replace($this->input('second'), ['body' => '<p><img src="/storage/'.$asset->key.'"></p>']));
        $editor->save($first->id, $this->input('first') + ['cover_path' => null], 1);
        $this->assertSame('active', $asset->fresh()->status);
        Storage::disk('public')->assertExists($asset->key);
        $editor->save($second->id, $this->input('second'), 1);
        $this->assertSame('deleting', $asset->fresh()->status);
        $deletion = DB::table('article_media_deletions')->sole();
        app(ArticleMediaLifecycle::class)->delete($deletion->id);
        $this->assertSame('deleted', $asset->fresh()->status);
        Storage::disk('public')->assertMissing($asset->key);
        foreach ($asset->variants as $variant) {
            Storage::disk('public')->assertMissing($variant['key']);
        }
        $this->assertDatabaseHas('article_media_deletions', ['id' => $deletion->id, 'status' => 'completed']);
        $this->assertDatabaseHas('article_media_events', ['asset_id' => $asset->id, 'event' => 'deleted']);
    }

    public function test_author_and_attachments_and_direct_variant_urls_count_as_references_in_any_status(): void
    {
        $asset = $this->image();
        config(['filesystems.disks.article-media-s3.url' => 'https://media.test']);
        config(['app.frontend_url' => 'https://site.example.test']);
        $author = ContentAuthor::create(['name' => 'Author', 'slug' => 'author', 'photo_path' => $asset->key]);
        $this->schedule($asset);
        $this->assertSame('active', $asset->fresh()->status);
        $author->update(['photo_path' => null]);
        $frontEntry = ContentEntry::create(['content_type' => 'article', 'slug' => 'front-alias', 'title' => 'Front alias', 'body' => '<img src="https://site.example.test/storage/'.$asset->key.'?v=1#figure">', 'status' => 'draft']);
        $this->schedule($asset);
        $this->assertSame('active', $asset->fresh()->status);
        $frontEntry->delete();
        $entry = ContentEntry::create(['content_type' => 'article', 'slug' => 'hidden', 'title' => 'Hidden', 'body' => '<img src="https://media.test/article-media/variants/'.$asset->sha256.'/v1/64.webp?x=1#fragment">', 'status' => 'hidden', 'payload' => ['attachments' => [['url' => 'https://media.test/'.$asset->key.'?download=1']]]]);
        $this->schedule($asset);
        $this->assertSame('active', $asset->fresh()->status);
        $entry->update(['body' => 'none', 'payload' => ['legacy' => ['oldUrl' => '/storage/'.$asset->key]]]);
        $this->schedule($asset);
        $this->assertSame('deleting', $asset->fresh()->status);
    }

    public function test_tombstone_rejects_stale_attach_and_import_but_explicit_fresh_upload_revives_generation(): void
    {
        [$asset,$source] = $this->image(withSource: true);
        $this->schedule($asset);
        app(ArticleMediaLifecycle::class)->delete(DB::table('article_media_deletions')->value('id'));
        try {
            app(ArticleEditor::class)->save(null, $this->input('stale') + ['cover_path' => $asset->key]);
            $this->fail('Stale attachment accepted');
        } catch (ValidationException) {
            $this->assertDatabaseCount('content_entries', 0);
        }
        try {
            app(ArticleMedia::class)->ingest($source, ['kind' => 'legacy']);
            $this->fail('Import revived tombstone');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('cannot be revived', $e->getMessage());
        }
        $revived = app(ArticleMedia::class)->ingest($source, ['kind' => 'editor', 'actorId' => auth('web')->id()]);
        $this->assertSame(2, $revived->generation);
        $this->assertFalse(app(ArticleMediaVariants::class)->process($asset->id, 1));
        $this->assertSame('active', $revived->status);
        Storage::disk('public')->assertExists($revived->key);
        app(ArticleMediaLifecycle::class)->delete(DB::table('article_media_deletions')->value('id'));
        Storage::disk('public')->assertExists($revived->key);
    }

    public function test_delete_failure_keeps_retryable_outbox_and_never_claims_success(): void
    {
        $asset = $this->image();
        $this->schedule($asset);
        $id = DB::table('article_media_deletions')->value('id');
        $original = Storage::disk('public');
        $broken = \Mockery::mock(FilesystemAdapter::class);
        $broken->shouldReceive('allFiles')->andReturn([]);
        $broken->shouldReceive('exists')->with($asset->key)->andReturn(true);
        $broken->shouldReceive('delete')->with($asset->key)->andReturn(false);
        Storage::set('public', $broken);
        app(ArticleMediaLifecycle::class)->delete($id);
        $this->assertDatabaseHas('article_media_deletions', ['id' => $id, 'status' => 'pending', 'attempts' => 1]);
        $this->assertSame('deleting', $asset->fresh()->status);
        Storage::set('public', $original);
        DB::table('article_media_deletions')->where('id', $id)->update(['retry_at' => now()->subSecond()]);
        app(ArticleMediaLifecycle::class)->delete($id);
        $this->assertDatabaseHas('article_media_deletions', ['id' => $id, 'status' => 'completed', 'attempts' => 2]);
    }

    public function test_upload_lease_expiry_does_not_delete_drafts_or_authors_and_fresh_lease_extends_reuse(): void
    {
        [$asset,$source] = $this->image(withSource: true);
        $asset->refresh()->update(['lease_at' => now()->subHours(25)]);
        app(ArticleMedia::class)->ingest($source, ['kind' => 'editor', 'actorId' => auth('web')->id()]);
        $this->assertSame(0, app(ArticleMediaLifecycle::class)->cleanupAbandoned());
        $asset->refresh()->update(['lease_at' => now()->subHours(25)]);
        $entry = app(ArticleEditor::class)->save(null, $this->input('draft') + ['cover_path' => $asset->key]);
        $this->assertNull($asset->fresh()->lease_at);
        $this->assertSame(0, app(ArticleMediaLifecycle::class)->cleanupAbandoned());
        $entry->delete();
        $asset->refresh()->update(['lease_at' => now()->subHours(25)]);
        $this->assertSame(1, app(ArticleMediaLifecycle::class)->cleanupAbandoned());
    }

    public function test_backfill_is_idempotent_verified_preserves_alpha_dimensions_and_original_zoom(): void
    {
        $asset = $this->image();
        $this->artisan('articles:media-backfill', ['--limit' => 1])->assertSuccessful();
        $this->assertNull($asset->fresh()->variants);
        $this->artisan('articles:media-backfill', ['--apply' => true, '--limit' => 1])->assertSuccessful();
        $asset->refresh();
        $this->assertSame(700, $asset->width);
        $this->assertSame(400, $asset->height);
        $this->assertCount(12, $asset->variants);
        foreach ($asset->variants as $variant) {
            $this->assertLessThanOrEqual(700, $variant['width']);
            $this->assertEqualsWithDelta(400 * $variant['width'] / 700, $variant['height'], 1);
            $file = Storage::disk('public')->path($variant['key']);
            $this->assertSame('image/'.$variant['format'], (new \finfo(FILEINFO_MIME_TYPE))->file($file));
            $process = new Process(['magick', 'identify', '-format', '%[opaque]', $file]);
            $process->mustRun();
            $this->assertSame('False', trim($process->getOutput()));
        }
        $this->artisan('articles:media-backfill', ['--verify' => true, '--limit' => 1])->assertSuccessful();
        $this->artisan('articles:media-backfill', ['--apply' => true, '--limit' => 1])->assertSuccessful();
        $this->assertSame($asset->variants, $asset->fresh()->variants);
        $body = app(ArticleMedia::class)->publicHtml('<p><img alt="Figure" src="/storage/'.$asset->key.'"></p>');
        $this->assertStringContainsString('<picture>', $body);
        $this->assertStringContainsString('type="image/avif"', $body);
        $this->assertStringContainsString('data-full-src="/storage/'.$asset->key.'"', $body);
        $this->assertSame($asset->sha256, hash('sha256', Storage::disk('public')->get($asset->key)));
    }

    public function test_worker_final_reference_recheck_cancels_deletion_and_external_plaintext_does_not_pin(): void
    {
        $asset = $this->image();
        ContentEntry::create(['content_type' => 'article', 'slug' => 'external', 'title' => 'External', 'body' => '<p>Reference '.$asset->key.'</p><img src="https://unrelated.test/'.$asset->key.'">', 'status' => 'draft']);
        $this->schedule($asset);
        $this->assertSame('deleting', $asset->fresh()->status);
        ContentAuthor::create(['slug' => 'late', 'name' => 'Late author', 'photo_path' => $asset->key]);
        app(ArticleMediaLifecycle::class)->delete(DB::table('article_media_deletions')->value('id'));
        $this->assertSame('active', $asset->fresh()->status);
        $this->assertDatabaseHas('article_media_deletions', ['asset_id' => $asset->id, 'status' => 'cancelled']);
        Storage::disk('public')->assertExists($asset->key);
    }

    public function test_large_original_is_preserved_for_zoom_while_new_variants_stop_at_1920(): void
    {
        $source = tempnam(sys_get_temp_dir(), 'article-large-profile-');
        try {
            (new Process(['magick', '-size', '2784x1884', 'gradient:red-blue', 'JPEG:'.$source]))->mustRun();
            $original = file_get_contents($source);
            $asset = app(ArticleMedia::class)->ingest($source, ['kind' => 'editor']);
            app(ArticleMediaVariants::class)->process($asset->id);
            $asset->refresh();
            $this->assertSame(2784, $asset->width);
            $this->assertSame(1884, $asset->height);
            $widths = array_column($asset->variants, 'width');
            $this->assertSame(1920, max($widths));
            $this->assertNotContains(2784, $widths);
            $this->assertCount(16, $asset->variants);
            Storage::disk('public')->assertMissing('article-media/variants/'.$asset->sha256.'/v1/2784.avif');
            Storage::disk('public')->assertMissing('article-media/variants/'.$asset->sha256.'/v1/2784.webp');
            foreach ($asset->variants as $variant) {
                $this->assertLessThanOrEqual(1920, $variant['width']);
                $this->assertEqualsWithDelta(1884 * $variant['width'] / 2784, $variant['height'], 1);
            }
            $this->assertSame($original, Storage::disk('public')->get($asset->key));
            $this->assertSame($asset->sha256, hash('sha256', $original));
            $html = app(ArticleMedia::class)->publicHtml('<img src="/storage/'.$asset->key.'">');
            $this->assertStringContainsString('data-full-src="/storage/'.$asset->key.'"', $html);
        } finally {
            unlink($source);
        }
    }

    public function test_eighty_megapixel_jpeg_decodes_with_bounded_profile_and_preserves_oriented_source_dimensions(): void
    {
        $source = tempnam(sys_get_temp_dir(), 'article-large-jpeg-');
        try {
            (new Process(['magick', '-limit', 'memory', '128MiB', '-limit', 'map', '256MiB', '-limit', 'disk', '2GiB', '-limit', 'thread', '1', '-size', '9340x8564', 'xc:white', '-depth', '8', 'JPEG:'.$source]))->mustRun();
            // EXIF orientation 6 rotates the original clockwise into a portrait.
            $exif = "Exif\0\0II".pack('vVv', 42, 8, 1).pack('vvVv', 0x0112, 3, 1, 6)."\0\0".pack('V', 0);
            $jpeg = file_get_contents($source);
            file_put_contents($source, substr($jpeg, 0, 2)."\xff\xe1".pack('n', strlen($exif) + 2).$exif.substr($jpeg, 2));
            $original = file_get_contents($source);
            $asset = app(ArticleMedia::class)->ingest($source, ['kind' => 'editor']);
            app(ArticleMediaVariants::class)->process($asset->id);
            $asset->refresh();
            $this->assertSame('ready', $asset->processing_status);
            $this->assertSame(8564, $asset->width);
            $this->assertSame(9340, $asset->height);
            $this->assertCount(16, $asset->variants);
            $this->assertSame(1920, max(array_column($asset->variants, 'width')));
            foreach ($asset->variants as $variant) {
                $this->assertEqualsWithDelta(9340 * $variant['width'] / 8564, $variant['height'], 1);
                $this->assertSame('image/'.$variant['format'], (new \finfo(FILEINFO_MIME_TYPE))->file(Storage::disk('public')->path($variant['key'])));
            }
            $this->assertSame($original, Storage::disk('public')->get($asset->key));
            $this->assertSame(hash('sha256', $original), $asset->sha256);
        } finally {
            unlink($source);
        }
    }

    public function test_absolute_frontend_and_backend_aliases_render_valid_pending_image_and_attachment_urls(): void
    {
        $asset = $this->image();
        config(['app.frontend_url' => 'https://site.example.test', 'app.url' => 'https://api.example.test']);
        $media = app(ArticleMedia::class);
        foreach (['https://site.example.test/storage/', 'https://api.example.test/storage/'] as $origin) {
            $html = $media->publicHtml('<p><img src="'.$origin.$asset->key.'?v=1#image"><a href="'.$origin.$asset->key.'">File</a></p>');
            $this->assertStringContainsString('src="/storage/'.$asset->key.'"', $html);
            $this->assertStringContainsString('href="/storage/'.$asset->key.'"', $html);
            $this->assertStringNotContainsString('example.testhttps://', $html);
        }
        $unrelated = 'https://unrelated.test/storage/'.$asset->key;
        $this->assertStringContainsString('src="'.$unrelated.'"', $media->publicHtml('<img src="'.$unrelated.'">'));
        $source = tempnam(sys_get_temp_dir(), 'article-gif-alias-');
        try {
            file_put_contents($source, base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'));
            $gif = $media->ingest($source, ['kind' => 'editor']);
            app(ArticleMediaVariants::class)->process($gif->id);
            $html = $media->publicHtml('<img src="https://site.example.test/storage/'.$gif->key.'">');
            $this->assertStringContainsString('src="/storage/'.$gif->key.'"', $html);
            $this->assertStringContainsString('data-full-src="/storage/'.$gif->key.'"', $html);
            $this->assertStringNotContainsString('<picture>', $html);
        } finally {
            unlink($source);
        }
    }

    public function test_encoded_own_draft_reference_pins_original_and_deleted_encoded_attach_is_rejected(): void
    {
        $asset = $this->image();
        config(['filesystems.disks.article-media-s3.url' => 'https://media.test', 'app.frontend_url' => 'https://site.test']);
        $encodedName = '%'.bin2hex($asset->filename[0]).substr($asset->filename, 1);
        $url = 'https://media.test/article-media/'.$encodedName.'?v=1#figure';
        $editor = app(ArticleEditor::class);
        $cover = $editor->save(null, $this->input('encoded-cover') + ['cover_path' => $asset->key]);
        $draft = $editor->save(null, array_replace($this->input('encoded-draft'), ['body' => '<p><img src="'.$url.'"></p>']));
        $editor->save($cover->id, $this->input('encoded-cover') + ['cover_path' => null], 1);
        $this->assertSame('active', $asset->fresh()->status);
        $this->assertSame([$asset->sha256], app(ArticleMediaLifecycle::class)->entryHashes($draft));
        $this->assertSame([$asset->sha256], app(ArticleMediaLifecycle::class)->hashes(['https://site.test/storage/article-media/'.$encodedName]));
        $body = app(ArticleMedia::class)->publicHtml($draft->body);
        $this->assertStringContainsString('src="/storage/'.$asset->key.'"', $body);
        $variant = 'https://media.test/article-media/variants/%'.bin2hex($asset->sha256[0]).substr($asset->sha256, 1).'/v1/64.webp';
        $this->assertSame([$asset->sha256], app(ArticleMediaLifecycle::class)->hashes(['<picture><source srcset="'.$variant.' 64w"></picture>']));
        $this->assertSame([], app(ArticleMediaLifecycle::class)->hashes([str_replace('media.test', 'unrelated.test', $url)]));
        $external = str_replace('media.test', 'unrelated.test', $url);
        $this->assertStringContainsString('src="'.$external.'"', app(ArticleMedia::class)->publicHtml('<img src="'.$external.'">'));
        $editor->save($draft->id, $this->input('encoded-draft'), 1);
        $this->assertSame('deleting', $asset->fresh()->status);
        app(ArticleMediaLifecycle::class)->delete(DB::table('article_media_deletions')->value('id'));
        try {
            $editor->save(null, array_replace($this->input('encoded-stale'), ['body' => '<img src="'.$url.'">']));
            $this->fail('Encoded deleted attachment accepted');
        } catch (ValidationException) {
            $this->assertSame(2, ContentEntry::count());
        }
        try {
            $editor->save(null, array_replace($this->input('encoded-stale-variant'), ['body' => '<img src="'.$variant.'">']));
            $this->fail('Encoded deleted variant accepted');
        } catch (ValidationException) {
            $this->assertSame(2, ContentEntry::count());
        }
        foreach (['%25'.substr($encodedName, 1), '%GG'.$asset->filename] as $invalid) {
            try {
                $editor->save(null, array_replace($this->input('invalid-media'), ['body' => '<img src="https://media.test/article-media/'.$invalid.'">']));
                $this->fail('Invalid owned path accepted');
            } catch (ValidationException) {
                $this->assertSame(2, ContentEntry::count());
            }
        }
    }

    public function test_browser_canonical_owned_aliases_pin_draft_before_prefix_matching(): void
    {
        $asset = $this->image();
        config(['filesystems.disks.article-media-s3.url' => 'https://media.test', 'app.frontend_url' => 'https://site.test']);
        $urls = [
            'https://media.test/other/../'.$asset->key,
            'https://media.test/other/%2e%2e/'.$asset->key,
            'https://site.test/storage/other/../'.$asset->key,
            'https://site.test/storage/other/%2E%2E/'.$asset->key,
            'https://media.test:443/'.$asset->key,
            'https://media.test/other\\..\\article-media\\'.$asset->filename,
        ];
        $editor = app(ArticleEditor::class);
        $cover = $editor->save(null, $this->input('canonical-cover') + ['cover_path' => $asset->key]);
        $body = implode('', array_map(fn ($url) => '<img src="'.$url.'">', $urls));
        $draft = $editor->save(null, array_replace($this->input('canonical-draft'), ['body' => $body]));
        foreach ($urls as $url) {
            $this->assertSame([$asset->sha256], app(ArticleMediaLifecycle::class)->hashes([$url]));
        }
        $editor->save($cover->id, $this->input('canonical-cover') + ['cover_path' => null], 1);
        $this->assertSame('active', $asset->fresh()->status);
        $this->assertSame([$asset->sha256], app(ArticleMediaLifecycle::class)->entryHashes($draft));
        $this->assertSame([], app(ArticleMediaLifecycle::class)->hashes(['https://unrelated.test:443/other/../'.$asset->key]));
        $rendered = app(ArticleMedia::class)->publicHtml($draft->body);
        $this->assertStringNotContainsString('/other/', $rendered);
        $editor->save($draft->id, $this->input('canonical-draft'), 1);
        $this->assertSame('deleting', $asset->fresh()->status);
    }

    public function test_gif_pdf_and_animated_webp_are_unmodified_passthrough(): void
    {
        $source = tempnam(sys_get_temp_dir(), 'article-passthrough-');
        try {
            foreach ([base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'), "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n%%EOF"] as $bytes) {
                file_put_contents($source, $bytes);
                $asset = app(ArticleMedia::class)->ingest($source, ['kind' => 'editor']);
                app(ArticleMediaVariants::class)->process($asset->id);
                $this->assertSame([], $asset->fresh()->variants);
                $this->assertSame($bytes, Storage::disk('public')->get($asset->key));
            }
            $process = new Process(['magick', '-size', '4x4', 'xc:red', 'xc:blue', '-delay', '10', '-loop', '0', 'WEBP:'.$source]);
            $process->mustRun();
            $bytes = file_get_contents($source);
            $this->assertStringContainsString('ANIM', $bytes);
            $asset = app(ArticleMedia::class)->ingest($source, ['kind' => 'editor']);
            app(ArticleMediaVariants::class)->process($asset->id);
            $this->assertSame([], $asset->fresh()->variants);
            $this->assertSame($bytes, Storage::disk('public')->get($asset->key));
        } finally {
            unlink($source);
        }
    }

    private function input(string $slug): array
    {
        return ['title' => $slug, 'slug' => $slug, 'body' => '<p>Text</p>', 'status' => 'draft'];
    }

    private function schedule(ArticleMediaAsset $asset): void
    {
        app(ArticleMediaGuard::class)->run(fn () => app(ArticleMediaLifecycle::class)->scheduleUnused([$asset->sha256], auth('web')->id()));
    }

    private function image(bool $withSource = false): ArticleMediaAsset|array
    {
        $source = tempnam(sys_get_temp_dir(), 'article-image-');
        $process = new Process(['magick', '-size', '700x400', 'xc:none', '-fill', 'red', '-draw', 'rectangle 10,10 100,100', 'PNG:'.$source]);
        $process->mustRun();
        $this->beforeApplicationDestroyed(fn () => is_file($source) ? unlink($source) : null);
        $asset = app(ArticleMedia::class)->ingest($source, ['kind' => 'editor', 'actorId' => auth('web')->id()]);

        return $withSource ? [$asset, $source] : $asset;
    }
}
