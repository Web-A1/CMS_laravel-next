<?php

namespace Tests\Feature;

use App\Filament\Pages\Articles;
use App\Filament\RichContent\ArticleContentRenderer as RichContentRenderer;
use App\Filament\RichContent\ArticleRichContentPlugin;
use App\Models\ArticleMediaAsset;
use App\Models\ContentEntry;
use App\Models\Subject;
use App\Models\User;
use App\Services\Articles\ArticleEditor;
use App\Services\Articles\ArticleHtml;
use App\Services\Articles\ArticlePreview;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

final class ArticleDocumentNormalizationTest extends TestCase
{
    public function test_read_also_footer_is_removed_without_losing_preceding_content(): void
    {
        $html = app(ArticleHtml::class);
        $body = '<p>Учебный текст <a href="/articles/context">со ссылкой</a>.</p><p>Наш <a href="/subjects/biohimiya">курс</a>.<br><strong>Читайте <em>также</em></strong> про <a href="/articles/other">другую статью</a>.</p>';
        $result = $html->sanitize($body);
        $this->assertSame('<p>Учебный текст <a href="/articles/context">со ссылкой</a>.</p><p>Наш <a href="/subjects/biohimiya">курс</a>.</p>', $result);
        $this->assertSame($result, $html->sanitize($result));
        $this->assertSame('<p>Текст.</p>', $html->sanitize('<p>Текст.</p><p>И читайте также <a href="/articles/other">другую статью</a></p><p>&nbsp;</p>'));
        $middle = '<p>В тексте читайте также <a href="/articles/context">объяснение</a>.</p><p>Дальнейшее учебное содержание.</p>';
        $this->assertSame($middle, $html->sanitize($middle));
        $this->assertSame('<p>А если хотите узнать больше, откройте курс.</p>', $html->sanitize('<p>Читайте также <a href="/articles/context">объяснение</a>.</p><p>А если хотите узнать больше, откройте курс.</p>'));
        $plain = '<p>В учебнике читайте также соответствующую главу.</p>';
        $this->assertSame($plain, $html->sanitize($plain));
        $book = '<p>Откройте <a href="/subjects/biohimiya">курс</a>.<br>Читайте также главу учебника.</p>';
        $this->assertSame($book, $html->sanitize($book));
        $inline = '<p>В тексте читайте также <a href="/articles/context">объяснение</a> и учебный признак.';
        $this->assertSame($inline.'</p>', $html->sanitize($inline.'<br>Читайте также <a href="/articles/other">другую статью</a>.</p>'));
        $this->assertSame('<p>Учебный текст.</p>', $html->sanitize('<p>Учебный текст.<br>И читайте также <a href="/articles/other">другую статью</a>.</p>'));
        $this->assertSame('<p>Текст.<br>Следующий учебный признак.</p>', $html->sanitize('<p>Текст.<br>Читайте также <a href="/articles/other">другую статью</a>.<br>Следующий учебный признак.</p>'));
        $preview = app(ArticlePreview::class)->document(['title' => 'Preview', 'body' => $body], []);
        $this->assertStringNotContainsString('другую статью', $preview);
        $this->assertStringContainsString('href="/subjects/biohimiya"', $preview);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('testing', app()->environment());
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        Queue::fake();
        Storage::fake('public');
        Storage::fake('local');
        Artisan::call('migrate', ['--force' => true]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create(['role' => 'admin_full']), 'web');
    }

    public function test_mixed_paragraphs_keep_text_order_links_marks_and_authored_captions(): void
    {
        $html = app(ArticleHtml::class);
        $source = '<p>До <strong>схемы <a href="/articles/example"><img src="/scheme.jpg" title="Подпись &amp; детали"> после</a> конец</strong>.</p><p>&nbsp;<br></p><p><ins>Важная фраза</ins></p>';
        $normalized = $html->sanitize($source);
        $this->assertSame($normalized, $html->sanitize($normalized));
        $this->assertStringContainsString('<figure>', $normalized);
        $this->assertStringContainsString('<figcaption>Подпись &amp; детали</figcaption>', $normalized);
        $this->assertStringContainsString('alt="Подпись &amp; детали"', $normalized);
        $this->assertStringContainsString('href="/articles/example"', $normalized);
        $this->assertStringContainsString('<u>Важная фраза</u>', $normalized);
        $this->assertLessThan(strpos($normalized, '<figure>'), strpos($normalized, 'схемы'));
        $this->assertLessThan(strpos($normalized, 'после'), strpos($normalized, '</figure>'));
        $this->assertStringNotContainsString('<p>&nbsp;', $normalized);
        $withoutTitle = $html->sanitize('<p><img src="/other.jpg" alt="Описание"></p>');
        $this->assertStringNotContainsString('figcaption', $withoutTitle);
    }

    public function test_bibliography_marker_survives_real_tiptap_state_and_does_not_mark_cellular_sources(): void
    {
        $html = app(ArticleHtml::class);
        $bibliography = $html->sanitize('<p>Текст.</p><h2><strong>Источники</strong></h2><ul><li><a href="https://example.edu">Учебник</a></li></ul>');
        $state = RichContentRenderer::make($bibliography)->plugins([new ArticleRichContentPlugin])->toArray();
        $this->assertTrue($state['content'][1]['attrs']['articleSources']);
        $this->assertStringContainsString('data-article-sources', $html->fromEditorState($state));
        $medical = '<h2>Фактор некроза опухоли</h2><h3>Источники:</h3><ul><li>Активированные макрофаги</li><li>Лимфоциты</li></ul>';
        $this->assertStringNotContainsString('data-article-sources', $html->sanitize($medical));
        $this->assertStringNotContainsString('data-article-sources', $html->sanitize($bibliography.'<p>Следующий раздел.</p>'));
    }

    public function test_ordered_list_start_survives_real_renderer_and_editor_save(): void
    {
        $html = app(ArticleHtml::class);
        foreach ([3, 0, -4, 9_007_199_254_740_991] as $start) {
            $source = '<ol start="'.$start.'" onclick="alert(1)"><li>Первый</li><li>Второй</li></ol>';
            $normalized = $html->sanitize($source);
            $this->assertSame('<ol start="'.$start.'"><li>Первый</li><li>Второй</li></ol>', $normalized);
            $state = RichContentRenderer::make($normalized)->plugins([new ArticleRichContentPlugin])->toArray();
            $this->assertSame($start, $state['content'][0]['attrs']['start']);
            $this->assertStringContainsString('<ol start="'.$start.'">', $html->fromEditorState($state));

            $page = Livewire::test(Articles::class, ['articleId' => 'create'])
                ->set('data.title', 'Numbered '.$start)->set('data.sourceMode', true)->set('data.body', $source)
                ->set('data.sourceMode', false)->call('save')->assertHasNoErrors();
            $entry = ContentEntry::findOrFail($page->get('entryId'));
            $this->assertStringContainsString('<ol start="'.$start.'">', $entry->body);
            $this->assertSame('Первый Второй', $html->plainText($entry->body));
            Livewire::test(Articles::class, ['articleId' => (string) $entry->id])
                ->set('data.sourceMode', false)->set('data.sourceMode', true)->call('save')->assertHasNoErrors();
            $this->assertStringContainsString('<ol start="'.$start.'">', $entry->fresh()->body);
            $this->assertStringNotContainsString('onclick', $entry->fresh()->body);
        }
        foreach (['javascript:alert(1)', '3.5', '3x', '9007199254740992', '-9007199254740992'] as $invalid) {
            $this->assertSame('<ol><li>Текст</li></ol>', $html->sanitize('<ol start="'.$invalid.'" onmouseover="alert(1)"><li>Текст</li></ol>'));
            $state = ['type' => 'doc', 'content' => [['type' => 'orderedList', 'attrs' => ['start' => $invalid], 'content' => [['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Текст']]]]]]]]];
            $this->assertStringNotContainsString('start=', $html->fromEditorState($state));
        }
        $nested = '<ol start="0"><li>До<ol start="-4"><li>Вложенный</li></ol>После</li><li>Второй</li></ol>';
        $state = RichContentRenderer::make($nested)->plugins([new ArticleRichContentPlugin])->toArray();
        $this->assertSame($nested, $html->fromEditorState($state));
        $this->assertSame($nested, RichContentRenderer::make($state)->plugins([new ArticleRichContentPlugin])->toUnsafeHtml());
        $page = Livewire::test(Articles::class, ['articleId' => 'create'])
            ->set('data.title', 'Nested list')->set('data.sourceMode', true)->set('data.body', $nested)
            ->set('data.sourceMode', false)->call('save')->assertHasNoErrors();
        $entry = ContentEntry::findOrFail($page->get('entryId'));
        Livewire::test(Articles::class, ['articleId' => (string) $entry->id])
            ->set('data.sourceMode', false)->set('data.sourceMode', true)->call('save')->assertHasNoErrors();
        $dom = new \DOMDocument;
        $dom->loadHTML('<?xml encoding="utf-8" ?>'.$entry->fresh()->body);
        $xpath = new \DOMXPath($dom);
        $this->assertSame(2, $xpath->query('//body/ol[@start="0"]/li')->length);
        $this->assertSame('Вложенный', $xpath->evaluate('string(//body/ol/li[1]/ol[@start="-4"]/li)'));
        $this->assertSame('До Вложенный После', $html->plainText($dom->saveHTML($xpath->query('//body/ol/li[1]')->item(0))));
        $this->assertSame('Второй', $xpath->evaluate('string(//body/ol/li[2])'));
    }

    public function test_table_header_scope_survives_real_renderer_and_editor_save(): void
    {
        $html = app(ArticleHtml::class);
        $this->assertContains('/article-editor/table-semantics.js', (new ArticleRichContentPlugin)->getTipTapJsExtensions());
        foreach (['row', 'col', 'rowgroup', 'colgroup'] as $scope) {
            $source = '<table><tbody><tr><th scope="'.$scope.'" colspan="2" rowspan="3" onclick="alert(1)">Заголовок</th><td>Ячейка</td></tr></tbody></table>';
            $normalized = $html->sanitize($source);
            $this->assertStringContainsString('scope="'.$scope.'"', $normalized);
            $state = RichContentRenderer::make($normalized)->plugins([new ArticleRichContentPlugin])->toArray();
            $header = $state['content'][0]['content'][0]['content'][0];
            $this->assertSame('tableHeader', $header['type']);
            $this->assertSame($scope, $header['attrs']['scope']);
            $this->assertSame(2, $header['attrs']['colspan']);
            $this->assertSame(3, $header['attrs']['rowspan']);
            $this->assertStringContainsString('scope="'.$scope.'"', $html->fromEditorState($state));
            $page = Livewire::test(Articles::class, ['articleId' => 'create'])
                ->set('data.title', 'Table '.$scope)->set('data.sourceMode', true)->set('data.body', $source)
                ->set('data.sourceMode', false)->call('save')->assertHasNoErrors();
            $entry = ContentEntry::findOrFail($page->get('entryId'));
            Livewire::test(Articles::class, ['articleId' => (string) $entry->id])
                ->set('data.sourceMode', false)->set('data.sourceMode', true)->call('save')->assertHasNoErrors();
            $body = $entry->fresh()->body;
            foreach (['scope="'.$scope.'"', 'colspan="2"', 'rowspan="3"'] as $attribute) {
                $this->assertStringContainsString($attribute, $body);
            }
            $this->assertSame('Заголовок Ячейка', $html->plainText($body));
            $this->assertStringNotContainsString('onclick', $body);
        }
        foreach (['javascript:alert(1)', 'column', 'ROW', ''] as $invalid) {
            $source = '<table><tr><th scope="'.$invalid.'" onmouseover="alert(1)">Текст</th></tr></table>';
            $this->assertStringNotContainsString('scope=', $html->sanitize($source));
            $state = RichContentRenderer::make($source)->plugins([new ArticleRichContentPlugin])->toArray();
            $state['content'][0]['content'][0]['content'][0]['attrs']['scope'] = $invalid;
            $this->assertStringNotContainsString('scope=', $html->fromEditorState($state));
            $this->assertStringNotContainsString('onmouseover', $html->fromEditorState($state));
        }
    }

    public function test_inline_authored_caption_survives_normalization_visual_save_and_preview(): void
    {
        $html = app(ArticleHtml::class);
        $path = 'article-media/'.str_repeat('b', 64).'.jpg';
        ArticleMediaAsset::create(['filename' => basename($path), 'sha256' => str_repeat('b', 64), 'disk' => 'public', 'key' => $path, 'mime' => 'image/jpeg', 'bytes' => 1, 'origin' => ['kind' => 'test'], 'created_at' => now()]);
        $source = '<p><img src="/storage/'.$path.'" alt="Авторское описание"><em>Структура <strong>ткани</strong> &amp; детали</em></p>';
        $normalized = $html->sanitize($source);
        $this->assertStringContainsString('<figcaption><em>Структура <strong>ткани</strong> &amp; детали</em></figcaption>', $normalized);
        $this->assertStringContainsString('alt="Авторское описание"', $normalized);
        $this->assertSame($normalized, $html->sanitize($normalized));
        $this->assertSame($html->plainText($source), $html->plainText($normalized));
        $this->assertStringContainsString('alt="Структура ткани &amp; детали"', $html->sanitize(str_replace('alt="Авторское описание"', 'alt=""', $source)));
        foreach (['<p><img src="/scheme.jpg"></p><p><em>Основной текст</em></p>', '<p>До<img src="/scheme.jpg"><em>Основной текст</em></p>', '<p><img src="/scheme.jpg"><em>Текст</em> после</p>', '<p><img src="/one.jpg"><img src="/two.jpg"><em>Текст</em></p>'] as $ordinary) {
            $this->assertStringNotContainsString('figcaption', $html->sanitize($ordinary));
        }
        $page = Livewire::test(Articles::class, ['articleId' => 'create'])
            ->set('data.title', 'Inline caption')->set('data.sourceMode', true)->set('data.body', $source)
            ->set('data.sourceMode', false)->call('save')->assertHasNoErrors();
        $entry = ContentEntry::query()->article()->sole();
        $this->assertStringContainsString('<figcaption><em>Структура <strong>ткани</strong> &amp; детали</em></figcaption>', $entry->body);
        $this->assertStringContainsString('alt="Авторское описание"', $entry->body);
        $again = Livewire::test(Articles::class, ['articleId' => (string) $entry->id]);
        $before = $entry->fresh()->getAttributes();
        $this->assertStringContainsString('<figcaption><em>Структура <strong>ткани</strong> &amp; детали</em></figcaption>', $again->instance()->previewDocument()['html']);
        $this->assertSame($before, $entry->fresh()->getAttributes());
    }

    public function test_imported_document_can_be_reopened_saved_and_previewed_without_losing_blocks(): void
    {
        $path = 'article-media/'.str_repeat('a', 64).'.jpg';
        ArticleMediaAsset::create(['filename' => basename($path), 'sha256' => str_repeat('a', 64), 'disk' => 'public', 'key' => $path, 'mime' => 'image/jpeg', 'bytes' => 1, 'origin' => ['kind' => 'test'], 'created_at' => now()]);
        $subject = Subject::create(['slug' => 'histology', 'title' => 'Гистология', 'is_active' => true]);
        $body = '<p>До <a href="https://example.edu"><img src="/storage/'.$path.'" alt="Схема"></a> после.</p>'
            .'<p><ins>Сохранить подчёркивание</ins></p><table><tbody><tr><th>Формула</th><td>H<sub>2</sub>O и x<sup>2</sup></td></tr></tbody></table>'
            .'<p><iframe src="https://kinescope.io/embed/abc123" title="Видео"></iframe></p>'
            .'<h2>Источники</h2><ul><li>Учебник</li></ul>';
        $entry = ContentEntry::create(['content_type' => 'article', 'title' => 'Imported', 'slug' => 'imported', 'subject_id' => $subject->id, 'body' => $body, 'body_format' => 'html', 'excerpt' => 'Отдельное вступление.', 'status' => 'hidden', 'tags' => ['old'], 'payload' => ['legacy' => ['id' => 123]], 'revision' => 1]);
        $page = Livewire::test(Articles::class, ['articleId' => (string) $entry->id]);
        $this->assertStringContainsString('<figure>', $page->get('data.body'));
        $this->assertSame($body, $entry->fresh()->body, 'Opening the editor must not persist normalization');
        $page->set('data.sourceMode', false)->call('save')->assertHasNoErrors();
        $saved = $entry->fresh();
        $this->assertSame($subject->id, $saved->subject_id);
        $this->assertSame([], $saved->tags);
        foreach (['<figure>', 'href="https://example.edu"', '<u>Сохранить подчёркивание</u>', '<sub>2</sub>', '<sup>2</sup>', 'kinescope.io/embed/abc123', 'data-article-sources'] as $part) {
            $this->assertStringContainsString($part, $saved->body);
        }
        $again = Livewire::test(Articles::class, ['articleId' => (string) $entry->id]);
        $before = $entry->fresh()->getAttributes();
        $preview = $again->instance()->previewDocument();
        $this->assertTrue($preview['ok']);
        $this->assertStringContainsString('Отдельное вступление.', $preview['html']);
        $this->assertStringContainsString('data-article-sources', $preview['html']);
        $this->assertStringContainsString('ui-table-scroll', $preview['html']);
        $this->assertSame($before, $entry->fresh()->getAttributes());
        $this->getJson('/api/v1/articles/imported')->assertNotFound();
    }

    public function test_preview_omits_repeated_catalog_excerpt_and_save_cannot_reintroduce_tags(): void
    {
        $body = '<p>Начало &amp; продолжение текста.</p>';
        $preview = app(ArticlePreview::class)->document(['title' => 'Preview', 'body' => $body, 'excerpt' => 'Начало & продолжение'], []);
        $this->assertStringNotContainsString('<p class="article-preview-introduction">', $preview);
        $saved = app(ArticleEditor::class)->save(null, ['title' => 'New', 'slug' => 'new', 'body' => $body, 'status' => 'draft', 'tags' => ['forbidden']]);
        $this->assertSame([], $saved->tags);
        $this->assertNotContains('tags', ArticleEditor::PARAMETERS);
    }

    public function test_unsaved_source_html_is_normalized_before_entering_visual_mode(): void
    {
        $page = Livewire::test(Articles::class, ['articleId' => 'create'])
            ->set('data.title', 'Unsaved source')
            ->set('data.sourceMode', true)
            ->set('data.body', '<p><ins>Подчёркнутая фраза</ins> рядом с <a href="/articles/example"><strong>ссылкой</strong></a>.</p>')
            ->set('data.sourceMode', false)
            ->call('save')->assertHasNoErrors();
        $entry = ContentEntry::query()->article()->sole();
        $this->assertStringContainsString('<u>Подчёркнутая фраза</u>', $entry->body);
        $this->assertStringContainsString('href="/articles/example"', $entry->body);
        $this->assertStringContainsString('<strong>ссылкой</strong>', $entry->body);
        $again = Livewire::test(Articles::class, ['articleId' => (string) $entry->id]);
        $again->assertSet('data.sourceMode', false);
        $this->assertStringContainsString('<u>Подчёркнутая фраза</u>', app(ArticleHtml::class)->fromEditorState($again->get('data.body')));
        $this->assertStringContainsString('<u>Подчёркнутая фраза</u>', $again->instance()->previewDocument()['html']);
        $this->assertSame('Подчёркнутая фраза рядом с ссылкой.', app(ArticleHtml::class)->plainText($entry->body));
    }
}
