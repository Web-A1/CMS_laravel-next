<?php

namespace Tests\Feature;

use App\Filament\Pages\Articles;
use App\Filament\Pages\StaffProfile;
use App\Filament\Pages\UserDetail;
use App\Models\AdminUserEvent;
use App\Models\ArticleMediaAsset;
use App\Models\ContentAuthor;
use App\Models\ContentCategory;
use App\Models\ContentEntry;
use App\Models\ContentPlacement;
use App\Models\User;
use App\Services\AdminStaffProfile;
use App\Services\AdminUserManagement;
use App\Services\Articles\ArticleEditor;
use App\Services\Articles\LegacyArticleImport;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class StaffAuthorProfileTest extends TestCase
{
    use RefreshDatabase;

    private function teacher(array $fields = []): User
    {
        return User::factory()->create(['role' => 'teacher', 'name' => 'Отдельное имя аккаунта', 'first_name' => 'Демо', 'last_name' => 'Преподаватель', ...$fields]);
    }

    private function editor(): ArticleEditor
    {
        $this->actingAs(User::factory()->create(['role' => 'admin_full']), 'web');

        return app(ArticleEditor::class);
    }

    private function author(User $user): ContentAuthor
    {
        $author = $this->editor()->saveTaxonomy('author', null, ['slug' => 'teacher', 'name' => 'Демо Автор 1']);
        DB::table('content_authors')->where('id', $author->id)->update(['user_id' => $user->id]);

        return $author->refresh();
    }

    private function hash(ContentAuthor $author): string
    {
        return hash('sha256', json_encode($author->fresh()->getAttributes()));
    }

    public function test_author_parameters_preserve_manual_fields_and_historical_link(): void
    {
        $teacher = $this->teacher();
        $author = $this->author($teacher);
        $editor = app(ArticleEditor::class);
        $account = $teacher->fresh()->getAttributes();
        $updated = $editor->saveAuthorParameters($author->id, ['home_role_text' => 'Editorial role'], $this->hash($author));
        $this->assertSame('Демо Автор 1', $updated->name);
        $this->assertSame($teacher->id, $updated->user_id);
        $stale = $this->hash($author);
        $updated = $editor->saveTaxonomy('author', $author->id, ['slug' => 'teacher', 'name' => 'Editorial name', 'bio' => 'Biography'], $stale);
        $this->assertSame('Editorial name', $updated->name);
        $this->assertSame($teacher->id, $updated->user_id);
        $this->assertSame($account, $teacher->fresh()->getAttributes());
        try {
            $editor->saveAuthorParameters($author->id, ['home_role_text' => 'Stale'], $stale);
            $this->fail('Stale hash must fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('expectedHash', $exception->errors());
        }
        $this->expectException(ValidationException::class);
        $editor->saveAuthorParameters($author->id, ['bio' => 'Forbidden draft'], $this->hash($author));
    }

    public function test_author_parameter_and_full_no_op_do_not_touch_audit_or_media_lease(): void
    {
        Queue::fake();
        $editor = $this->editor();
        $hash = str_repeat('a', 64);
        $path = 'article-media/'.$hash.'.jpg';
        $asset = ArticleMediaAsset::create(['filename' => $hash.'.jpg', 'disk' => 'public', 'key' => $path, 'sha256' => $hash,
            'bytes' => 12, 'mime' => 'image/jpeg', 'origin' => ['kind' => 'test'], 'created_at' => now()]);
        $author = $editor->saveTaxonomy('author', null, ['slug' => 'photo-author', 'name' => 'Manual', 'photo_path' => $path]);
        $asset->update(['lease_at' => now()]);
        $before = $author->fresh()->getAttributes();
        $lease = $asset->fresh()->getAttributes();
        $this->travel(10)->seconds();
        $editor->saveAuthorParameters($author->id, ['photo_path' => $path], $this->hash($author));
        $editor->saveTaxonomy('author', $author->id, ['slug' => 'photo-author', 'name' => 'Manual', 'bio' => '', 'photo_path' => $path], $this->hash($author));
        $this->assertSame($before, $author->fresh()->getAttributes());
        $this->assertSame($lease, $asset->fresh()->getAttributes());
        $this->assertSame(1, DB::table('article_audit_events')->count());
        Queue::assertNothingPushed();
    }

    public function test_author_partial_rejects_invalid_account_and_unregistered_photo_without_changes(): void
    {
        $editor = $this->editor();
        $author = $editor->saveTaxonomy('author', null, ['slug' => 'safe-author', 'name' => 'Saved']);
        $before = $author->fresh()->getAttributes();
        foreach ([['user_id' => $this->teacher(['role' => 'manager'])->id], ['photo_path' => 'article-media/'.str_repeat('b', 64).'.jpg']] as $patch) {
            try {
                $editor->saveAuthorParameters($author->id, $patch, $this->hash($author));
                $this->fail('Invalid parameter must fail.');
            } catch (ValidationException) {
                $this->assertSame($before, $author->fresh()->getAttributes());
                $this->assertSame(1, DB::table('article_audit_events')->count());
            }
        }
    }

    public function test_both_profile_paths_leave_historical_author_and_audit_unchanged(): void
    {
        $user = $this->teacher();
        $author = $this->author($user);
        $before = $author->getAttributes();
        $oldRevision = AdminUserManagement::revisionFor($user, 0);
        app(AdminStaffProfile::class)->update($user, ['name' => $user->name, 'first_name' => '  Анна  ']);
        $this->assertNotSame($oldRevision, AdminUserManagement::revisionFor($user->fresh(), 0));
        app(AdminUserManagement::class)->mutate(auth('web')->user(), $user->id, 'profile', ['revision' => AdminUserManagement::revisionFor($user->fresh(), 0), 'last_name' => 'Иванова']);
        $user->forceFill(['role' => 'student'])->save();
        $this->assertSame($before, $author->fresh()->getAttributes());
        $this->assertSame(2, AdminUserEvent::query()->where('user_id', $user->id)->count());
        $this->assertSame(1, DB::table('article_audit_events')->count());
    }

    public function test_historical_link_does_not_prevent_clearing_account_names(): void
    {
        $user = $this->teacher();
        $author = $this->author($user);
        $before = $author->getAttributes();
        app(AdminStaffProfile::class)->update($user, ['name' => $user->name, 'last_name' => null]);
        app(AdminUserManagement::class)->mutate(auth('web')->user(), $user->id, 'profile', ['revision' => AdminUserManagement::revisionFor($user->fresh(), 0), 'first_name' => null]);
        $this->assertNull($user->fresh()->first_name);
        $this->assertNull($user->fresh()->last_name);
        $this->assertSame($before, $author->fresh()->getAttributes());
        $this->assertSame(1, DB::table('article_audit_events')->count());
    }

    public function test_api_fields_roles_validation_and_legacy_payload_remain_strict(): void
    {
        $user = $this->teacher(['first_name' => null, 'last_name' => null]);
        $this->actingAs($user);
        $this->patchJson('/api/v1/admin/me', ['name' => 'Display', 'first_name' => 'Анна-Мария', 'last_name' => 'O’Нил'])->assertOk()
            ->assertJsonPath('first_name', 'Анна-Мария');
        $this->patchJson('/api/v1/admin/me', ['name' => 'Display', 'first_name' => str_repeat('я', 121)])->assertUnprocessable();
        $this->patchJson('/api/v1/admin/me', ['name' => 'Display', 'last_name' => "Bad\nName"])->assertUnprocessable();
        $this->patchJson('/api/v1/admin/me', ['name' => 'Display', 'user_id' => 1])->assertUnprocessable();
        $manager = User::factory()->create(['role' => 'manager']);
        $this->actingAs($manager);
        $this->patchJson('/api/v1/admin/me', ['name' => 'Old payload'])->assertOk();
        $this->patchJson('/api/v1/admin/me', ['name' => 'Old payload', 'first_name' => null])->assertUnprocessable();
        $this->assertNull($manager->fresh()->first_name);
        $this->assertArrayNotHasKey('first_name', $user->fresh()->toArray());
    }

    public function test_any_account_link_key_is_rejected_on_create_full_and_partial_save(): void
    {
        $editor = $this->editor();
        $teacher = $this->teacher();
        $author = $editor->saveTaxonomy('author', null, ['slug' => 'manual', 'name' => 'Manual']);
        $before = $author->fresh()->getAttributes();
        foreach ([null, '', $teacher->id, 999999] as $value) {
            foreach (['create', 'full', 'partial'] as $operation) {
                try {
                    $input = ['slug' => 'manual', 'name' => 'Changed', 'user_id' => $value];
                    if ($operation === 'partial') {
                        $editor->saveAuthorParameters($author->id, ['user_id' => $value], $this->hash($author));
                    } else {
                        $editor->saveTaxonomy('author', $operation === 'create' ? null : $author->id, $input, $this->hash($author));
                    }
                    $this->fail('Any supplied user_id must fail.');
                } catch (ValidationException) {
                    $this->assertSame($before, $author->fresh()->getAttributes());
                    $this->assertDatabaseCount('content_authors', 1);
                    $this->assertDatabaseCount('article_audit_events', 1);
                }
            }
        }
        $this->assertFalse((new ContentAuthor)->isFillable('user_id'));
        $raw = new ContentAuthor;
        $raw->forceFill(['slug' => 'historical', 'name' => 'Historical', 'user_id' => $teacher->id])->save();
        $saved = $editor->saveTaxonomy('author', $raw->id, ['slug' => 'historical', 'name' => 'Independent'], $this->hash($raw));
        $this->assertSame('Independent', $saved->name);
        $this->assertSame($teacher->id, $saved->user_id);
    }

    public function test_historical_role_change_and_account_delete_keep_editorial_snapshot(): void
    {
        $user = $this->teacher();
        $author = $this->author($user);
        $user->forceFill(['role' => 'student', 'first_name' => 'Changed'])->save();
        app(ArticleEditor::class)->saveTaxonomy('author', $author->id, ['slug' => 'teacher', 'name' => 'Editorial', 'bio' => 'Updated'], $this->hash($author));
        $user->delete();
        $this->assertNull($author->fresh()->user_id);
        $this->assertSame('Editorial', $author->fresh()->name);
        $this->assertSame('Updated', $author->fresh()->bio);
    }

    public function test_public_author_whitelist_includes_avatar_and_preserves_null(): void
    {
        $author = $this->author($this->teacher());
        $category = ContentCategory::create(['content_type' => 'article', 'slug' => 'science', 'title' => 'Science', 'is_active' => true]);
        foreach (['signed' => $author->id, 'unsigned' => null] as $slug => $authorId) {
            $entry = ContentEntry::create(['content_type' => 'article', 'slug' => $slug, 'title' => $slug, 'body' => 'Body', 'status' => 'published', 'published_at' => now()->subDay(), 'content_author_id' => $authorId, 'content_category_id' => $category->id]);
            ContentPlacement::create(['content_entry_id' => $entry->id, 'placement' => 'articles.index', 'is_enabled' => true]);
        }
        $json = $this->getJson('/api/v1/articles/signed')->assertOk()->json('data.author');
        $this->assertSame(['id', 'name', 'slug', 'avatarUrl', 'avatarVariants', 'photoUrl', 'photoVariants', 'aboutUrl'], array_keys($json));
        $this->assertSame('Демо Автор 1', $json['name']);
        $this->getJson('/api/v1/articles/unsigned')->assertOk()->assertJsonPath('data.author', null);
    }

    public function test_article_choice_records_actual_actor_and_remains_independent_of_account_changes(): void
    {
        $teacher = $this->teacher();
        $author = $this->author($teacher);
        $actor = auth('web')->user();
        $entry = ContentEntry::create(['content_type' => 'article', 'slug' => 'selected', 'title' => 'Selected', 'body' => 'Body', 'status' => 'draft', 'revision' => 1]);
        app(ArticleEditor::class)->saveParameters($entry->id, ['content_author_id' => $author->id], 1);
        $event = DB::table('article_audit_events')->where('entity_type', ContentEntry::class)->where('entity_id', $entry->id)->sole();
        $this->assertSame($actor->id, $event->actor_id);
        $this->assertSame($author->id, json_decode($event->after, true)['content_author_id']);
        $before = $entry->fresh()->getAttributes();
        app(AdminStaffProfile::class)->update($teacher, ['name' => 'New account name', 'first_name' => 'New first']);
        $this->assertSame($before, $entry->fresh()->getAttributes());
        $this->assertSame('Демо Автор 1', $author->fresh()->name);
        $this->assertDatabaseCount('article_audit_events', 2);
    }

    public function test_livewire_staff_and_user_card_save_names_and_author_action_uses_editorial_name(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $teacher = $this->teacher();
        $this->actingAs($teacher, 'web');
        Livewire::test(StaffProfile::class)->fillForm(['first_name' => '  Мария ', 'last_name' => 'Новая'])->call('save')->assertHasNoFormErrors();
        $this->assertSame('Мария', $teacher->fresh()->first_name);
        $this->editor();
        Livewire::test(UserDetail::class, ['userId' => (string) $teacher->id])->fillForm(['last_name' => 'Другая'])->call('saveProfile')->assertHasNoFormErrors();
        Livewire::test(Articles::class, ['articleId' => 'create'])->callAction('author', ['slug' => 'livewire-author', 'name' => 'Editorial name'])->assertHasNoActionErrors();
        $this->assertSame('Editorial name', ContentAuthor::query()->sole()->name);
    }

    public function test_import_hash_ignores_only_null_link_and_keeps_editorial_conflicts(): void
    {
        $user = $this->teacher();
        $author = ContentAuthor::create(['slug' => 'old', 'name' => 'Original']);
        $method = new \ReflectionMethod(LegacyArticleImport::class, 'targetHash');
        $import = app(LegacyArticleImport::class);
        $legacy = clone $author->fresh();
        $attributes = $legacy->getAttributes();
        unset($attributes['user_id']);
        $legacy->setRawAttributes($attributes);
        $before = $method->invoke($import, $legacy);
        $this->assertSame($before, $method->invoke($import, $author->fresh()));
        $author->forceFill(['user_id' => $user->id])->save();
        $this->assertNotSame($before, $method->invoke($import, $author->fresh()));
        $author->forceFill(['user_id' => null, 'name' => 'Normalized'])->save();
        $this->assertNotSame($before, $method->invoke($import, $author->fresh()));
    }

    public function test_profile_dirty_binding_uses_all_editable_fields_and_refreshes_saved_state(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $teacher = $this->teacher();
        $this->actingAs($teacher, 'web');
        $page = Livewire::test(StaffProfile::class)
            ->assertSet('savedData', ['name' => $teacher->name, 'first_name' => 'Демо', 'last_name' => 'Преподаватель'])
            ->assertSeeHtml('x-data="adminFieldSaving(')->assertSeeHtml('wire:ignore.self')
            ->assertDontSee('setUpUnsavedDataChangesAlert', false)
            ->assertSeeHtml('<fieldset x-bind:disabled="!ordinaryDirty()"')
            ->assertSeeHtml('x-show="ordinaryDirty()"');
        $this->assertSame(['name', 'first_name', 'last_name'], $page->instance()->savingConfig()['manual']);
        $this->assertSame(3, substr_count($page->html(), 'data-ui="field-saving.hint"'));
        $dom = new \DOMDocument;
        @$dom->loadHTML($page->html());
        $save = (new \DOMXPath($dom))->query('//*[@data-ui="admin.profile.save"]')->item(0);
        $this->assertSame('fieldset', $save->parentNode->nodeName);
        $this->assertSame('!ordinaryDirty()', $save->parentNode->getAttribute('x-bind:disabled'));
        $this->assertFalse($save->hasAttribute('x-bind:disabled'), 'Livewire owns the submit button disabled state; the fieldset owns the dirty state.');
        foreach (['first_name' => 'Анна', 'last_name' => 'Иванова'] as $field => $value) {
            $original = $page->get('savedData');
            $page->set('data.'.$field, $value)->assertSet('savedData', $original);
            $this->assertNotSame($page->get('savedData'), $page->get('data'));
            $page->set('data.'.$field, $original[$field]);
            $this->assertSame($page->get('savedData'), $page->get('data'));
            $page->set('data.'.$field, $value)->call('save')->assertHasNoFormErrors();
            $this->assertSame($page->get('savedData'), $page->get('data'));
            $this->assertSame($value, $page->get('savedData')[$field]);
        }
        $this->assertSame($teacher->name, $teacher->fresh()->name);
        $manager = User::factory()->create(['role' => 'manager']);
        $this->actingAs($manager, 'web');
        $page = Livewire::test(StaffProfile::class)->assertSet('savedData', ['name' => $manager->name]);
        $page->set('data.name', 'Changed')->call('save')->assertHasNoFormErrors()->assertSet('savedData', ['name' => 'Changed']);
        $this->assertSame($page->get('savedData'), $page->get('data'));
    }

    public function test_self_profile_snapshot_cas_rejects_stale_form_without_replacing_its_draft(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $teacher = $this->teacher();
        $this->actingAs($teacher, 'web');
        $page = Livewire::test(StaffProfile::class);
        $opening = $page->get('savingOpening');
        $snapshot = $page->get('expectedSnapshot');
        User::query()->whereKey($teacher->id)->update(['first_name' => 'Concurrent']);
        $page->set('data.name', 'Unsaved draft')->call('save')
            ->assertReturned(['ok' => false, 'terminal' => true, 'message' => 'Профиль изменился. Откройте страницу заново.'])
            ->assertSet('data.name', 'Unsaved draft')->assertSet('expectedSnapshot', $snapshot)
            ->assertSet('savingOpening', $opening);
        $this->assertSame('Отдельное имя аккаунта', $teacher->fresh()->name);
        $this->assertDatabaseCount('admin_user_events', 0);
    }

    public function test_normalized_self_profile_noop_skips_user_author_and_audit_writes(): void
    {
        $teacher = $this->teacher();
        $author = $this->author($teacher);
        $snapshot = $teacher->only(['name', 'first_name', 'last_name']);
        $authorBefore = $author->fresh()->getAttributes();
        DB::enableQueryLog();
        app(AdminStaffProfile::class)->update($teacher, ['name' => ' '.$teacher->name.' ', 'first_name' => ' Демо ', 'last_name' => 'Преподаватель'], $snapshot);
        $writes = collect(DB::getQueryLog())->filter(fn ($query) => preg_match('/^\s*(update|insert|delete)\b/i', $query['query']));
        DB::disableQueryLog();
        $this->assertCount(0, $writes);
        $this->assertSame($authorBefore, $author->fresh()->getAttributes());
        $this->assertDatabaseCount('admin_user_events', 0);
        $this->assertDatabaseCount('article_audit_events', 1);
    }

    public function test_audit_failure_rolls_back_profile_and_public_snapshot(): void
    {
        $user = $this->teacher();
        $author = $this->author($user);
        DB::statement("CREATE TRIGGER reject_profile_audit BEFORE INSERT ON admin_user_events BEGIN SELECT RAISE(ABORT, 'audit unavailable'); END");
        foreach (['self', 'admin'] as $path) {
            try {
                if ($path === 'self') {
                    app(AdminStaffProfile::class)->update($user, ['name' => 'Changed', 'first_name' => 'Changed']);
                } else {
                    app(AdminUserManagement::class)->mutate(auth('web')->user(), $user->id, 'profile', ['revision' => AdminUserManagement::revisionFor($user->fresh(), 0), 'first_name' => 'Changed']);
                }
                $this->fail('Audit failure must roll back all writes');
            } catch (QueryException) {
                $this->assertSame('Демо', $user->fresh()->first_name);
                $this->assertSame('Отдельное имя аккаунта', $user->fresh()->name);
                $this->assertSame('Демо Автор 1', $author->fresh()->name);
                $this->assertSame(1, DB::table('article_audit_events')->count());
                $this->assertSame(0, DB::table('user_profiles')->count());
            }
        }
    }

    public function test_account_name_clear_saves_without_changing_historical_author(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $teacher = $this->teacher();
        $author = $this->author($teacher);
        $before = $author->getAttributes();
        $this->actingAs($teacher, 'web');
        $page = Livewire::test(StaffProfile::class);
        $page->set('data.first_name', null)->set('data.last_name', null)->call('save')->assertHasNoFormErrors();
        $this->assertSame($page->get('savedData'), $page->get('data'));
        $this->assertNull($teacher->fresh()->first_name);
        $this->assertNull($teacher->fresh()->last_name);
        $this->assertSame($before, $author->fresh()->getAttributes());
    }
}
