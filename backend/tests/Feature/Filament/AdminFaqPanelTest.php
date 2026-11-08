<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\Operations\Faqs;
use App\Models\AdminOperationEvent;
use App\Models\ContentEntry;
use App\Models\Subject;
use App\Models\User;
use App\Services\Faq\AdminFaqService;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class AdminFaqPanelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create(['role' => 'admin_full']), 'web');
    }

    private function data(): array
    {
        return ['title' => 'Вопрос FAQ?', 'body' => "Ответ\n\nВторой абзац", 'show_on_home' => true, 'sort_order' => 4];
    }

    public function test_admin_crud_and_publication_actions(): void
    {
        $page = Livewire::test(Faqs::class)->callAction(TestAction::make('create')->table(), data: $this->data())->assertHasNoActionErrors();
        $entry = ContentEntry::query()->faq()->firstOrFail();
        $this->assertSame('draft', $entry->status);
        $page->callAction(TestAction::make('publish')->table($entry))->assertHasNoActionErrors();
        $this->assertSame('published', $entry->fresh()->status);
        $page->callAction(TestAction::make('edit')->table($entry), data: [...$this->data(), 'title' => 'Изменённый вопрос', 'show_on_home' => false])->assertHasNoActionErrors();
        $this->assertSame('Изменённый вопрос', $entry->fresh()->title);
        $page->callAction(TestAction::make('hide')->table($entry))->assertHasNoActionErrors();
        $this->assertSame('hidden', $entry->fresh()->status);
        $page->mountAction(TestAction::make('delete')->table($entry))->assertActionMounted(TestAction::make('delete')->table($entry))->assertSet('entryId', $entry->id);
        $this->assertStringContainsString('Восстановить их нельзя', $page->instance()->getMountedAction()->getModalDescription());
        $this->assertNotNull($entry->fresh());
        $page->callMountedAction()->assertHasNoActionErrors();
        $this->assertDatabaseMissing('content_entries', ['id' => $entry->id]);
    }

    public function test_subject_selection_reopen_clear_and_invalid_selection_preserve_form(): void
    {
        $subject = Subject::create(['slug' => 'science', 'title' => 'Science', 'is_active' => true]);
        $page = Livewire::test(Faqs::class)->callAction(TestAction::make('create')->table(), data: [...$this->data(), 'subject_id' => (string) $subject->id])->assertHasNoActionErrors();
        $entry = ContentEntry::query()->faq()->sole();
        $this->assertSame($subject->id, $entry->subject_id);
        $page->mountAction(TestAction::make('edit')->table($entry))->assertActionDataSet(['subject_id' => $subject->id]);
        $page->setActionData([...$this->data(), 'subject_id' => null])->callMountedAction()->assertHasNoActionErrors();
        $this->assertNull($entry->fresh()->subject_id);
        $page->mountAction(TestAction::make('edit')->table($entry))->setActionData([...$this->data(), 'title' => 'Uncommitted title', 'subject_id' => 99999])->callMountedAction()->assertHasErrors();
        $this->assertSame('Вопрос FAQ?', $entry->fresh()->title);
        $page->assertActionDataSet(['title' => 'Uncommitted title']);
    }

    public function test_delete_uses_locked_revision_and_rejects_a_changed_question(): void
    {
        $entry = app(AdminFaqService::class)->execute(auth('web')->user(), 'create', $this->data(), (string) Str::uuid());
        $page = Livewire::test(Faqs::class)->mountAction(TestAction::make('delete')->table($entry));
        app(AdminFaqService::class)->execute(auth('web')->user(), 'update', [...$this->data(), 'title' => 'Новая редакция'], (string) Str::uuid(), $entry->id, $entry->revision);
        $page->callMountedAction()->assertNotified('Изменение не сохранено');
        $this->assertSame('Новая редакция', $entry->fresh()->title);
    }

    public function test_unknown_fields_and_plaintext_html_are_not_executed(): void
    {
        $page = Livewire::test(Faqs::class)->mountAction(TestAction::make('create')->table());
        $page->setActionData([...$this->data(), 'status' => 'published'])->callMountedAction()->assertHasErrors();
        $this->assertDatabaseCount('content_entries', 0);
        $page->unmountAction()->callAction(TestAction::make('create')->table(), data: [...$this->data(), 'title' => '<script>alert(1)</script>']);
        $page->assertSeeHtml('&lt;script&gt;alert(1)&lt;/script&gt;');
        $page->assertDontSeeHtml('<script>alert(1)</script>');
    }

    public function test_access_is_rechecked_when_role_is_revoked_after_mount(): void
    {
        $page = Livewire::test(Faqs::class)->mountAction(TestAction::make('create')->table())->setActionData($this->data());
        User::query()->whereKey(auth('web')->id())->update(['role' => 'admin_limited']);
        $page->callMountedAction()->assertForbidden();
        $this->assertDatabaseCount('content_entries', 0);
    }

    public function test_limited_admin_cannot_open_faq(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin_limited']), 'web');
        Livewire::test(Faqs::class)->assertForbidden();
    }

    public function test_parameter_ack_preserves_invalid_manual_draft_and_modal_until_native_submit(): void
    {
        $entry = app(AdminFaqService::class)->execute(auth('web')->user(), 'create', $this->data(), (string) Str::uuid());
        $page = Livewire::test(Faqs::class)->mountAction(TestAction::make('edit')->table($entry));
        $opening = $page->get('faqOpening');
        $key = $page->get('operationKey');
        $page->setActionData([...$this->data(), 'title' => '', 'body' => 'Несохранённый ответ', 'sort_order' => -1])
            ->call('saveFaqParameters', ['show_on_home' => false], $entry->revision, $opening)
            ->assertReturned(['ok' => true, 'revision' => 2, 'fields' => ['show_on_home' => false]])
            ->assertActionMounted(TestAction::make('edit')->table($entry))
            ->assertActionDataSet(['title' => '', 'body' => 'Несохранённый ответ', 'sort_order' => -1]);
        $this->assertNotSame($key, $page->get('operationKey'));
        $this->assertSame('Вопрос FAQ?', $entry->fresh()->title);
        $page->call('saveFaqParameters', ['subject_id' => null], 2, $opening)->assertSet('revision', 2);
        $this->assertSame(2, AdminOperationEvent::count());
        $page->setActionData([...$this->data(), 'show_on_home' => false, 'title' => 'Сохранённый вопрос'])
            ->callMountedAction()->assertHasNoActionErrors()->assertReturned(['ok' => true]);
        $this->assertSame('Сохранённый вопрос', $entry->fresh()->title);
        $this->assertSame(3, $entry->fresh()->revision);
    }

    public function test_partial_validation_conflict_and_reopening_do_not_replace_draft(): void
    {
        $entry = app(AdminFaqService::class)->execute(auth('web')->user(), 'create', $this->data(), (string) Str::uuid());
        $page = Livewire::test(Faqs::class)->mountAction(TestAction::make('edit')->table($entry));
        $opening = $page->get('faqOpening');
        $key = $page->get('operationKey');
        $page->setActionData([...$this->data(), 'body' => 'Черновик'])
            ->call('saveFaqParameters', ['subject_id' => 999999], 1, $opening)
            ->assertHasErrors(['mountedActions.0.data.subject_id'])
            ->assertActionDataSet(['body' => 'Черновик'])->assertSet('operationKey', $key);
        $page->call('saveFaqParameters', ['show_on_home' => false], 0, $opening)
            ->assertActionDataSet(['body' => 'Черновик'])->assertSet('revision', 1);
        $this->assertSame(1, $entry->fresh()->revision);
        $page->unmountAction()->mountAction(TestAction::make('edit')->table($entry));
        $this->assertNotSame($opening, $page->get('faqOpening'));
        $page->call('saveFaqParameters', ['show_on_home' => false], 1, $opening)->assertStatus(409);
    }

    public function test_create_and_revoked_permissions_cannot_use_parameter_adapter(): void
    {
        $page = Livewire::test(Faqs::class)->mountAction(TestAction::make('create')->table());
        $page->call('saveFaqParameters', ['show_on_home' => true], 0, $page->get('faqOpening'))->assertStatus(409);
        $this->assertDatabaseCount('content_entries', 0);
        $entry = app(AdminFaqService::class)->execute(auth('web')->user(), 'create', $this->data(), (string) Str::uuid());
        $page = Livewire::test(Faqs::class)->mountAction(TestAction::make('edit')->table($entry));
        User::query()->whereKey(auth('web')->id())->update(['role' => 'admin_limited']);
        $page->call('saveFaqParameters', ['show_on_home' => false], 1, $page->get('faqOpening'))->assertForbidden();
        $this->assertSame(1, $entry->fresh()->revision);
    }
}
