<?php

namespace Tests\Feature;

use App\Filament\Pages\Articles;
use App\Models\ArticleMediaAsset;
use App\Models\ContentEntry;
use App\Models\User;
use App\Services\Articles\ArticleMedia;
use App\Services\Articles\ArticleMediaVariants;
use Filament\Forms\Components\RichEditor;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\Livewire;
use Tests\TestCase;

class ArticleMediaUploadTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        Artisan::call('migrate', ['--force' => true]);
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        config(['livewire.temporary_file_upload.disk' => 'local']);
        Storage::fake('tmp-for-tests');
        Storage::fake('public');
        Queue::fake();
        $this->actingAs(User::factory()->create(['role' => 'admin_full']), 'web');
    }

    public function test_inline_success_and_dedup_remove_real_livewire_temp_and_keep_original_name_and_queue_source(): void
    {
        $page = Livewire::test(Articles::class, ['articleId' => 'create']);
        $inline = $page->instance()->form->getComponentByStatePath('data.body', withAbsoluteStatePath: true);
        $this->assertInstanceOf(RichEditor::class, $inline);
        $first = $this->temporary();
        $name = $first->getClientOriginalName();
        $path = $inline->saveUploadedFileAttachment($first);
        $this->assertFalse($first->exists());
        $this->assertSame($name, $first->getClientOriginalName());
        $this->assertTrue($first->delete()); // BaseFileUpload repeats cleanup after callback.
        $second = $this->temporary();
        $this->assertSame($path, $inline->saveUploadedFileAttachment($second));
        $this->assertFalse($second->exists());
        $this->assertSame([], Storage::disk('tmp-for-tests')->allFiles('livewire-tmp'));
        $this->assertDatabaseCount('article_media_assets', 1);
        $asset = ArticleMediaAsset::query()->sole();
        Storage::disk('public')->assertExists($asset->key);
        app(ArticleMediaVariants::class)->process($asset->id, $asset->generation);
        $this->assertSame('ready', $asset->fresh()->processing_status);
    }

    public function test_ingest_failure_preserves_source_and_metadata_for_retry(): void
    {
        $file = $this->temporary();
        config(['articles.media_disk' => 'unconfigured-article-disk']);
        try {
            app(ArticleMedia::class)->upload($file);
            $this->fail('Invalid storage was accepted');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Unsupported article media disk', $e->getMessage());
        }
        $this->assertTrue($file->exists());
        $this->assertCount(2, Storage::disk('tmp-for-tests')->allFiles('livewire-tmp'));
        $this->assertDatabaseCount('article_media_assets', 0);
        config(['articles.media_disk' => 'public']);
        app(ArticleMedia::class)->upload($file);
        $this->assertFalse($file->exists());
        $this->assertSame([], Storage::disk('tmp-for-tests')->allFiles('livewire-tmp'));
    }

    public function test_outer_transaction_rollback_keeps_inline_temp_available(): void
    {
        $file = $this->temporary();
        DB::beginTransaction();
        app(ArticleMedia::class)->upload($file);
        $this->assertTrue($file->exists());
        DB::rollBack();
        $this->assertTrue($file->exists());
        $this->assertDatabaseCount('article_media_assets', 0);
    }

    public function test_existing_cover_autosave_uses_native_upload_once_and_preserves_manual_text(): void
    {
        $entry = ContentEntry::create(['content_type' => 'article', 'title' => 'Cover test', 'slug' => 'cover-test', 'body' => '<p>Saved</p>', 'body_format' => 'html', 'status' => 'draft', 'revision' => 1]);
        $file = $this->temporary();
        $page = Livewire::test(Articles::class, ['articleId' => (string) $entry->id])
            ->set('data.title', 'Unfinished')
            ->set('data.cover_path', ['own-upload' => $file])->call('saveParameters', ['cover_path'], 1)
            ->assertHasNoErrors()->assertSet('data.title', 'Unfinished')->assertSet('savedForm.title', 'Cover test');
        $this->assertDatabaseCount('article_media_assets', 1);
        $asset = ArticleMediaAsset::query()->sole();
        $this->assertSame($asset->key, $entry->fresh()->cover_path);
        $this->assertNull($asset->lease_at);
        $this->assertFalse($file->exists());
        $page->call('saveParameters', ['cover_path'], 2)->assertHasNoErrors();
        $this->assertSame(2, $entry->fresh()->revision);
        $this->assertDatabaseCount('article_media_assets', 1);
    }

    public function test_new_cover_remains_temporary_until_first_manual_save(): void
    {
        $file = $this->temporary();
        Livewire::test(Articles::class, ['articleId' => 'create'])->set('data.cover_path', ['own-upload' => $file]);
        $this->assertDatabaseCount('content_entries', 0);
        $this->assertDatabaseCount('article_media_assets', 0);
        $this->assertTrue($file->exists());
    }

    private function temporary(): TemporaryUploadedFile
    {
        $filename = bin2hex(random_bytes(12)).'-meta'.base64_encode('scientific-figure.gif').'-.gif';
        $path = 'livewire-tmp/'.$filename;
        $bytes = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
        Storage::disk('tmp-for-tests')->put($path, $bytes);
        Storage::disk('tmp-for-tests')->put($path.'.json', json_encode(['name' => 'scientific-figure.gif', 'size' => strlen($bytes), 'type' => 'image/gif']));

        return TemporaryUploadedFile::createFromLivewire($filename);
    }
}
