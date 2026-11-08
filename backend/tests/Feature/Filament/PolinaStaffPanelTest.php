<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\Articles;
use App\Filament\Pages\Operations\Promos;
use App\Filament\Pages\Operations\Tasks;
use App\Filament\Pages\UserDetail;
use App\Models\AdminTask;
use App\Models\ContentEntry;
use App\Models\PromoCode;
use App\Models\User;
use App\Services\AdminOperationsService;
use App\Services\Articles\ArticleEditor;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\SupportDatabase;
use Tests\TestCase;

class PolinaStaffPanelTest extends TestCase
{
    use SupportDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create(['role' => 'polina', 'username' => 'polina', 'email' => null]), 'web');
    }

    public function test_articles_allow_edit_preview_author_and_publication_but_deny_delete_service_and_forged_action(): void
    {
        $entry = ContentEntry::create(['content_type' => 'article', 'title' => 'Article', 'slug' => 'polina-panel', 'body' => 'Body', 'body_format' => 'text', 'status' => 'draft', 'revision' => 1]);
        Livewire::test(Articles::class)->assertActionVisible('newEntry');
        $page = Livewire::test(Articles::class, ['articleId' => (string) $entry->id])
            ->assertActionVisible('author')->assertActionHidden('delete')->set('data.title', 'Polina edit')->call('save');
        $this->assertSame('Polina edit', $entry->fresh()->title);
        $page->call('previewDocument')->assertSuccessful();
        $author = app(ArticleEditor::class)->saveTaxonomy('author', null, ['name' => 'Staff author', 'slug' => 'polina-author']);
        $this->assertSame('Staff author', $author->name);
        $entry = app(ArticleEditor::class)->saveParameters($entry->id, ['status' => 'published', 'published_at' => now()->toISOString()], $entry->fresh()->revision);
        $this->assertSame('published', $entry->status);
        try {
            app(ArticleEditor::class)->delete($entry->id, $entry->revision);
            $this->fail('Delete service must forbid Polina.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->expectException(HttpException::class);
        $page->instance()->deleteAction()->getActionFunction()->call($page->instance());
    }

    public function test_user_with_assigned_manager_card_opens_without_assignment_or_payment_rights(): void
    {
        $manager = User::factory()->create(['role' => 'manager', 'name' => 'Assigned manager']);
        $student = User::factory()->create(['role' => 'user_no_purchase', 'manager_id' => $manager->id]);
        Livewire::test(UserDetail::class, ['userId' => (string) $student->id])
            ->assertSuccessful()->assertSee('Assigned manager')->call('saveManager')->assertForbidden();
        $this->assertSame($manager->id, $student->fresh()->manager_id);
    }

    public function test_task_assignment_completion_and_forged_mutations_obey_current_scope(): void
    {
        $polina = auth('web')->user();
        $admin = User::factory()->create(['role' => 'admin_full']);
        $this->actingAs($admin, 'web');
        Livewire::test(Tasks::class)->callAction(TestAction::make('create')->table(), data: [
            'title' => 'Assigned', 'description' => 'Description', 'assignee_id' => $polina->id, 'status' => 'in_progress',
        ])->assertHasNoErrors();
        $task = AdminTask::query()->sole();
        $this->assertSame($polina->id, $task->assignee_id);
        $other = app(AdminOperationsService::class)->tasks($admin, 'create', ['title' => 'Other', 'assignee_id' => $admin->id], key: 'panel-other')['data'];
        $this->actingAs($polina, 'web');
        $page = Livewire::test(Tasks::class)->assertCanSeeTableRecords([$task])->assertCanNotSeeTableRecords([$other])
            ->assertActionHidden(TestAction::make('create')->table())
            ->assertActionHidden(TestAction::make('edit')->table($task))
            ->assertActionHidden(TestAction::make('delete')->table($task))
            ->assertActionVisible(TestAction::make('complete')->table($task));
        $page->callAction(TestAction::make('complete')->table($task));
        $this->assertSame('done', $task->fresh()->status);
        $this->assertSame('Description', $task->fresh()->description);
        $page->assertActionHidden(TestAction::make('complete')->table($task));
    }

    public function test_promo_creation_is_visible_and_toggle_hidden(): void
    {
        Livewire::test(Promos::class)->callAction(TestAction::make('create')->table(), data: [
            'code' => 'POLINA-PANEL', 'discount_kind' => 'percent_bps', 'percent' => 10,
            'expires_at' => now()->addDay()->format('Y-m-d H:i:s'), 'activation_limit' => 2, 'per_user_limit' => 1,
        ])->assertHasNoErrors();
        $promo = PromoCode::query()->sole();
        Livewire::test(Promos::class)->assertActionVisible(TestAction::make('create')->table())
            ->assertActionHidden(TestAction::make('toggle')->table((string) $promo->id))
            ->assertActionVisible(TestAction::make('delete')->table((string) $promo->id))
            ->callAction(TestAction::make('delete')->table((string) $promo->id));
        $this->assertNull(PromoCode::find($promo->id));
        $this->assertNotNull(PromoCode::withTrashed()->find($promo->id)->deleted_at);
    }
}
