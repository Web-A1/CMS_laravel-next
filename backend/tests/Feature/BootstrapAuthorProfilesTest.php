<?php

namespace Tests\Feature;

use App\Models\ArticleMediaAsset;
use App\Models\ContentAuthor;
use App\Models\ContentEntry;
use App\Services\Articles\ArticleMedia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class BootstrapAuthorProfilesTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    private string $manifest;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('public');
        $this->directory = sys_get_temp_dir().'/author-profile-'.bin2hex(random_bytes(8));
        mkdir($this->directory);
        $this->manifest = $this->directory.'/expected-state.json';
        config(['author_profiles.source_directory' => $this->directory, 'articles.media_disk' => 'public']);
        foreach (config('author_profiles.home') as $index => $profile) {
            $path = $this->directory.'/'.$profile['key'].'-avatar-20261102.webp';
            (new Process(['magick', '-size', '128x128', 'xc:rgb('.($index * 25).',80,100)', 'WEBP:'.$path]))->mustRun();
            config(['author_profiles.source_sha256.'.$profile['key'] => hash_file('sha256', $path)]);
        }
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $path) {
            unlink($path);
        }
        rmdir($this->directory);
        parent::tearDown();
    }

    private function seedBaseline(): array
    {
        $home = collect(config('author_profiles.home'))->filter(fn ($profile) => $profile['id'] !== null)->keyBy('id');
        $source = $this->directory.'/demo-author-6-avatar-20261102.webp';
        $photo = app(ArticleMedia::class)->ingest($source, ['kind' => 'test']);
        for ($id = 1; $id <= 11; $id++) {
            $profile = $home->get($id);
            ContentAuthor::create(['id' => $id, 'slug' => $profile['slug'] ?? config('author_profiles.historical')[$id], 'name' => $profile['name'] ?? 'Historical '.$id, 'bio' => 'Preserved private biography '.$id, 'photo_path' => $photo->key]);
        }
        ContentEntry::create(['content_type' => 'article', 'slug' => 'linked', 'title' => 'Linked', 'body' => 'Body', 'content_author_id' => 4]);
        $baseline = ContentAuthor::query()->orderBy('id')->get()->map(fn ($author) => $author->only(['id', 'slug', 'name', 'bio', 'photo_path', 'user_id']))->all();
        $links = DB::table('content_entries')->orderBy('id')->get(['id', 'content_author_id'])->map(fn ($row) => (array) $row)->all();
        file_put_contents($this->manifest, json_encode(['authors' => $baseline, 'article_links_sha256' => hash('sha256', json_encode($links))]));

        return $baseline;
    }

    public function test_dry_run_apply_repeat_keep_thirteen_eight_five_and_original_fields_and_links(): void
    {
        $baseline = $this->seedBaseline();
        $this->artisan('articles:bootstrap-author-profiles', ['--expected-state' => $this->manifest])->expectsOutputToContain('13 profiles proposed')->assertSuccessful();
        $this->assertDatabaseCount('content_authors', 11);
        $this->assertDatabaseCount('article_media_assets', 1);
        $this->assertDatabaseCount('article_audit_events', 0);
        $this->artisan('articles:bootstrap-author-profiles', ['--expected-state' => $this->manifest, '--apply' => true])->assertSuccessful();
        $this->assertDatabaseCount('content_authors', 13);
        $this->assertSame(8, ContentAuthor::whereNotNull('home_sort_order')->count());
        $this->assertSame(5, ContentAuthor::whereNull('home_sort_order')->count());
        $this->assertSame($baseline, ContentAuthor::where('id', '<=', 11)->orderBy('id')->get()->map(fn ($author) => $author->only(['id', 'slug', 'name', 'bio', 'photo_path', 'user_id']))->all());
        $this->assertSame(4, ContentEntry::sole()->content_author_id);
        $this->assertSame(0, ContentAuthor::whereNotNull('user_id')->count());
        $this->assertSame(array_column(config('author_profiles.home'), 'name'), $this->getJson('/api/v1/content-authors?placement=home')->assertOk()->json('data.*.name'));
        foreach (ContentAuthor::whereNull('home_sort_order')->get() as $author) {
            $this->assertSame($author->photo_path, $author->avatar_path);
        }
        $this->assertSame(8, ArticleMediaAsset::where('processing_status', 'ready')->count());
        $this->assertDatabaseCount('article_audit_events', 13);
        $state = ContentAuthor::orderBy('id')->get()->toArray();
        $this->artisan('articles:bootstrap-author-profiles', ['--expected-state' => $this->manifest, '--apply' => true])->expectsOutputToContain('0 profiles to apply')->assertSuccessful();
        $this->assertSame($state, ContentAuthor::orderBy('id')->get()->toArray());
        $this->assertDatabaseCount('article_audit_events', 13);
    }

    public function test_editorial_identity_source_and_article_link_conflicts_fail_before_any_ingest(): void
    {
        $this->seedBaseline();
        foreach ([['home_role_text' => 'Editorial'], ['slug' => 'changed'], ['bio' => 'Changed']] as $patch) {
            $author = ContentAuthor::findOrFail(11);
            $before = $author->getAttributes();
            $author->update($patch);
            $this->artisan('articles:bootstrap-author-profiles', ['--expected-state' => $this->manifest, '--apply' => true])->assertFailed();
            $this->assertDatabaseCount('article_media_assets', 1);
            $this->assertDatabaseCount('article_audit_events', 0);
            $author->setRawAttributes($before)->save();
        }
        ContentEntry::sole()->update(['content_author_id' => 1]);
        $this->artisan('articles:bootstrap-author-profiles', ['--expected-state' => $this->manifest, '--apply' => true])->assertFailed();
        ContentEntry::sole()->update(['content_author_id' => 4]);
        config(['author_profiles.source_sha256.demo-author-7' => str_repeat('f', 64)]);
        $this->artisan('articles:bootstrap-author-profiles', ['--expected-state' => $this->manifest, '--apply' => true])->assertFailed();
        $this->assertDatabaseCount('article_media_assets', 1);
        $this->assertDatabaseCount('article_audit_events', 0);
    }

    public function test_remote_asset_disk_is_rejected_before_ingest_or_storage_read_and_target_is_bounded(): void
    {
        $this->seedBaseline();
        DB::table('article_media_assets')->update(['disk' => 'article-media-s3']);
        Storage::shouldReceive('disk')->with('article-media-s3')->never();
        $this->artisan('articles:bootstrap-author-profiles', ['--expected-state' => $this->manifest, '--apply' => true])->expectsOutputToContain('Non-public or inactive')->assertFailed();
        $this->assertDatabaseCount('article_media_assets', 1);
        $this->assertDatabaseCount('article_audit_events', 0);
        config(['articles.media_disk' => 'article-media-s3']);
        $this->artisan('articles:bootstrap-author-profiles', ['--expected-state' => $this->manifest])->assertFailed();
        config(['articles.media_disk' => 'public']);
        $this->app->detectEnvironment(fn () => 'staging');
        $this->artisan('articles:bootstrap-author-profiles', ['--expected-state' => $this->manifest])->assertFailed();
    }
}
