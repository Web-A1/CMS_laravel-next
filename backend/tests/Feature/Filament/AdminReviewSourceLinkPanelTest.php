<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\Reviews\CreateReview;
use App\Filament\Pages\Reviews\EditReview;
use App\Models\Review;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminReviewSourceLinkPanelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create(['role' => 'admin_full']), 'web');
    }

    private function input(array $extra = []): array
    {
        return array_replace(['displayName' => 'Мария', 'isAnonymous' => false, 'text' => 'Понятные объяснения и полезные примеры.',
            'reviewWrittenAt' => '2026-01-15', 'scope' => 'all', 'subjectId' => null, 'courseId' => null], $extra);
    }

    public function test_editor_creates_loads_replaces_and_clears_optional_source(): void
    {
        Livewire::test(CreateReview::class)->assertSee('Ссылка на источник')
            ->fillForm($this->input(['sourceUrl' => 'https://example.invalid/demo']))->call('save')->assertHasNoErrors();
        $review = Review::sole();
        $editor = Livewire::test(EditReview::class, ['reviewId' => (string) $review->id])
            ->assertSet('data.sourceUrl', 'https://example.invalid/demo')
            ->fillForm(['sourceUrl' => 'https://example.invalid/demo'])->call('save')->assertHasNoErrors()
            ->assertSet('data.sourceUrl', 'https://example.invalid/demo');
        $editor->fillForm(['sourceUrl' => null])->call('save')->assertHasNoErrors()->assertSet('data.sourceUrl', null);
        $this->assertNull($review->sources()->sole()->source_url);
        $editor->assertSee('Ссылка на источник');
    }

    public function test_editor_allows_empty_source_and_rejects_unsafe_url(): void
    {
        $editor = Livewire::test(CreateReview::class)->fillForm($this->input(['sourceUrl' => 'javascript:alert(1)']))
            ->call('save')->assertHasFormErrors(['sourceUrl']);
        $this->assertDatabaseCount('reviews', 0);
        $editor->fillForm(['sourceUrl' => null])->call('save')->assertHasNoErrors();
        $this->assertDatabaseCount('reviews', 1);
        $this->assertDatabaseCount('review_sources', 0);
    }
}
