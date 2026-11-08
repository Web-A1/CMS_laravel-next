<?php

namespace Tests\Feature;

use App\Filament\Pages\Articles;
use App\Filament\RichContent\ArticleRichContentPlugin;
use App\Models\ContentEntry;
use App\Models\User;
use App\Services\Articles\ArticleHtml;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\RichEditor\RichContentRenderer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class ArticleImageLayoutTest extends TestCase
{
    public function test_image_actions_keep_layout_and_captions_through_preview_save_and_reopen(): void
    {
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        config(['livewire.temporary_file_upload.disk' => 'local']);
        Storage::fake('public');
        Storage::fake('local');
        Storage::fake('tmp-for-tests');
        Queue::fake();
        Artisan::call('migrate', ['--force' => true]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create(['role' => 'admin_full']), 'web');

        $page = Livewire::test(Articles::class, ['articleId' => 'create'])->set('data.title', 'Image layout');
        $single = $pair = null;
        $page->callAction(TestAction::make('articleImage')->schemaComponent('body', 'form'), data: [
            'first_file' => [$this->temporaryImage('single')], 'first_caption' => 'Single caption',
        ])->assertHasNoActionErrors()->assertDispatched('run-rich-editor-commands', function ($event, $params) use (&$single): bool {
            $single = $params['commands'][0]['arguments'][0];

            return $params['commands'][0]['name'] === 'insertArticleImageBlock' && $single['type'] === 'articleFigure';
        });
        $page->callAction(TestAction::make('articleImagePair')->schemaComponent('body', 'form'), data: [
            'first_file' => [$this->temporaryImage('first')], 'first_caption' => 'First caption',
            'second_file' => [$this->temporaryImage('second')], 'second_caption' => 'Second caption',
        ])->assertHasNoActionErrors()->assertDispatched('run-rich-editor-commands', function ($event, $params) use (&$pair): bool {
            $pair = $params['commands'][0]['arguments'][0];

            return $params['commands'][0]['name'] === 'insertArticleImageBlock' && $pair['type'] === 'articleImagePair';
        });

        $page->set('data.body', ['type' => 'doc', 'content' => [$single, $pair]]);
        $preview = $page->instance()->previewDocument();
        $this->assertTrue($preview['ok']);
        $this->assertStringContainsString('data-article-images="pair"', $preview['html']);
        $this->assertDatabaseCount('article_media_assets', 0);
        $page->call('save')->assertHasNoErrors();
        $body = ContentEntry::query()->sole()->body;
        $this->assertStringContainsString('data-article-images="pair"', $body);
        $this->assertSame(3, substr_count($body, '<figure>'));
        $this->assertStringNotContainsString('livewire', $body);
        $this->assertStringContainsString('alt="Single caption"', $body);
        $this->assertStringContainsString('alt="First caption"', $body);
        $this->assertStringContainsString('alt="Second caption"', $body);
        $this->assertLessThan(strpos($body, 'Second caption'), strpos($body, 'First caption'));
        $this->assertStringContainsString('Single caption', $body);

        $parsed = RichContentRenderer::make($body)->plugins([new ArticleRichContentPlugin])->toArray();
        $this->assertSame(['articleFigure', 'articleImagePair'], array_column($parsed['content'], 'type'));
        $this->assertSame($body, app(ArticleHtml::class)->fromEditorState($parsed));
        $this->assertSame('<div>Keep text</div>', app(ArticleHtml::class)->sanitize('<div data-article-images="other">Keep text</div>'));
    }

    private function temporaryImage(string $name): UploadedFile
    {
        $bytes = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');

        return UploadedFile::fake()->createWithContent($name.'.gif', $bytes);
    }
}
