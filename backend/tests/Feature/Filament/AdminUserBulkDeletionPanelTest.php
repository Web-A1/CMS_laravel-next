<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\Users;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class AdminUserBulkDeletionPanelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create(['role' => 'admin_full']), 'web');
        Storage::fake('public');
        Storage::fake('local');
    }

    private function students(int $count = 3): array
    {
        return User::factory()->count($count)->create(['role' => 'user_with_purchase', 'name' => 'Bulk panel learner'])->all();
    }

    public function test_confirm_cancel_and_locked_selection_preserve_list_conditions(): void
    {
        [$a, $b, $other] = $this->students();
        $page = Livewire::test(Users::class)->filterTable('name', ['value' => 'Bulk panel'])
            ->sortTable('id', 'asc')->mountTableBulkAction('deleteSelected', [$a->id, $b->id]);
        $this->assertTrue($page->instance()->getMountedAction()->canAccessSelectedRecords());
        $this->assertSame([$a->id, $b->id], $page->get('bulkDeletionPreview')['ids']);
        $content = $page->instance()->getMountedAction()->getModalContent()->render();
        $this->assertStringContainsString('ID '.$a->id, $content);
        $this->assertStringContainsString($a->email, $content);
        $this->assertStringContainsString('Журналы действий сохранятся', $content);
        $page->setActionData(['confirmation' => '1'])->callMountedAction()->assertHasActionErrors(['confirmation']);
        $this->assertDatabaseCount('users', 4);
        $page->unmountAction();
        $this->assertDatabaseCount('users', 4);
        $page->mountTableBulkAction('deleteSelected', [$a->id, $b->id])
            ->set('selectedTableRecords', [(string) $other->id])
            ->setActionData(['confirmation' => '2'])->callMountedAction()->assertHasNoActionErrors()
            ->assertNotified('Удалено профилей: 2');
        $this->assertDatabaseMissing('users', ['id' => $a->id]);
        $this->assertDatabaseMissing('users', ['id' => $b->id]);
        $this->assertDatabaseHas('users', ['id' => $other->id]);
        $this->assertSame([], $page->get('selectedTableRecords'));
        $this->assertSame('Bulk panel', $page->get('tableFilters')['name']['value']);
        $this->assertSame('id:asc', $page->get('tableSort'));
    }

    public function test_only_current_page_can_be_selected(): void
    {
        $students = $this->students(21);
        $page = Livewire::test(Users::class)->sortTable('id', 'asc');
        $table = $page->instance()->getTable();
        $this->assertTrue($table->selectsCurrentPageOnly());
        $this->assertFalse($table->canTrackDeselectedRecords());
        $this->assertSame(100, $table->getMaxSelectableRecords());
        $visible = $page->instance()->getTableRecords()->pluck('id')->all();
        $outside = collect($students)->first(fn (User $user): bool => ! in_array($user->id, $visible, true));
        $page->mountTableBulkAction('deleteSelected', [$outside->id])->assertHasErrors(['selection']);
        $this->assertDatabaseCount('users', 22);
    }

    public function test_changed_profile_keeps_entire_batch(): void
    {
        [$a, $b] = $this->students();
        $page = Livewire::test(Users::class)->mountTableBulkAction('deleteSelected', [$a->id, $b->id]);
        $b->update(['name' => 'Changed after preview']);
        $page->setActionData(['confirmation' => '2'])->callMountedAction()->assertNotified('Профили не удалены');
        $this->assertDatabaseCount('users', 4);
    }

    public function test_staff_scope_and_limited_admin_do_not_offer_bulk_deletion(): void
    {
        $this->students();
        $this->actingAs(User::factory()->create(['role' => 'admin_limited']), 'web');
        Livewire::test(Users::class)->assertTableBulkActionHidden('deleteSelected');
        $this->actingAs(User::factory()->create(['role' => 'admin_full']), 'web');
        Livewire::test(Users::class)->filterTable('scope', 'staff')->assertTableBulkActionHidden('deleteSelected');
    }

    public function test_permission_revoked_after_preview_blocks_execution(): void
    {
        [$a, $b] = $this->students();
        $page = Livewire::test(Users::class)->mountTableBulkAction('deleteSelected', [$a->id, $b->id])
            ->setActionData(['confirmation' => '2']);
        User::whereKey(auth('web')->id())->update(['role' => 'admin_limited']);
        $page->callMountedAction();
        $this->assertDatabaseCount('users', 4);
        $this->assertDatabaseCount('admin_operation_events', 0);
    }
}
