<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\Reviews\CreateReview;
use App\Filament\Pages\Reviews\EditReview;
use App\Filament\Pages\UserDetail;
use App\Models\User;
use App\Support\Admin\ReviewsPanel;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminBreadcrumbsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create(['role' => 'admin_full']), 'web');
    }

    public function test_user_breadcrumb_preserves_list_context_and_marks_only_current_page(): void
    {
        $target = User::factory()->create();
        $context = ['q' => 'Иван & Анна', 'scope' => 'staff', 'page' => 3];
        $page = Livewire::withQueryParams($context)->test(UserDetail::class, ['userId' => (string) $target->id]);
        $page->assertDontSee('К сотрудникам')->assertDontSee('К пользователям');
        $dom = $this->breadcrumbs($page->html());

        $link = $dom->query('//nav[contains(@class, "fi-breadcrumbs")]//a')->item(0);
        $this->assertSame('/admin/users?'.http_build_query($context), $link->getAttribute('href'));
        $this->assertSame('Сотрудники', trim($link->textContent));
        $this->assertSame(0, $dom->query('//nav[contains(@class, "fi-breadcrumbs")]//a[@aria-current]')->length);
        $this->assertSame('Карточка пользователя', trim($dom->query('//nav[contains(@class, "fi-breadcrumbs")]//span[@aria-current="page"]')->item(0)->textContent));
    }

    public function test_user_breadcrumb_keeps_existing_bounds_for_untrusted_list_fields(): void
    {
        $target = User::factory()->create();
        $page = Livewire::withQueryParams(['q' => str_repeat('я', 300), 'scope' => 'unknown', 'page' => 1000001])
            ->test(UserDetail::class, ['userId' => (string) $target->id]);
        $link = $this->breadcrumbs($page->html())->query('//nav[contains(@class, "fi-breadcrumbs")]//a')->item(0);

        $this->assertSame('/admin/users?'.http_build_query(['q' => str_repeat('я', 255), 'scope' => 'users', 'page' => 1]), $link->getAttribute('href'));
        $this->assertSame('Пользователи', trim($link->textContent));
    }

    public function test_review_breadcrumb_uses_safe_return_target_and_has_no_duplicate_back_action(): void
    {
        $returnTo = '/admin/reviews?'.http_build_query(['search' => 'Имя & курс', 'page' => 2, 'perPage' => 20]);
        $new = Livewire::withQueryParams(['returnTo' => $returnTo])->test(CreateReview::class);
        $new->assertDontSee('К списку')->assertSee('Новый отзыв');
        $link = $this->breadcrumbs($new->html())->query('//nav[contains(@class, "fi-breadcrumbs")]//a')->item(0);
        $this->assertSame($returnTo, $link->getAttribute('href'));

        $review = app(ReviewsPanel::class)->execute('create', null, [
            'displayName' => 'Автор', 'isAnonymous' => false, 'text' => 'Отзыв для проверки перехода к списку.',
            'reviewWrittenAt' => now()->toDateString(), 'scope' => 'all', 'subjectId' => null, 'courseId' => null,
        ], 'breadcrumb-review');
        $edit = Livewire::withQueryParams(['returnTo' => 'https://example.invalid/admin/reviews'])
            ->test(EditReview::class, ['reviewId' => (string) $review['id']]);
        $edit->assertDontSee('К списку')->assertSee('Отзыв № '.$review['id']);
        $link = $this->breadcrumbs($edit->html())->query('//nav[contains(@class, "fi-breadcrumbs")]//a')->item(0);
        $this->assertSame('/admin/reviews', $link->getAttribute('href'));
    }

    private function breadcrumbs(string $html): \DOMXPath
    {
        $document = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new \DOMXPath($document);
        $this->assertSame(1, $xpath->query('//header//nav[contains(@class, "fi-breadcrumbs")]')->length);

        return $xpath;
    }
}
