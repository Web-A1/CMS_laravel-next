<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\Articles;
use App\Models\ArticleMediaAsset;
use App\Models\ContentAuthor;
use App\Models\ContentEntry;
use App\Models\Subject;
use App\Models\User;
use App\Support\Admin\ColumnQuery;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class AdminArticlesPanelTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        Artisan::call('migrate', ['--force' => true]);
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        Storage::fake('public');
        Storage::fake('local');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create(['role' => 'admin_full']), 'web');
    }

    private function entry(array $overrides = []): ContentEntry
    {
        return ContentEntry::create(array_replace([
            'content_type' => 'article', 'title' => 'Existing article', 'slug' => 'existing-article',
            'body' => 'Plain body', 'body_format' => 'text', 'status' => 'draft', 'revision' => 1,
        ], $overrides));
    }

    public function test_default_list_and_native_row_buttons_open_only_editor(): void
    {
        $entry = $this->entry();
        $page = Livewire::test(Articles::class)->assertSet('editorOpen', false)
            ->assertSeeHtml('data-ui="article-list"')->assertDontSeeHtml('data-ui="article-editor"')
            ->assertActionHidden('author')->assertActionVisible('newEntry');
        $this->assertNull($page->instance()->getTable()->getRecordAction($entry));
        $this->assertStringStartsWith('/admin/articles/'.$entry->id.'?', $page->instance()->getTable()->getRecordUrl($entry));
        $this->assertSame([], $page->instance()->getTable()->getRecordActions());
        $page->call('loadTable');
        $page = Livewire::test(Articles::class, ['articleId' => (string) $entry->id])->assertSet('editorOpen', true)->assertSet('entryId', $entry->id)
            ->assertActionVisible('author')->assertActionHidden('newEntry')
            ->assertSeeHtml('data-ui="article-editor"')->assertDontSeeHtml('data-ui="article-list"')
            ->assertSee('Содержание')->assertSee('Публикация')->assertSee('Параметры статьи')->assertSee('SEO');
        $this->assertSame($page->get('savedDataHash'), md5((string) str(json_encode($page->get('data'), JSON_UNESCAPED_UNICODE))->replace('\\', '')), json_encode($page->get('data')));
        $page->mountAction('back')->assertRedirect('/admin/articles');
    }

    public function test_delete_confirmation_cancel_preserves_draft_and_confirm_returns_same_list_state(): void
    {
        $entry = $this->entry();
        Livewire::test(Articles::class)->assertActionHidden('delete');
        $page = Livewire::test(Articles::class, ['articleId' => 'create'])->assertActionHidden('delete');
        $newForm = $page->html();
        $this->assertStringNotContainsString('Удалить', substr($newForm, strpos($newForm, 'data-ui="article-editor.actions"')));
        $page = Livewire::test(Articles::class, ['articleId' => (string) $entry->id])->assertActionVisible('delete')->set('data.title', 'Unsaved title');
        $state = [$page->get('tableFilters'), $page->get('tableSort'), $page->get('paginators')];
        $page->mountAction('delete')->call('forceRender')->assertSee('Удалить статью №'.$entry->id.' навсегда?')->assertSeeHtml('role="alertdialog"')
            ->unmountAction()->assertSet('data.title', 'Unsaved title')->assertSet('editorOpen', true);
        $this->assertNotNull($entry->fresh());
        $page->mountAction('delete')->callMountedAction()->assertRedirect('/admin/articles');
        $this->assertDatabaseMissing('content_entries', ['id' => $entry->id]);
        $this->assertSame($state, [$page->get('tableFilters'), $page->get('tableSort'), $page->get('paginators')]);
    }

    public function test_clean_new_and_existing_form_return_without_confirmation_and_authors_do_not_dirty_form(): void
    {
        $page = Livewire::test(Articles::class, ['articleId' => 'create'])->assertSet('editorOpen', true);
        $page->mountAction('author')->unmountAction()->mountAction('back')->assertRedirect('/admin/articles');
        $filename = str_repeat('a', 64).'.jpg';
        $path = 'article-media/'.$filename;
        ArticleMediaAsset::create(['filename' => $filename, 'disk' => 'public', 'key' => $path,
            'sha256' => str_repeat('a', 64), 'bytes' => 12, 'mime' => 'image/jpeg',
            'origin' => ['kind' => 'test'], 'created_at' => now()]);
        $entry = $this->entry(['cover_path' => $path, 'published_at' => now()]);
        Livewire::test(Articles::class, ['articleId' => (string) $entry->id])->mountAction('author')->unmountAction()
            ->mountAction('back')->assertRedirect('/admin/articles');
        $this->assertSame('Plain body', $entry->fresh()->body);
    }

    public function test_dirty_cancel_and_author_modal_preserve_draft_then_discard_preserves_table_state(): void
    {
        $entry = $this->entry();
        $page = Livewire::test(Articles::class, ['articleId' => (string) $entry->id])
            ->set('tableFilters.title.value', 'Existing')->sortTable('id', 'asc')
            ->set('tableRecordsPerPage', 25)->call('gotoPage', 2)
            ->set('data.title', 'Unsaved title');
        $tableState = [$page->get('tableFilters'), $page->get('tableSort'), $page->get('paginators'), $page->get('tableRecordsPerPage')];
        $draft = $page->get('data');
        $page->mountAction('author')->unmountAction()->assertSet('data', $draft)
            ->mountAction('back')->assertSet('editorOpen', true)->assertActionMounted('back')
            ->call('forceRender')
            ->assertSee('Сохранить изменения перед выходом?')->assertSee('Сохранить и выйти')->assertSee('Выйти без сохранения')->assertSeeHtml('role="alertdialog"')
            ->unmountAction()->assertSet('data', $draft)->assertSet('editorOpen', true)
            ->mountAction('back')->callMountedAction(['discard' => true])->assertRedirect('/admin/articles');
        $this->assertSame($tableState, [$page->get('tableFilters'), $page->get('tableSort'), $page->get('paginators'), $page->get('tableRecordsPerPage')]);
        $this->assertSame('Existing article', $entry->fresh()->title);
        $this->assertSame(1, $entry->fresh()->revision);
    }

    public function test_clean_back_returns_without_confirmation_or_writing_article(): void
    {
        $entry = $this->entry();
        Livewire::test(Articles::class, ['articleId' => (string) $entry->id])->mountAction('back')->assertRedirect('/admin/articles');
        $this->assertSame(1, $entry->fresh()->revision);
        $this->assertSame('Existing article', $entry->fresh()->title);
    }

    public function test_dirty_back_saves_before_returning_to_same_list_state(): void
    {
        $entry = $this->entry();
        $page = Livewire::test(Articles::class, ['articleId' => (string) $entry->id])->set('tableFilters.title.value', 'Existing')->sortTable('id', 'asc')
            ->set('tableRecordsPerPage', 25)->call('gotoPage', 2)->set('data.title', 'Saved on exit');
        $tableState = [$page->get('tableFilters'), $page->get('tableSort'), $page->get('paginators'), $page->get('tableRecordsPerPage')];
        $page->mountAction('back')->callMountedAction()->assertHasNoErrors()->assertRedirect('/admin/articles');
        $this->assertSame('Saved on exit', $entry->fresh()->title);
        $this->assertSame(2, $entry->fresh()->revision);
        $this->assertSame($tableState, [$page->get('tableFilters'), $page->get('tableSort'), $page->get('paginators'), $page->get('tableRecordsPerPage')]);
    }

    public function test_new_draft_can_be_saved_on_exit_or_discarded_without_creating_article(): void
    {
        $page = Livewire::test(Articles::class, ['articleId' => 'create'])->set('data.sourceMode', true)
            ->set('data.title', 'Saved draft')->set('data.slug', 'saved-draft')->set('data.body', '<p>Draft body</p>')
            ->mountAction('back')->callMountedAction()->assertHasNoErrors()->assertRedirect('/admin/articles');
        $this->assertSame('Saved draft', ContentEntry::query()->article()->sole()->title);
        Livewire::test(Articles::class, ['articleId' => 'create'])->set('data.title', 'Discarded draft')->mountAction('back')
            ->callMountedAction(['discard' => true])->assertRedirect('/admin/articles');
        $this->assertSame(1, ContentEntry::query()->article()->count());
    }

    public function test_back_save_validation_and_revision_errors_keep_editor_input_visible(): void
    {
        $entry = $this->entry();
        $page = Livewire::test(Articles::class, ['articleId' => (string) $entry->id])->set('data.title', '')
            ->mountAction('back')->callMountedAction()->assertHasErrors(['data.title' => 'required'])
            ->assertActionNotMounted('back')->assertSet('editorOpen', true)->assertSet('data.title', '');
        $this->assertSame('Existing article', $entry->fresh()->title);
        $page->set('data.title', 'Local change');
        $entry->update(['revision' => 2, 'title' => 'Concurrent change']);
        $page->mountAction('back')->callMountedAction()->assertHasErrors(['data.title'])
            ->assertActionNotMounted('back')->assertSet('editorOpen', true)->assertSet('data.title', 'Local change');
        $this->assertSame('Concurrent change', $entry->fresh()->title);
    }

    public function test_save_stays_in_editor_validation_and_revision_conflict_preserve_input(): void
    {
        $page = Livewire::test(Articles::class, ['articleId' => 'create'])
            ->set('data.sourceMode', true)->set('data.title', 'New article')->set('data.slug', 'new-article')
            ->set('data.body', '<p>Article body</p>')->call('save')->assertHasNoErrors()->assertSet('editorOpen', true);
        $entry = ContentEntry::query()->article()->sole();
        $page->assertSet('entryId', $entry->id)->assertSet('revision', 1)
            ->set('data.title', '')->call('save')->assertHasErrors(['data.title' => 'required']);
        $this->assertSame('New article', $entry->fresh()->title);
        $page->set('data.title', 'Local change');
        $entry->update(['revision' => 2, 'title' => 'Concurrent change']);
        $page->call('save')->assertHasErrors(['data.title'])->assertSet('data.title', 'Local change')->assertSet('editorOpen', true);
        $this->assertSame('Concurrent change', $entry->fresh()->title);
    }

    public function test_header_keeps_saved_identity_and_status_until_successful_save(): void
    {
        $entry = $this->entry(['status' => 'hidden', 'tags' => ['existing tag']]);
        $page = Livewire::test(Articles::class, ['articleId' => (string) $entry->id]);
        $this->assertSame('Existing article', $page->instance()->getTitle());
        $this->assertSame('ID статьи: '.$entry->id, $page->instance()->getSubheading());
        $this->assertSame('Скрыто', $page->instance()->getSavedStatusLabel());

        $page->set('data.title', 'Unsaved title')->set('data.status', 'draft');
        $this->assertSame('Existing article', $page->instance()->getTitle());
        $this->assertSame('ID статьи: '.$entry->id, $page->instance()->getSubheading());
        $this->assertSame('Скрыто', $page->instance()->getSavedStatusLabel());

        $page->set('data.title', '')->call('save')->assertHasErrors(['data.title' => 'required']);
        $this->assertSame('Existing article', $page->instance()->getTitle());
        $page->set('data.title', 'Saved title')->call('save')->assertHasNoErrors();
        $this->assertSame([], $entry->fresh()->tags);
        $this->assertSame('Saved title', $page->instance()->getTitle());
        $this->assertSame('Черновик', $page->instance()->getSavedStatusLabel());
        $page->assertDontSee('Изменение статуса не сохранено.');

        $page = Livewire::test(Articles::class, ['articleId' => 'create']);
        $this->assertSame('Новая статья', $page->instance()->getTitle());
        $this->assertSame('Ещё не сохранена', $page->instance()->getSubheading());
        $this->assertSame('Черновик', $page->instance()->getSavedStatusLabel());
    }

    public function test_save_stays_at_form_end_and_secondary_actions_are_local(): void
    {
        $page = Livewire::test(Articles::class, ['articleId' => (string) $this->entry()->id]);
        $html = $page->html();
        $header = substr($html, strpos($html, 'data-ui="article-editor.header"'), strpos($html, 'data-ui="article-editor"') - strpos($html, 'data-ui="article-editor.header"'));
        $this->assertStringNotContainsString('<button', $header);
        $this->assertStringNotContainsString('Удалить', $header);
        $this->assertStringNotContainsString('Назад к списку', $html);
        $this->assertLessThan(strpos($html, 'data-ui="article-editor.actions"'), strpos($html, 'form.cover_alt'));
        $this->assertStringNotContainsString('form.tags', $html);
        $this->assertStringContainsString('Удалить', substr($html, strpos($html, 'data-ui="article-editor.actions"')));
        $page->assertSeeHtml('id="article-editor-form"')->assertSeeHtml('x-on:submit.prevent="save()"');
        $this->assertLessThan(strpos($html, 'form.subject_id'), strpos($html, 'form.status'));
        $this->assertLessThan(strpos($html, 'form.subject_id'), strpos($html, 'form.published_at'));
        $this->assertStringNotContainsString('Авторы', substr($html, 0, strpos($html, 'data-ui="article-editor"')));
        $page->assertSee('Без автора')->assertSee('Перетащите изображение')->assertSee('Удалить');
        $this->assertStringNotContainsString('Ещё', substr($html, 0, strpos($html, 'data-ui="article-editor"')));
        $page->assertSeeHtml('data-ui="article-editor.id"')->assertSeeHtml('data-ui="article-editor.saved-status"');
        $page->mountAction('author')->assertActionMounted('author')->unmountAction();
        $page->mountAction('delete')->assertActionMounted('delete');
    }

    public function test_title_and_status_filters_narrow_list_without_global_search(): void
    {
        $draft = $this->entry();
        $hidden = $this->entry(['slug' => 'hidden', 'title' => 'Hidden entry', 'status' => 'hidden']);
        Livewire::test(Articles::class)->assertCanSeeTableRecords([$draft, $hidden])
            ->set('tableFilters.title.value', 'Existing')->assertCanSeeTableRecords([$draft])->assertCanNotSeeTableRecords([$hidden])
            ->call('clearColumnFilters', 'title')->call('chooseColumnFilter', 'status', 'hidden')
            ->assertCanSeeTableRecords([$hidden])->assertCanNotSeeTableRecords([$draft]);
    }

    public function test_header_subject_and_author_choices_include_options_and_filter_records(): void
    {
        $subject = Subject::create(['slug' => 'histology', 'title' => 'Гистология', 'is_active' => true]);
        $author = ContentAuthor::create(['slug' => 'anna', 'name' => 'Анна']);
        $matching = $this->entry(['subject_id' => $subject->id, 'content_author_id' => $author->id]);
        $other = $this->entry(['slug' => 'other', 'title' => 'Other article']);
        $page = Livewire::test(Articles::class);
        $this->assertSame(['none' => 'Без предмета', $subject->id => 'Гистология'], $page->instance()->getTable()->getFilter('subject')->getOptions());
        $this->assertSame([$author->id => 'Анна'], $page->instance()->getTable()->getFilter('author')->getOptions());
        $page->call('chooseColumnFilter', 'subject.title', (string) $subject->id)
            ->assertCanSeeTableRecords([$matching])->assertCanNotSeeTableRecords([$other])
            ->call('clearColumnFilters', 'subject.title')->assertCanSeeTableRecords([$matching, $other])
            ->call('chooseColumnFilter', 'author.name', (string) $author->id)
            ->assertCanSeeTableRecords([$matching])->assertCanNotSeeTableRecords([$other]);
    }

    public function test_subject_header_none_choice_filters_null_and_combines_with_author_then_all_resets(): void
    {
        $subject = Subject::create(['slug' => 'histology', 'title' => 'Гистология', 'is_active' => true]);
        $author = ContentAuthor::create(['slug' => 'anna', 'name' => 'Анна']);
        $without = $this->entry(['content_author_id' => $author->id]);
        $otherWithout = $this->entry(['slug' => 'other-without']);
        $with = $this->entry(['slug' => 'with-subject', 'subject_id' => $subject->id, 'content_author_id' => $author->id]);
        $page = Livewire::test(Articles::class)->call('gotoPage', 2)
            ->call('chooseColumnFilter', 'subject.title', 'none')->assertSet('paginators.page', 1)
            ->assertCanSeeTableRecords([$without, $otherWithout])->assertCanNotSeeTableRecords([$with]);
        $this->assertTrue($page->instance()->columnFilterIsActive('subject.title'));
        $page->call('chooseColumnFilter', 'author.name', (string) $author->id)
            ->assertCanSeeTableRecords([$without])->assertCanNotSeeTableRecords([$otherWithout, $with])
            ->call('chooseColumnFilter', 'subject.title', null)
            ->assertCanSeeTableRecords([$without, $with])->assertCanNotSeeTableRecords([$otherWithout]);
        $this->assertFalse($page->instance()->columnFilterIsActive('subject.title'));
        $page->call('clearColumnFilters', 'author.name')->assertCanSeeTableRecords([$without, $otherWithout, $with]);
    }

    public function test_publication_filter_uses_displayed_moscow_day_boundaries(): void
    {
        $entries = collect([
            '2026-10-29 20:59:00', // Previous day, 23:59 Moscow.
            '2026-10-29 21:00:00', // Selected day, 00:00 Moscow.
            '2026-10-29 21:01:00',
            '2026-10-30 20:59:00', // Selected day, 23:59 Moscow.
            '2026-10-30 21:00:00', // Next day, 00:00 Moscow.
            '2026-10-30 21:01:00',
        ])->map(fn ($date, $index) => $this->entry(['slug' => 'boundary-'.$index, 'published_at' => $date]));

        Livewire::test(Articles::class)
            ->set('tableFilters.publication.from', '2026-10-30')
            ->set('tableFilters.publication.to', '2026-10-30')
            ->assertCanSeeTableRecords($entries->only([1, 2, 3]))
            ->assertCanNotSeeTableRecords($entries->only([0, 4, 5]));
    }

    public function test_column_date_filter_keeps_utc_boundaries_by_default(): void
    {
        $entries = collect([
            '2026-10-29 23:59:00', '2026-10-30 00:00:00', '2026-10-30 00:01:00',
            '2026-10-30 23:59:00', '2026-10-31 00:00:00', '2026-10-31 00:01:00',
        ])->map(fn ($date, $index) => $this->entry(['slug' => 'utc-boundary-'.$index, 'published_at' => $date]));
        $query = ContentEntry::query()->article();
        ColumnQuery::dates($query, 'published_at', ['from' => '2026-10-30', 'to' => '2026-10-30']);

        $this->assertSame($entries->only([1, 2, 3])->pluck('id')->values()->all(), $query->orderBy('id')->pluck('id')->all());
    }

    public function test_title_has_search_without_sort_and_editor_breadcrumb_reuses_guarded_back_action(): void
    {
        Filament::bootCurrentPanel();
        $entry = $this->entry();
        $page = Livewire::test(Articles::class);
        $this->assertFalse($page->instance()->getTable()->getColumn('title')->isSortable());
        $this->assertTrue($page->instance()->getTable()->getColumn('id')->isSortable());
        $this->assertTrue($page->instance()->getTable()->getColumn('published_at')->isSortable());
        $page->call('loadTable')->assertDontSeeHtml('data-ui="articles.sort.title"')->assertSeeHtml('data-ui="articles.search.title"');
        $page = Livewire::test(Articles::class, ['articleId' => 'create']);
        $this->assertArticleBreadcrumb($page->html(), 'Новая статья');
        $page = Livewire::test(Articles::class, ['articleId' => (string) $entry->id]);
        $this->assertArticleBreadcrumb($page->html(), $entry->title);
    }

    public function test_parameter_ack_rebases_only_confirmed_fields_and_preserves_manual_draft(): void
    {
        $entry = $this->entry();
        $page = Livewire::test(Articles::class, ['articleId' => (string) $entry->id]);
        $original = $page->get('savedForm');
        $page->set('data.title', 'Unfinished title')->set('data.body', '<p>Unfinished body</p>')
            ->set('data.status', 'hidden')->call('saveParameters', ['status'], 1)->assertReturned([
                'ok' => true, 'entryId' => $entry->id, 'revision' => 2, 'fields' => ['status' => 'hidden'],
            ])->assertSet('data.title', 'Unfinished title')->assertSet('data.body', '<p>Unfinished body</p>')
            ->assertSet('savedForm.title', $original['title'])->assertSet('savedForm.body', $original['body'])
            ->assertSet('savedForm.status', 'hidden')->assertSet('savedStatus', 'hidden');
        $this->assertSame('Existing article', $entry->fresh()->title);
        $this->assertSame('Plain body', $entry->fresh()->body);
        $this->assertNotSame($page->get('savedDataHash'), md5((string) str(json_encode($page->get('data'), JSON_UNESCAPED_UNICODE))->replace('\\', '')));
        $page->mountAction('back')->assertActionMounted('back');
    }

    public function test_parameter_only_save_becomes_clean_and_publication_date_uses_native_timezone_cast(): void
    {
        $entry = $this->entry();
        $page = Livewire::test(Articles::class, ['articleId' => (string) $entry->id])
            ->set('data.status', 'hidden')->call('saveParameters', ['status'], 1)
            ->mountAction('back')->assertRedirect('/admin/articles');
        $page = Livewire::test(Articles::class, ['articleId' => (string) $entry->id]);
        $baseline = $page->get('savedForm');
        $page->set('data.status', 'published')->call('saveParameters', ['status'], 2)
            ->assertSet('savedStatus', 'hidden')->assertSet('savedForm', $baseline)->assertHasErrors(['data.published_at']);
        $page->set('data.published_at', '2026-10-31 12:30:00')->call('saveParameters', ['status', 'published_at'], 2)->assertHasNoErrors();
        $this->assertSame('2026-10-31 09:30:00', $entry->fresh()->published_at->format('Y-m-d H:i:s'));
        Livewire::test(Articles::class, ['articleId' => (string) $entry->id])->assertSet('data.published_at', '2026-10-31 12:30');
    }

    public function test_cover_guard_reads_html_and_rich_editor_copy_without_replacing_dirty_body(): void
    {
        $hash = str_repeat('b', 64);
        $path = 'article-media/'.$hash.'.jpg';
        ArticleMediaAsset::create(['filename' => $hash.'.jpg', 'disk' => 'public', 'key' => $path,
            'sha256' => $hash, 'bytes' => 12, 'mime' => 'image/jpeg', 'origin' => ['kind' => 'test'], 'created_at' => now()]);
        $entry = $this->entry(['body_format' => 'html', 'body' => '<p>Saved body</p>', 'cover_path' => $path]);
        $states = ['<p>Unfinished</p><img src="/storage/'.$path.'">',
            ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Unfinished']]], ['type' => 'image', 'attrs' => ['id' => $path]]]]];
        foreach ($states as $body) {
            Livewire::test(Articles::class, ['articleId' => (string) $entry->id])
                ->set('data.body', $body)->set('data.cover_path', [])
                ->call('saveParameters', ['cover_path'], 1)->assertHasErrors(['data.cover_path'])->assertSet('data.body', $body)
                ->assertSet('savedForm.body', '<p>Saved body</p>')->assertSet('revision', 1);
            $this->assertSame($path, $entry->fresh()->cover_path);
            $this->assertDatabaseCount('article_media_deletions', 0);
        }
        Livewire::test(Articles::class, ['articleId' => (string) $entry->id])->set('data.body', $states[0])->set('data.cover_path', [])->call('save')->assertHasNoErrors();
        $this->assertNull($entry->fresh()->cover_path);
        $this->assertSame('active', ArticleMediaAsset::query()->sole()->status);
    }

    public function test_distinct_routes_reload_auth_scope_and_guarded_list_context(): void
    {
        $entry = $this->entry();
        $this->get('/admin/articles/'.$entry->id)->assertOk()->assertSee('ID статьи: '.$entry->id);
        $this->get('/admin/articles/create')->assertOk()->assertSee('Новая статья');
        foreach (['0', '01', 'invalid', '99999999999999999999999999', (string) ($entry->id + 100)] as $id) {
            $this->get('/admin/articles/'.$id)->assertNotFound();
        }
        $other = $this->entry(['content_type' => 'guide', 'slug' => 'other-type']);
        $this->get('/admin/articles/'.$other->id)->assertNotFound();
        $url = '/admin/articles?filters[title][value]=Existing&sort=id:asc&perPage=25&page=2';
        $page = Livewire::withQueryParams(['returnTo' => $url])->test(Articles::class, ['articleId' => (string) $entry->id]);
        $this->assertStringContainsString('page=2', $page->instance()->backUrl());
        $this->assertStringContainsString('perPage=25', $page->instance()->backUrl());
        foreach (['https://evil.test/admin/articles', '//evil.test/admin/articles', '/admin/users', "/admin/articles\n", '/admin/articles?perPage=all&sort=title:evil&page=-1&unknown=x'] as $url) {
            $page->set('returnTo', $url);
            $this->assertSame('/admin/articles', $page->instance()->backUrl());
        }
        auth('web')->logout();
        $this->get('/admin/articles/'.$entry->id)->assertRedirect();
    }

    public function test_author_parameters_preserve_invalid_manual_and_article_drafts(): void
    {
        $author = ContentAuthor::create(['slug' => 'existing-author', 'name' => 'Saved name', 'bio' => 'Saved biography']);
        $page = Livewire::test(Articles::class, ['articleId' => 'create'])->set('data.title', 'Article draft')
            ->mountAction('author', ['authorId' => $author->id]);
        $opening = $page->get('authorOpening');
        $hash = $page->get('authorHash');
        $page->setActionData(['id' => $author->id, 'name' => 'Draft name', 'slug' => '', 'bio' => 'Draft biography'])
            ->call('saveAuthorParameters', ['home_role_text' => 'New role'], $hash, $opening)
            ->assertHasNoErrors()->assertActionMounted('author')
            ->assertActionDataSet(['name' => 'Draft name', 'slug' => '', 'bio' => 'Draft biography'])
            ->assertSet('data.title', 'Article draft');
        $this->assertSame('Saved name', $author->fresh()->name);
        $this->assertSame('Saved name', $page->get('authorSaved')['name']);
        $this->assertSame('Saved biography', $page->get('authorSaved')['bio']);
        $this->assertSame('Saved biography', $author->fresh()->bio);
        $this->assertSame('existing-author', $author->fresh()->slug);
        $this->assertNotSame($hash, $page->get('authorHash'));
        $page->call('saveAuthorParameters', ['home_role_text' => 'New role'], $page->get('authorHash'), $opening)->assertHasNoErrors();
        $this->assertSame(1, DB::table('article_audit_events')->count());
        $this->assertDatabaseCount('content_entries', 0);
    }

    public function test_author_upload_parameter_saves_only_ready_photo_and_can_clear_it(): void
    {
        Queue::fake();
        $author = ContentAuthor::create(['slug' => 'photo-author', 'name' => 'Saved', 'bio' => 'Saved bio']);
        $filename = str_repeat('c', 64).'.jpg';
        $path = 'article-media/'.$filename;
        ArticleMediaAsset::create(['filename' => $filename, 'disk' => 'public', 'key' => $path, 'sha256' => str_repeat('c', 64),
            'bytes' => 12, 'mime' => 'image/jpeg', 'origin' => ['kind' => 'test'], 'created_at' => now(), 'lease_at' => now()]);
        $page = Livewire::test(Articles::class, ['articleId' => 'create'])->mountAction('author', ['authorId' => $author->id]);
        $page->set('mountedActions.0.data.bio', 'Unsaved biography')->set('mountedActions.0.data.photo_path', ['photo-key' => $path])
            ->call('saveAuthorParameters', ['photo_path' => ['photo-key' => $path]], $page->get('authorHash'), $page->get('authorOpening'))
            ->assertHasNoErrors()->assertActionDataSet(['bio' => 'Unsaved biography']);
        $this->assertSame($path, $author->fresh()->photo_path);
        $this->assertSame('Saved bio', $author->fresh()->bio);
        $this->assertNull(ArticleMediaAsset::sole()->lease_at);
        $page->set('mountedActions.0.data.photo_path', [])->call('saveAuthorParameters', ['photo_path' => []], $page->get('authorHash'), $page->get('authorOpening'))
            ->assertHasNoErrors()->assertActionMounted('author');
        $this->assertNull($author->fresh()->photo_path);
    }

    public function test_author_id_change_is_not_eager_and_native_remount_changes_authoritative_opening(): void
    {
        $first = ContentAuthor::create(['slug' => 'first-author', 'name' => 'First']);
        $second = ContentAuthor::create(['slug' => 'second-author', 'name' => 'Second']);
        $page = Livewire::test(Articles::class, ['articleId' => 'create'])->mountAction('author', ['authorId' => $first->id]);
        $opening = $page->get('authorOpening');
        $hash = $page->get('authorHash');
        $page->set('mountedActions.0.data.name', 'Draft')->set('mountedActions.0.data.id', $second->id)
            ->assertSet('authorId', $first->id)->assertSet('authorOpening', $opening)->assertActionDataSet(['name' => 'Draft']);
        $page->unmountAction()->mountAction('author', ['authorId' => $second->id])
            ->assertSet('authorId', $second->id)->assertActionDataSet(['name' => 'Second']);
        $this->assertNotSame($opening, $page->get('authorOpening'));
        $page->call('saveAuthorParameters', ['home_role_text' => null], $hash, $opening)->assertStatus(409);
        $this->assertSame('First', $first->fresh()->name);
        $this->assertSame('Second', $second->fresh()->name);
        $this->assertSame(0, DB::table('article_audit_events')->count());
    }

    public function test_author_create_and_reopen_keep_manual_name_without_account_field(): void
    {
        $page = Livewire::test(Articles::class, ['articleId' => 'create'])->mountAction('author')
            ->setActionData(['name' => 'Editorial name', 'slug' => 'new-author', 'bio' => 'Biography']);
        $this->assertDatabaseCount('content_authors', 0);
        $page->callMountedAction()->assertHasNoActionErrors()->assertReturned(['ok' => true]);
        $author = ContentAuthor::sole();
        $this->assertSame('Editorial name', $author->name);
        $this->assertNull($author->user_id);
        $page->mountAction('author', ['authorId' => $author->id])->assertActionDataSet(['name' => 'Editorial name', 'bio' => 'Biography']);
        $schema = $page->instance()->getSchema('mountedActionSchema0');
        $this->assertFalse($schema->getComponentByStatePath('name')->isReadOnly());
        $this->assertNull($schema->getComponentByStatePath('user_id'));
        $page->set('mountedActions.0.data.name', 'Changed editorial name')->callMountedAction()->assertHasNoActionErrors();
        $this->assertSame('Changed editorial name', $author->fresh()->name);
        $this->assertDatabaseCount('content_entries', 0);
    }

    public function test_raw_user_id_is_rejected_before_schema_strips_unknown_fields(): void
    {
        $author = ContentAuthor::create(['slug' => 'existing-author', 'name' => 'Saved']);
        foreach ([null, '', 123] as $value) {
            foreach ([null, $author->id] as $id) {
                $page = Livewire::test(Articles::class, ['articleId' => 'create'])->mountAction('author', ['authorId' => $id]);
                $page->set('mountedActions.0.data.name', 'Draft')->set('mountedActions.0.data.slug', 'new-author')
                    ->set('mountedActions.0.data.user_id', $value)->callMountedAction()
                    ->assertHasErrors(['mountedActions.0.data.user_id'])->assertActionMounted('author')
                    ->assertActionDataSet(['name' => 'Draft']);
                $this->assertSame('Saved', $author->fresh()->name);
                $this->assertDatabaseCount('content_authors', 1);
                $this->assertDatabaseCount('article_audit_events', 0);
            }
            $page = Livewire::test(Articles::class, ['articleId' => 'create'])->mountAction('author', ['authorId' => $author->id]);
            $page->call('saveAuthorParameters', ['user_id' => $value], $page->get('authorHash'), $page->get('authorOpening'))
                ->assertStatus(422);
        }
    }

    public function test_author_full_hash_conflict_preserves_modal_and_draft(): void
    {
        $author = ContentAuthor::create(['slug' => 'stale-author', 'name' => 'Saved', 'bio' => 'Saved bio']);
        $page = Livewire::test(Articles::class, ['articleId' => 'create'])->mountAction('author', ['authorId' => $author->id]);
        $author->update(['bio' => 'External edit']);
        $page->set('mountedActions.0.data.name', 'Draft')->callMountedAction()
            ->assertHasErrors(['mountedActions.0.data.expectedHash'])->assertActionMounted('author')
            ->assertActionDataSet(['name' => 'Draft']);
        $this->assertSame('External edit', $author->fresh()->bio);
        $this->assertSame('Saved', $author->fresh()->name);
    }

    private function assertArticleBreadcrumb(string $html, string $currentTitle): void
    {
        $document = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new \DOMXPath($document);
        $links = $xpath->query('//nav[contains(@class, "fi-breadcrumbs")]//a');
        $this->assertSame(1, $links->length);
        $this->assertSame('/admin/articles', $links->item(0)->getAttribute('href'));
        $this->assertSame('Статьи', trim($links->item(0)->textContent));
        $this->assertSame(0, $xpath->query('//nav[contains(@class, "fi-breadcrumbs")]//a[@aria-current]')->length);
        $this->assertSame($currentTitle, trim($xpath->query('//nav[contains(@class, "fi-breadcrumbs")]//span[@aria-current="page"]')->item(0)->textContent));
        $binding = $xpath->query('//*[@*[name()="x-on:click.capture"]]')->item(0)->getAttribute('x-on:click.capture');
        $this->assertSame('intercept($event)', $binding);
    }
}
