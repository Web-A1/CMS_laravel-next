<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\Reviews\Reviews;
use App\Models\Review;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class AdminReviewBulkPanelTest extends TestCase
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

    private function reviews(int $count = 3): array
    {
        $reviews = [];
        foreach (range(1, $count) as $n) {
            $review = Review::create(['source' => 'legacy_ocr', 'origin_type' => 'legacy_import',
                'display_name' => 'Автор '.$n, 'text' => 'Панель массового удаления '.$n, 'status' => 'published',
                'published_at' => now(), 'review_written_at' => '2026-01-15', 'show_on_home' => true]);
            $review->moderationEvents()->create(['event_type' => 'admin_update']);
            $path = 'review-screenshots/bulk-panel-'.$n.'.png';
            Storage::disk('public')->put($path, 'working image');
            $review->media()->create(['type' => 'legacy_screenshot', 'url' => '/storage/'.$path]);
            $reviews[] = $review;
        }

        return $reviews;
    }

    public function test_bulk_selection_confirmation_cancel_and_locked_snapshot_delete_only_the_shown_group(): void
    {
        [$a, $b, $other] = $this->reviews();
        $page = Livewire::test(Reviews::class)->filterTable('home', 'yes')
            ->mountTableBulkAction('deleteSelected', [$a, $b]);
        $this->assertTrue($page->instance()->getMountedAction()->canAccessSelectedRecords(), 'The browser must send the checkbox selection when mounting the action.');
        $this->assertSame([$a->id, $b->id], array_column($page->get('bulkDeletionReviews'), 'id'));
        $this->assertStringContainsString('ID: '.$a->id.', '.$b->id, (string) $page->instance()->getMountedAction()->getModalDescription());
        $page->setActionData(['confirmation' => '1'])->callMountedAction()->assertHasActionErrors(['confirmation']);
        $this->assertDatabaseCount('reviews', 3);
        $page->unmountAction();
        $this->assertDatabaseCount('reviews', 3);
        $page->mountTableBulkAction('deleteSelected', [$a, $b])
            ->set('selectedTableRecords', [(string) $other->id])
            ->setActionData(['confirmation' => '2'])->callMountedAction()->assertHasNoActionErrors();
        $this->assertDatabaseCount('reviews', 1);
        $this->assertDatabaseHas('reviews', ['id' => $other->id]);
        Storage::disk('public')->assertMissing(['review-screenshots/bulk-panel-1.png', 'review-screenshots/bulk-panel-2.png']);
        Storage::disk('public')->assertExists('review-screenshots/bulk-panel-3.png');
        $this->assertSame('yes', $page->get('tableFilters')['home']['value']);
        $page->assertCanSeeTableRecords([$other])->assertNotified('Удалено отзывов: 2');
        $this->assertSame([], $page->get('selectedTableRecords'));
    }

    public function test_review_changed_after_confirmation_opening_blocks_the_whole_group(): void
    {
        [$a, $b] = $this->reviews();
        $page = Livewire::test(Reviews::class)->mountTableBulkAction('deleteSelected', [$a, $b]);
        $b->moderationEvents()->create(['event_type' => 'admin_update']);
        $page->setActionData(['confirmation' => '2'])->callMountedAction()->assertNotified('Отзывы не удалены');
        $this->assertDatabaseCount('reviews', 3);
        $this->assertDatabaseCount('admin_operation_events', 0);
        $this->assertDatabaseCount('review_image_deletions', 0);
        $this->assertCount(3, Storage::disk('public')->allFiles());
    }

    public function test_select_all_is_limited_to_current_page_and_missing_selection_is_rejected(): void
    {
        $reviews = $this->reviews(21);
        $page = Livewire::test(Reviews::class);
        $this->assertTrue($page->instance()->getTable()->selectsCurrentPageOnly());
        $this->assertFalse($page->instance()->getTable()->canTrackDeselectedRecords());
        $this->assertSame(100, $page->instance()->getTable()->getMaxSelectableRecords());
        $visible = $page->instance()->getTableRecords()->pluck('id')->all();
        $outside = collect($reviews)->first(fn ($r) => ! in_array($r->id, $visible, true));
        $page->mountTableBulkAction('deleteSelected', [$outside])->assertHasErrors(['selection']);
        $this->assertDatabaseCount('reviews', 21);
        $page->unmountAction()->mountTableBulkAction('deleteSelected', [999999])->assertHasErrors(['selection']);
        $this->assertDatabaseCount('reviews', 21);
    }

    public function test_revoked_permissions_after_opening_confirmation_prevent_bulk_delete(): void
    {
        [$a, $b] = $this->reviews();
        $page = Livewire::test(Reviews::class)->mountTableBulkAction('deleteSelected', [$a, $b])->setActionData(['confirmation' => '2']);
        User::whereKey(auth('web')->id())->update(['role' => 'admin_limited']);
        $page->callMountedAction()->assertForbidden();
        $this->assertDatabaseCount('reviews', 3);
        $this->assertDatabaseCount('admin_operation_events', 0);
        $this->assertCount(3, Storage::disk('public')->allFiles());
    }
}
