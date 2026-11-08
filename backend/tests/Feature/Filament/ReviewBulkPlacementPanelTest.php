<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\Reviews\Reviews;
use App\Models\Review;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ReviewBulkPlacementPanelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create(['role' => 'admin_full']), 'web');
    }

    private function reviews(): array
    {
        return array_map(fn ($n) => Review::create(['display_name' => 'Автор '.$n, 'text' => 'Отзыв '.$n,
            'source' => 'legacy_ocr', 'origin_type' => 'legacy_import', 'status' => $n === 2 ? 'draft' : 'published',
            'review_written_at' => '2026-01-15', 'published_at' => now(), 'show_on_home' => false]), range(1, 3));
    }

    public function test_confirm_cancel_locked_selection_on_off_and_filter_preservation(): void
    {
        [$a, $b, $other] = $this->reviews();
        $page = Livewire::test(Reviews::class)->filterTable('home', 'no')->mountTableBulkAction('showOnHome', [$a, $b]);
        $this->assertTrue($page->instance()->getMountedAction()->canAccessSelectedRecords());
        $this->assertStringContainsString('ID: '.$a->id.', '.$b->id, (string) $page->instance()->getMountedAction()->getModalDescription());
        $page->unmountAction();
        $this->assertFalse($a->fresh()->show_on_home);
        $page->mountTableBulkAction('showOnHome', [$a, $b])->set('selectedTableRecords', [(string) $other->id])
            ->callMountedAction()->assertNotified('Показ на главной включён для отзывов: 2');
        $this->assertTrue($a->fresh()->show_on_home);
        $this->assertTrue($b->fresh()->show_on_home);
        $this->assertFalse($other->fresh()->show_on_home);
        $this->assertSame('draft', $b->fresh()->publication_status);
        $this->assertSame('no', $page->get('tableFilters')['home']['value']);
        $this->assertSame([], $page->get('selectedTableRecords'));
        $page->assertCanSeeTableRecords([$other])->assertCanNotSeeTableRecords([$a, $b]);
        $page->filterTable('home', 'yes')->mountTableBulkAction('hideFromHome', [$a, $b])->callMountedAction()
            ->assertNotified('Показ на главной отключён для отзывов: 2');
        $this->assertFalse($a->fresh()->show_on_home);
        $this->assertFalse($b->fresh()->show_on_home);
        $this->assertSame('published', $a->fresh()->publication_status);
    }

    public function test_stale_snapshot_rejects_the_whole_group(): void
    {
        [$a, $b] = $this->reviews();
        $page = Livewire::test(Reviews::class)->mountTableBulkAction('showOnHome', [$a, $b]);
        $b->moderationEvents()->create(['event_type' => 'admin_update']);
        $page->callMountedAction()->assertNotified('Изменения не сохранены');
        $this->assertFalse($a->fresh()->show_on_home);
        $this->assertFalse($b->fresh()->show_on_home);
        $this->assertDatabaseCount('admin_operation_events', 0);
    }

    public function test_revoked_permissions_after_confirmation_prevent_changes(): void
    {
        [$a, $b] = $this->reviews();
        $page = Livewire::test(Reviews::class)->mountTableBulkAction('showOnHome', [$a, $b]);
        User::whereKey(auth('web')->id())->update(['role' => 'admin_limited']);
        $page->callMountedAction()->assertForbidden();
        $this->assertFalse($a->fresh()->show_on_home);
        $this->assertFalse($b->fresh()->show_on_home);
    }
}
