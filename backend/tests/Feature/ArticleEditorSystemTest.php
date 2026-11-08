<?php

namespace Tests\Feature;

use App\Filament\Pages\Articles;
use App\Filament\RichContent\ArticleRichContentPlugin;
use App\Models\ArticleMediaAsset;
use App\Models\ContentEntry;
use App\Models\User;
use App\Services\Articles\ArticleHtml;
use App\Services\Articles\ArticlePreview;
use Filament\Facades\Filament;
use Filament\Forms\Components\RichEditor\RichContentRenderer;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\Livewire;
use Tests\TestCase;

class ArticleEditorSystemTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('testing', app()->environment());
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        Queue::fake();
        Storage::fake('public');
        Storage::fake('local');
        Storage::fake('tmp-for-tests');
        config(['livewire.temporary_file_upload.disk' => 'local']);
        Artisan::call('migrate', ['--force' => true]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create(['role' => 'admin_full']), 'web');
    }

    private function state(string $html): array
    {
        return RichContentRenderer::make($html)->plugins([new ArticleRichContentPlugin])->toArray();
    }

    private function asset(): string
    {
        $filename = str_repeat('a', 64).'.jpg';
        ArticleMediaAsset::create(['filename' => $filename, 'sha256' => str_repeat('a', 64), 'disk' => 'public',
            'key' => 'article-media/'.$filename, 'mime' => 'image/jpeg', 'bytes' => 1,
            'origin' => ['kind' => 'test'], 'created_at' => now()]);

        return '/storage/article-media/'.$filename;
    }

    public function test_video_insert_edit_delete_and_unsafe_iframe_filtering(): void
    {
        $html = app(ArticleHtml::class);
        $state = ['type' => 'doc', 'content' => [['type' => 'articleVideo', 'attrs' => ['src' => 'https://kinescope.io/embed/abc123', 'title' => 'Lesson']]]];
        $this->assertStringContainsString('https://kinescope.io/embed/abc123', $html->fromEditorState($state));
        $state['content'][0]['attrs']['src'] = 'https://kinescope.io/embed/edited456';
        $this->assertStringContainsString('edited456', $html->fromEditorState($state));
        $state['content'] = [];
        $this->assertSame('', $html->fromEditorState($state));
        foreach (['https://evil.example/embed/abc', 'javascript:alert(1)', 'https://kinescope.io/embed/abc?bad=1', 'https://kinescope.io/embed/abc/extra'] as $src) {
            $this->assertStringNotContainsString('<iframe', $html->sanitize('<iframe src="'.$src.'"></iframe><script>alert(1)</script>'));
        }
    }

    public function test_editor_tool_handlers_survive_html_attribute_serialization(): void
    {
        $page = Livewire::test(Articles::class, ['articleId' => 'create']);
        $dom = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>'.$page->html());
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $handlers = [];
        $previewDisabled = null;
        foreach ($dom->getElementsByTagName('button') as $button) {
            $handlers[$button->getAttribute('aria-label')] = $button->getAttribute('x-on:click');
            if ($button->getAttribute('data-ui') === 'article-preview.open') {
                $previewDisabled = $button->getAttribute('x-bind:disabled');
            }
        }
        // Alpine boolean attributes treat a numeric zero as a present attribute.
        // Keep the upload count comparison boolean in the actual rendered markup.
        $this->assertSame('previewBusy || fullBusy || uploads > 0', $previewDisabled);
        $this->assertStringContainsString("getAttributes('articleVideo')", $handlers['Видео Kinescope']);
        $this->assertStringContainsString("isActive('articleVideo')", $handlers['Видео Kinescope']);
        $this->assertStringContainsString("caption?.type.name === 'articleFigcaption'", $handlers['Подпись изображения']);
        $this->assertStringNotContainsString('storage.articleFigure', $handlers['Подпись изображения']);
        $this->assertStringEndsWith(')', $handlers['Видео Kinescope']);
        $this->assertStringEndsWith(')', $handlers['Подпись изображения']);
    }

    public function test_figure_marks_alt_neighboring_text_linked_images_and_table_spans_survive_form_save(): void
    {
        $src = $this->asset();
        $body = '<p>Before <a href="https://example.com"><img src="'.$src.'" alt="Linked"></a> after</p>'
            .'<figure><img src="'.$src.'" alt="Scheme"><figcaption>A <strong>marked</strong> <em>caption</em></figcaption></figure>'
            .'<table><tbody><tr><th colspan="2">Heading</th></tr><tr><td rowspan="2"><p>Cell <img src="'.$src.'" alt="Cell"> end</p></td><td>Two</td></tr><tr><td>Three</td></tr></tbody></table>';
        $page = Livewire::test(Articles::class, ['articleId' => 'create'])
            ->set('data.title', 'Figures')->set('data.body', $this->state($body))->call('save')->assertHasNoErrors()
            ->assertSet('data.sourceMode', false);
        $saved = ContentEntry::query()->sole()->body;
        foreach (['Before', 'after', 'href="https://example.com"', 'alt="Linked"', '<figure>', '<figcaption>A <strong>marked</strong> <em>caption</em></figcaption>', 'alt="Scheme"', 'alt="Cell"', 'colspan="2"', 'rowspan="2"', '<th'] as $part) {
            $this->assertStringContainsString($part, $saved);
        }
        $page->call('save')->assertHasNoErrors()->assertSet('data.sourceMode', false);
        $this->assertSame($saved, ContentEntry::query()->sole()->body);
        Livewire::test(Articles::class, ['articleId' => (string) ContentEntry::query()->sole()->id])
            ->assertSet('data.sourceMode', false)->call('save')->assertHasNoErrors();
        $this->assertSame($saved, ContentEntry::query()->sole()->body);
    }

    public function test_first_save_redirects_only_after_success_and_subsequent_save_updates_same_article(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'polina']), 'web');
        $page = Livewire::test(Articles::class, ['articleId' => 'create'])
            ->set('data.body', $this->state('<p>H<sub>2</sub>O and m<sup>2</sup>.</p>'))
            ->call('save')->assertHasErrors(['data.title'])->assertNoRedirect();
        $this->assertDatabaseCount('content_entries', 0);

        $page->set('data.title', 'Polina saved article')->call('save')->assertHasNoErrors();
        $entry = ContentEntry::query()->sole();
        $page->assertRedirect(Articles::getUrl(['articleId' => $entry->id, 'returnTo' => '/admin/articles'], isAbsolute: false));
        Livewire::test(Articles::class, ['articleId' => (string) $entry->id])
            ->assertSet('data.sourceMode', false)->set('data.title', 'Polina revised article')
            ->call('save')->assertHasNoErrors()->assertNoRedirect();
        $this->assertDatabaseCount('content_entries', 1);
        $this->assertSame('Polina revised article', $entry->fresh()->title);
        $this->assertStringContainsString('<sub>2</sub>', $entry->fresh()->body);
        $this->assertStringContainsString('<sup>2</sup>', $entry->fresh()->body);
    }

    public function test_preview_preserves_dirty_form_revision_status_and_has_no_writes(): void
    {
        $entry = ContentEntry::create(['content_type' => 'article', 'title' => 'Saved', 'slug' => 'saved', 'body' => '<p>Saved body</p>', 'body_format' => 'html', 'status' => 'draft', 'revision' => 1]);
        $page = Livewire::test(Articles::class, ['articleId' => (string) $entry->id])
            ->set('data.title', '<script>Unsaved</script>')->set('data.status', 'published')
            ->set('data.body', '<h2>Unsaved text</h2><table><tr><td>Wide</td></tr></table><script>alert(1)</script>');
        $before = [$page->get('data'), $page->get('savedForm'), $page->get('savedDataHash'), $page->get('revision'), $page->get('savedStatus')];
        DB::flushQueryLog();
        DB::enableQueryLog();
        $result = $page->instance()->previewDocument();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertTrue($result['ok']);
        $this->assertStringContainsString('&lt;script&gt;Unsaved&lt;/script&gt;', $result['html']);
        $this->assertStringContainsString('<h2>Unsaved text</h2>', $result['html']);
        $this->assertStringContainsString('class="ui-table-scroll"', $result['html']);
        $this->assertStringNotContainsString('<script>', $result['html']);
        $this->assertSame($before, [$page->get('data'), $page->get('savedForm'), $page->get('savedDataHash'), $page->get('revision'), $page->get('savedStatus')]);
        $this->assertSame('Saved', $entry->fresh()->title);
        $this->assertSame('draft', $entry->fresh()->status);
        $this->assertSame(1, $entry->fresh()->revision);
        foreach ($queries as $query) {
            $this->assertDoesNotMatchRegularExpression('/^\s*(insert|update|delete|replace)/i', $query['query']);
        }
        Queue::assertNothingPushed();
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->getJson('/api/v1/articles/saved')->assertNotFound();
    }

    public function test_preview_renders_escaped_introduction_before_unchanged_body_before_and_after_save(): void
    {
        $bodyText = 'Unique visible body introduction.';
        $excerpt = 'An introduction with <b>plain text</b> & special characters.';
        $page = Livewire::test(Articles::class, ['articleId' => 'create'])
            ->set('data.title', 'Preview parity')->set('data.sourceMode', true)
            ->set('data.body', '<p>'.$bodyText.'</p>')->set('data.excerpt', $excerpt);
        $preview = $page->instance()->previewDocument();
        $this->assertTrue($preview['ok']);
        $this->assertStringContainsString(e($excerpt), $preview['html']);
        $this->assertStringNotContainsString('<b>plain text</b>', $preview['html']);
        $this->assertLessThan(strpos($preview['html'], '<div class="ui-prose">'), strpos($preview['html'], e($excerpt)));
        $this->assertSame(1, substr_count($preview['html'], $bodyText));

        $page->call('save')->assertHasNoErrors();
        $this->assertSame($excerpt, ContentEntry::query()->sole()->excerpt);
        $preview = $page->instance()->previewDocument();
        $this->assertTrue($preview['ok']);
        $this->assertSame(1, substr_count($preview['html'], e($excerpt)));
        $this->assertSame(1, substr_count($preview['html'], $bodyText));

        // Save auto-generates excerpt from body when the catalog excerpt is empty.
        $page->set('data.excerpt', '')->call('save')->assertHasNoErrors();
        $entry = ContentEntry::query()->sole();
        $this->assertSame($bodyText, $entry->excerpt);
    }

    public function test_pending_native_image_and_cover_preview_use_only_signed_temporary_urls(): void
    {
        $file = $this->temporary();
        $page = Livewire::test(Articles::class, ['articleId' => 'create'])->set('data.title', 'Temporary');
        $instance = $page->instance();
        $instance->componentFileAttachments = ['data' => ['body' => ['temporary-uuid' => $file]]];
        $instance->data['body'] = ['type' => 'doc', 'content' => [['type' => 'image', 'attrs' => ['id' => 'temporary-uuid', 'src' => 'untrusted', 'alt' => 'Temporary']]]];
        $instance->data['cover_path'] = ['cover' => $file];
        $before = $instance->data;
        $filesBefore = Storage::disk('tmp-for-tests')->allFiles();
        $result = $instance->previewDocument();
        $this->assertTrue($result['ok']);
        $this->assertSame(2, substr_count($result['html'], e($file->temporaryUrl())));
        $this->assertStringNotContainsString('src="untrusted"', $result['html']);
        $this->assertSame($before, $instance->data);
        $this->assertSame($filesBefore, Storage::disk('tmp-for-tests')->allFiles());
        $this->assertTrue($file->exists());
        $this->assertDatabaseCount('content_entries', 0);
        $this->assertDatabaseCount('article_media_assets', 0);
        $this->assertSame([], Storage::disk('public')->allFiles());
        Queue::assertNothingPushed();
    }

    public function test_unknown_uuid_and_unregistered_asset_report_form_errors(): void
    {
        $page = Livewire::test(Articles::class, ['articleId' => 'create'])->set('data.title', 'Unknown');
        foreach ([['type' => 'doc', 'content' => [['type' => 'image', 'attrs' => ['id' => 'unknown-uuid']]]], '<p><img src="/storage/article-media/'.str_repeat('b', 64).'.jpg"></p>', '<img src="/article-preview-media/unknown">'] as $body) {
            $page->instance()->data['body'] = $body;
            $result = $page->instance()->previewDocument();
            $this->assertFalse($result['ok']);
            $this->assertArrayHasKey('data.body', $result['errors']);
        }
        $this->assertDatabaseCount('content_entries', 0);
        Queue::assertNothingPushed();
    }

    public function test_source_toggle_keeps_pending_image_identity_and_preview_without_saving(): void
    {
        $file = $this->temporary();
        $body = ['type' => 'doc', 'content' => [['type' => 'image', 'attrs' => ['id' => 'pending-uuid', 'src' => $file->temporaryUrl(), 'alt' => 'Pending']]]];
        $page = Livewire::test(Articles::class, ['articleId' => 'create'])->set('data.title', 'Pending')->set('data.body', $body);
        $savedForm = $page->get('savedForm');
        $page->set('componentFileAttachments', ['data' => ['body' => ['pending-uuid' => $file]]])
            ->set('data.sourceMode', true)->assertSet('data.sourceMode', false)->assertSet('data.body', $body)
            ->assertHasErrors(['data.body'])->assertSee('Сначала сохраните статью, чтобы перейти к HTML.')
            ->assertSet('revision', 0)->assertSet('savedForm', $savedForm);
        $result = $page->instance()->previewDocument();
        $this->assertTrue($result['ok']);
        $this->assertStringContainsString(e($file->temporaryUrl()), $result['html']);
        $this->assertDatabaseCount('content_entries', 0);
        $this->assertDatabaseCount('article_media_assets', 0);
        $this->assertTrue($file->exists());
        Queue::assertNothingPushed();
    }

    public function test_preview_rechecks_actor_and_refuses_list_page(): void
    {
        Livewire::test(Articles::class)->call('previewDocument')->assertStatus(422);
        $page = Livewire::test(Articles::class, ['articleId' => 'create']);
        auth('web')->logout();
        $page->call('previewDocument')->assertForbidden();
    }

    public function test_avif_is_added_without_removing_existing_preview_formats(): void
    {
        foreach (['avif', 'png', 'gif', 'bmp', 'svg', 'wav', 'mp4', 'mov', 'avi', 'wmv', 'mp3', 'm4a', 'jpg', 'jpeg', 'mpga', 'webp', 'wma'] as $mime) {
            $this->assertContains($mime, config('livewire.temporary_file_upload.preview_mimes'));
        }
        $file = $this->temporary('avif');
        $preview = app(ArticlePreview::class)->document(['title' => 'AVIF', 'body' => '<p>Body</p>', 'cover_path' => [$file]], []);
        $this->assertStringContainsString(e($file->temporaryUrl()), $preview);
        $this->assertDatabaseCount('article_media_assets', 0);
    }

    private function temporary(string $extension = 'gif'): TemporaryUploadedFile
    {
        $filename = 'test-meta'.base64_encode('figure.'.$extension).'-.'.$extension;
        $bytes = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
        Storage::disk('tmp-for-tests')->put('livewire-tmp/'.$filename, $bytes);
        Storage::disk('tmp-for-tests')->put('livewire-tmp/'.$filename.'.json', json_encode(['name' => 'figure.'.$extension, 'size' => strlen($bytes), 'type' => 'image/'.$extension]));

        return TemporaryUploadedFile::createFromLivewire($filename);
    }
}
