<?php

namespace Tests\Feature;

use App\Filament\Pages\Articles;
use App\Models\ArticleMediaAsset;
use App\Models\ContentCategory;
use App\Models\ContentEntry;
use App\Models\ContentPlacement;
use App\Models\LegacyImportMapping;
use App\Models\Subject;
use App\Models\User;
use App\Services\Articles\ArticleEditor;
use App\Services\Articles\ArticleHtml;
use App\Services\Articles\ArticleMedia;
use App\Services\Articles\LegacyArticleImport;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ArticleMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_html_preserves_scientific_content_and_removes_active_content(): void
    {
        $html = app(ArticleHtml::class)->sanitize('<h1 onclick="bad()">Title</h1><script>alert(1)</script><p style="color:red">H<sub>2</sub>O X<sup>2</sup></p><table><tr><th scope="col">A</th><td colspan="2">Cell</td></tr></table><img src="/storage/article-media/a.jpg"><a href="javascript:alert(1)">link</a><iframe src="https://evil.test/embed/x"></iframe><iframe src="https://kinescope.io/embed/kzoz8bqCok5sHdmtQNkFPJ"></iframe>');
        foreach (['<h2>Title</h2>', '<sub>2</sub>', '<sup>2</sup>', '<table>', 'colspan="2"', 'https://kinescope.io/embed/kzoz8bqCok5sHdmtQNkFPJ'] as $fragment) {
            $this->assertStringContainsString($fragment, $html);
        }
        foreach (['<script', 'onclick', 'style=', 'javascript:', 'evil.test', '<h1'] as $fragment) {
            $this->assertStringNotContainsString($fragment, $html);
        }
        $long = '<p>'.str_repeat('научный текст ', 4000).'Конец статьи</p>';
        $this->assertStringContainsString('Конец статьи', app(ArticleHtml::class)->sanitize($long));
    }

    public function test_public_pagination_categories_related_and_redirect_use_same_visibility(): void
    {
        $subject = Subject::create(['slug' => 'science', 'title' => 'Science', 'is_active' => true]);
        $category = ContentCategory::create(['content_type' => 'article', 'slug' => 'science', 'title' => 'Science', 'is_active' => true]);
        foreach (['old', 'new', 'future', 'draft', 'disabled'] as $slug) {
            $entry = ContentEntry::create(['content_type' => 'article', 'slug' => $slug, 'title' => $slug, 'body' => 'Text', 'status' => $slug === 'draft' ? 'draft' : 'published', 'published_at' => $slug === 'future' ? now()->addDay() : ($slug === 'old' ? now()->subDays(2) : now()->subDay()), 'content_category_id' => $category->id]);
            $entry->update(['subject_id' => $subject->id]);
            ContentPlacement::create(['content_entry_id' => $entry->id, 'placement' => 'articles.index', 'is_enabled' => $slug !== 'disabled']);
        }
        $this->getJson('/api/v1/articles?page=1&limit=1')->assertOk()->assertJsonPath('data.0.slug', 'new')->assertJsonPath('meta.total', 2)->assertJsonPath('meta.lastPage', 2);
        $this->getJson('/api/v1/articles?page=2&limit=1')->assertJsonPath('data.0.slug', 'old');
        $this->getJson('/api/v1/articles/categories')->assertJsonPath('data.0.count', 2);
        $this->getJson('/api/v1/articles/new')->assertJsonPath('data.related.0.slug', 'old')->assertJsonCount(1, 'data.related');
        foreach (['future', 'draft', 'disabled'] as $slug) {
            $this->getJson('/api/v1/articles/'.$slug)->assertNotFound();
        }
        $this->getJson('/api/v1/articles/redirect?path=/unknown')->assertNotFound();
        $category->update(['is_active' => false]);
        $this->getJson('/api/v1/articles/new')->assertOk();
    }

    public function test_collection_indices_never_enable_detail_fields_in_summaries_or_related(): void
    {
        $subject = Subject::create(['slug' => 'science', 'title' => 'Science', 'is_active' => true]);
        $category = ContentCategory::create(['content_type' => 'article', 'slug' => 'science', 'title' => 'Science', 'is_active' => true]);
        foreach (['first', 'second', 'third'] as $slug) {
            $entry = ContentEntry::create(['content_type' => 'article', 'slug' => $slug, 'title' => $slug, 'body' => 'Private detail payload', 'status' => 'published', 'published_at' => now()->subDay(), 'content_category_id' => $category->id]);
            ContentPlacement::create(['content_entry_id' => $entry->id, 'placement' => 'articles.index', 'is_enabled' => true]);
        }
        ContentEntry::query()->article()->update(['subject_id' => $subject->id]);
        $summary = ['id', 'subject' => ['id', 'slug', 'title'], 'slug', 'title', 'description', 'excerpt', 'updatedAt', 'author', 'cover', 'tags', 'category' => ['slug', 'title'], 'sortOrder', 'publishedAt'];
        $this->getJson('/api/v1/articles?limit=24')->assertOk()->assertJsonCount(3, 'data')
            ->assertExactJsonStructure(['data' => ['*' => $summary], 'meta' => ['placement', 'limit', 'total', 'page', 'perPage', 'lastPage']]);
        $this->getJson('/api/v1/articles/first')->assertOk()->assertJsonCount(2, 'data.related')
            ->assertExactJsonStructure(['data' => [...$summary, 'body', 'bodyFormat', 'attachments', 'related' => ['*' => $summary], 'relatedFaqs', 'seoTitle', 'seoDescription']]);
    }

    public function test_editor_rechecks_full_admin_role_revision_and_stable_slug(): void
    {
        $actor = User::factory()->create(['role' => 'admin_full']);
        $this->actingAs($actor, 'web');
        $editor = app(ArticleEditor::class);
        $input = ['title' => 'New article', 'slug' => 'new-article', 'body' => '<h1>Body</h1>', 'status' => 'draft', 'tags' => ['science']];
        $entry = $editor->save(null, $input);
        $this->assertSame('html', $entry->body_format);
        $this->assertSame('<h2>Body</h2>', $entry->body);
        $this->assertSame('Body', $entry->excerpt);
        $this->assertSame([], $entry->tags);
        $this->assertDatabaseCount('article_audit_events', 1);
        try {
            $editor->save($entry->id, $input, 0);
            $this->fail('Stale write accepted');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('изменена', $e->getMessage());
        }
        try {
            $editor->save($entry->id, array_replace($input, ['slug' => 'changed']), 1);
            $this->fail('Slug change accepted');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('сохраняется', $e->getMessage());
        }
        $actor->update(['role' => 'manager']);
        try {
            $editor->save($entry->id, $input, 1);
            $this->fail('Revoked role accepted');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_filament_editor_creates_article_and_preserves_imported_html_on_reload(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin_full']), 'web');
        Livewire::test(Articles::class, ['articleId' => 'create'])
            ->set('data.title', 'Scientific article')->set('data.slug', 'scientific-article')
            ->set('data.body', '<table><tr><td>H<sub>2</sub>O</td></tr></table>')->call('save')->assertHasNoErrors();
        $entry = ContentEntry::query()->sole();
        // Provenance, rather than HTML body format, identifies an imported article.
        $entry->update(['payload' => ['legacy' => ['id' => 123]]]);
        Livewire::test(Articles::class, ['articleId' => (string) $entry->id])
            ->assertSet('data.sourceMode', true)->assertSet('data.body', $entry->body)
            ->set('data.title', 'Revised scientific article')->call('save')->assertHasNoErrors();
        $this->assertSame($entry->body, $entry->fresh()->body);
        $this->assertSame('Revised scientific article', $entry->fresh()->title);
    }

    public function test_editor_mode_toggle_converts_live_json_to_html_without_losing_text(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin_full']), 'web');
        $document = ['type' => 'doc', 'content' => [
            ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [['type' => 'text', 'text' => 'Heading']]],
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Scientific text', 'marks' => [['type' => 'bold']]]]],
        ]];
        Livewire::test(Articles::class, ['articleId' => 'create'])
            ->set('data.title', 'Mode test')->set('data.slug', 'mode-test')
            ->set('data.body', $document)->set('data.sourceMode', true)
            ->assertSet('data.body', '<h2>Heading</h2><p><strong>Scientific text</strong></p>')
            ->call('save')->assertHasNoErrors();
        $entry = ContentEntry::query()->sole();
        $this->assertSame('Heading Scientific text', $entry->excerpt);
        $this->assertStringNotContainsString('[object Object]', $entry->body);
        $this->assertSame('', app(ArticleHtml::class)->fromEditorState(['type' => 'doc', 'content' => [['type' => 'paragraph']]]));
        $this->assertSame('Heading Scientific text Label Value H2O', app(ArticleHtml::class)->plainText('<h2>Heading</h2><p>Scientific text</p><table><tr><td>Label</td><td>Value</td></tr></table><p>H<sub>2</sub>O</p>'));
    }

    public function test_import_dry_run_apply_repeat_and_editor_conflict(): void
    {
        Storage::fake('public');
        [$source,$root,$manifest] = $this->fixture();
        $import = app(LegacyArticleImport::class);
        $this->assertSame(1, $import->run($source, $root, $manifest, false)['articles']);
        $this->assertDatabaseCount('content_entries', 0);
        $import->run($source, $root, $manifest, true);
        $entry = ContentEntry::query()->sole();
        $this->assertSame('2024-01-29 11:22:02', $entry->published_at->format('Y-m-d H:i:s'));
        $this->assertSame('test', $entry->slug);
        $this->assertStringContainsString('/storage/article-media/', $entry->body);
        $this->assertStringContainsString('https://christmedschool.com/farma.html?from=article#course', $entry->body);
        $this->assertSame(0, $import->run($source, $root, $manifest, true)['created']);
        $asset = ArticleMediaAsset::query()->sole();
        $this->get('/storage/article-media/'.$asset->filename)->assertOk()->assertHeader('Content-Type', 'image/gif');
        Storage::fake('article-media-s3');
        config(['articles.media_disk' => 'article-media-s3']);
        $this->assertSame($asset->id, app(ArticleMedia::class)->ingest($root.'/upload/test.jpg', ['kind' => 'test'])->id);
        $this->assertSame('public', $asset->fresh()->disk);
        $entry->update(['title' => 'Edited']);
        $changed = file_get_contents($root.'/upload/test.jpg').'new-media-bytes';
        file_put_contents($root.'/upload/test.jpg', $changed);
        $manifestData = json_decode(file_get_contents($manifest), true);
        $manifestData['files']['/upload/test.jpg']['sha256'] = hash('sha256', $changed);
        $manifestData['files']['/upload/test.jpg']['bytes'] = strlen($changed);
        file_put_contents($manifest, json_encode($manifestData));
        try {
            $import->run($source, $root, $manifest, true);
            $this->fail('Editor conflict accepted');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Import/editor conflict', $e->getMessage());
        }
        $this->assertDatabaseCount('article_media_assets', 1);
        $this->assertCount(1, Storage::disk('public')->allFiles('article-media'));
        $this->assertCount(0, Storage::disk('article-media-s3')->allFiles('article-media'));
    }

    public function test_import_fails_missing_referenced_asset_before_writes(): void
    {
        Storage::fake('public');
        [$source,$root,$manifest] = $this->fixture();
        unlink($root.'/upload/test.jpg');
        try {
            app(LegacyArticleImport::class)->run($source, $root, $manifest, true);
            $this->fail('Missing media accepted');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Media verification failed', $e->getMessage());
        }
        $this->assertDatabaseCount('content_entries', 0);
    }

    public function test_available_full_migration_package_matches_source_inventory(): void
    {
        $package = base_path('../demo-imports/artifacts/articles-migration-20261021');
        if (! is_file($package.'/legacy-articles.json') || ! is_file($package.'/media-manifest.json')) {
            $this->markTestSkipped('Local read-only migration package is not installed.');
        }
        Storage::fake('public');
        $import = app(LegacyArticleImport::class);
        $result = $import->run($package.'/legacy-articles.json', $package.'/media', $package.'/media-manifest.json', true);
        $this->assertSame(225, $result['articles']);
        $this->assertSame(16, $result['categories']);
        $this->assertSame(11, $result['authors']);
        $this->assertSame(403, $result['files']);
        $this->assertSame(3, $result['tags']);
        $this->assertDatabaseCount('content_entries', 225);
        $this->assertDatabaseCount('content_authors', 11);
        $this->assertDatabaseCount('content_categories', 16);
        $this->assertSame(0, $import->run($package.'/legacy-articles.json', $package.'/media', $package.'/media-manifest.json', true)['created']);
        $raw = json_decode(file_get_contents($package.'/legacy-articles.json'), true);
        $normalize = function ($html) {
            $dom = new \DOMDocument;
            $previous = libxml_use_internal_errors(true);
            $dom->loadHTML('<?xml encoding="utf-8"?><div>'.$html.'</div>');
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
            foreach (iterator_to_array($dom->getElementsByTagName('figcaption')) as $caption) {
                $image = $caption->parentNode->getElementsByTagName('img')->item(0);
                if ($image && $image->getAttribute('title') !== '' && trim($caption->textContent) === trim($image->getAttribute('title'))) {
                    $caption->parentNode->removeChild($caption);
                }
            }

            $compact = fn ($text) => preg_replace('/[\s\x{00a0}]+/u', '', html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $root = $dom->getElementsByTagName('div')->item(0);
            foreach (iterator_to_array($root->childNodes) as $paragraph) {
                if (! $paragraph instanceof \DOMElement || $paragraph->tagName !== 'p') {
                    continue;
                }
                $lines = preg_split('~<br\b[^>]*>~i', $dom->saveHTML($paragraph));
                $kept = [];
                $excluded = false;
                foreach ($lines as $line) {
                    $text = html_entity_decode(strip_tags($line), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    $linkedRecommendation = false;
                    if (preg_match('/\A[\s\x{00a0}]*(?:И[\s\x{00a0}]+)?Читайте[\s\x{00a0}]+также/iu', $text)) {
                        $fragment = new \DOMDocument;
                        $fragment->loadHTML('<?xml encoding="utf-8"?><div>'.$line.'</div>');
                        foreach ($fragment->getElementsByTagName('a') as $link) {
                            $linkedRecommendation = $linkedRecommendation || trim($link->getAttribute('href')) !== '';
                        }
                    }
                    if ($linkedRecommendation) {
                        $excluded = true;
                    } else {
                        $kept[] = $text;
                    }
                }
                if ($excluded) {
                    $replacement = $dom->createElement('p');
                    $replacement->appendChild($dom->createTextNode(implode("\n", $kept)));
                    $root->replaceChild($replacement, $paragraph);
                }
            }

            return $compact(strip_tags($dom->saveHTML()));
        };
        $inline = '<p>В тексте читайте также <a href="/articles/context">объяснение</a> и важный учебный признак.</p>';
        $this->assertNotSame($normalize($inline), $normalize('<p>В тексте </p>'));
        $book = '<p>Откройте <a href="/subjects/biohimiya">курс</a>.<br>Читайте также главу учебника.</p>';
        $this->assertNotSame($normalize($book), $normalize('<p>Откройте курс.</p>'));
        $this->assertSame($normalize('<p>Откройте курс.</p>'), $normalize('<p>Откройте курс.<br>И читайте также <a href="/articles/other">другую статью</a>.</p>'));
        foreach ($raw['news'] as $row) {
            $articleMapping = LegacyImportMapping::query()->where('target_domain', 'articles')->where('source_table', 'news')->where('source_id', (string) $row['id'])->sole();
            $article = ContentEntry::findOrFail($articleMapping->target_id);
            $this->assertSame($normalize($row['content']), $normalize($article->body), 'Body text fidelity for source '.$row['id']);
            $this->assertSame([], $article->tags);
        }
        $this->assertDatabaseMissing('article_media_assets', ['filename' => hash_file('sha256', $package.'/media/upload/news/a4b1d0883c.pdf').'.pdf']);
        $mapping = LegacyImportMapping::query()->where('target_domain', 'articles')->where('source_table', 'news')->where('source_id', '41')->sole();
        $this->assertStringContainsString('https://kinescope.io/embed/kzoz8bqCok5sHdmtQNkFPJ', ContentEntry::findOrFail($mapping->target_id)->body);
    }

    public function test_registry_serves_s3_redirect_using_recorded_disk_and_rejects_unknown_alias(): void
    {
        $asset = ArticleMediaAsset::create(['filename' => str_repeat('a', 64).'.jpg', 'disk' => 'article-media-s3', 'key' => 'article-media/'.str_repeat('a', 64).'.jpg', 'sha256' => str_repeat('a', 64), 'bytes' => 12, 'mime' => 'image/jpeg', 'origin' => ['kind' => 'test'], 'created_at' => now()]);
        config(['filesystems.disks.article-media-s3.driver' => 's3', 'filesystems.disks.article-media-s3.url' => 'https://media.example.test']);
        $adapter = \Mockery::mock(FilesystemAdapter::class);
        $adapter->shouldReceive('url')->once()->with($asset->key)->andReturn('https://media.example.test/'.$asset->key);
        Storage::shouldReceive('disk')->once()->with('article-media-s3')->andReturn($adapter);
        $this->get('/storage/article-media/'.$asset->filename)->assertStatus(302)->assertRedirect('https://media.example.test/'.$asset->key);
        $this->get('/storage/article-media/'.str_repeat('b', 64).'.jpg')->assertNotFound();
    }

    public function test_api_resolves_s3_registry_urls_but_keeps_stable_database_aliases(): void
    {
        $filename = str_repeat('c', 64).'.jpg';
        $path = 'article-media/'.$filename;
        ArticleMediaAsset::create(['filename' => $filename, 'disk' => 'article-media-s3', 'key' => $path, 'sha256' => str_repeat('c', 64), 'bytes' => 12, 'mime' => 'image/jpeg', 'origin' => ['kind' => 'test'], 'created_at' => now()]);
        $entry = ContentEntry::create(['content_type' => 'article', 'slug' => 's3-article', 'title' => 'S3 article', 'body' => '<p><img src="/storage/'.$path.'"></p>', 'body_format' => 'html', 'status' => 'published', 'published_at' => now()->subDay(), 'cover_path' => $path]);
        ContentPlacement::create(['content_entry_id' => $entry->id, 'placement' => 'articles.index', 'is_enabled' => true]);
        config(['filesystems.disks.article-media-s3.driver' => 's3']);
        $adapter = \Mockery::mock(FilesystemAdapter::class);
        $adapter->shouldReceive('url')->once()->with($path)->andReturn('https://media.example.test/'.$path);
        Storage::shouldReceive('disk')->once()->with('article-media-s3')->andReturn($adapter);
        $this->getJson('/api/v1/articles/s3-article')->assertOk()->assertJsonPath('data.cover.url', 'https://media.example.test/'.$path)->assertJsonPath('data.body', '<figure><img src="https://media.example.test/'.$path.'" alt="" loading="lazy" data-full-src="https://media.example.test/'.$path.'"></figure>');
        $this->assertStringContainsString('/storage/article-media/', $entry->fresh()->body);
    }

    private function fixture(): array
    {
        $root = sys_get_temp_dir().'/article-import-'.bin2hex(random_bytes(8));
        mkdir($root.'/upload', 0777, true);
        $image = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
        file_put_contents($root.'/upload/test.jpg', $image);
        $manifest = $root.'/manifest.json';
        file_put_contents($manifest, json_encode(['files' => ['/upload/test.jpg' => ['file' => 'upload/test.jpg', 'sha256' => hash('sha256', $image), 'bytes' => strlen($image)]]]));
        $source = $root.'/source.json';
        file_put_contents($source, json_encode(['news' => [['id' => 1, 'url' => 'science/test.html', 'url_old' => null, 'h1' => 'Test', 'menuname' => 'Test', 'content' => '<p>Text<img src="/upload/test.jpg"><a href="/farma.html?from=article#course">Course</a></p>', 'image' => 'test.jpg', 'dateCreated' => '2024-01-29 14:22:02', 'dateEdited' => null, 'isPublished' => 1, 'isDeleted' => null, 'title' => 'Title', 'description' => 'Description', 'category1' => '', 'category2' => null, 'category3' => '', 'postedById' => null, 'tags' => 'science', 'viewsCounter' => 5, 'likesCounter' => 2, 'keywords' => null]], 'pages' => [], 'files' => [], 'tags' => []]));
        $this->beforeApplicationDestroyed(function () use ($root) {
            foreach ([$root.'/upload/test.jpg', $root.'/manifest.json', $root.'/source.json'] as $p) {
                if (file_exists($p)) {
                    unlink($p);
                }
            } rmdir($root.'/upload');
            rmdir($root);
        });

        return [$source, $root, $manifest];
    }
}
