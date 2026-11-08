<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Services\Learning\LessonAssessmentManualReviewService;
use App\Support\Rbac;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Livewire\Attributes\Url;

final class AssessmentReviews extends Page
{
    use \App\Support\Admin\HasPlainColumnControls;

    public const TABLE_COLUMNS = ['queue' => [
        'student' => ['label' => 'Студент', 'search' => true],
        'context' => ['label' => 'Курс / урок', 'search' => true],
        'reviewed' => ['label' => 'Проверено', 'sort' => true],
        'status' => ['label' => 'Статус', 'options' => ['pending_review' => 'Ожидает проверки', 'finalized' => 'Проверена']],
    ]];
    protected static ?string $slug = 'assessment-reviews';
    protected static ?string $title = 'Проверка заданий';
    protected string $view = 'filament.pages.assessment-reviews';

    #[Url] public string $status = 'pending_review';
    #[Url] public string $search = '';
    #[Url] public int $page = 1;

    public static function canAccess(): bool
    {
        $actor = auth('web')->user();
        $actor = $actor instanceof User ? $actor->fresh() : null;

        return $actor instanceof User && $actor->canAccessPanel(Filament::getPanel('admin'))
            && in_array($actor->role, ['admin_full', 'manager', 'teacher'], true)
            && Rbac::hasPermission($actor, 'assessment-reviews.read');
    }

    public function mount(): void {
        abort_unless(static::canAccess(), 403);
        $this->columnFilters['queue']['status'] = array_key_exists('status', $this->columnFilters['queue'] ?? [])
            ? (string) $this->columnFilters['queue']['status']
            : ($this->status === 'all' ? '' : $this->status);
    }
    public function hydrate(): void { abort_unless(static::canAccess(), 403); }
    public function applyFilters(): void { abort_unless(static::canAccess(), 403); $this->page = 1; }
    public function goToPage(int $page): void { abort_unless(static::canAccess(), 403); $this->page = max(1, $page); }

    public function clearPreviousSearch(): void
    {
        $this->search = '';
        $this->updatedColumnFilters();
    }

    protected function getViewData(): array
    {
        abort_unless(static::canAccess(), 403);
        $this->validate(['status' => 'required|in:pending_review,finalized,all', 'search' => 'string|max:255', 'page' => 'integer|min:1']);
        $actor = auth('web')->user()->fresh();
        $reviews = app(LessonAssessmentManualReviewService::class);
        $status = $this->columnFilters['queue']['status'] ?? $this->status;
        $paginator = $reviews->list($actor, 20, $this->page, $status === '' ? 'all' : $status, trim($this->search), function ($query): void {
            $filters = $this->columnFilters['queue'] ?? [];
            if (filled($filters['student'] ?? null)) {
                $query->whereHas('user', fn ($users) => \App\Support\Admin\ColumnQuery::search($users, ['name', 'email'], $filters['student']));
            }
            if (filled($filters['context'] ?? null)) {
                $query->where(function ($query) use ($filters): void {
                    $query->whereHas('assessment.lesson', fn ($lessons) => \App\Support\Admin\ColumnQuery::search($lessons, ['title'], $filters['context']))
                        ->orWhereHas('assessment.lesson.course', fn ($courses) => \App\Support\Admin\ColumnQuery::search($courses, ['title'], $filters['context']));
                });
            }
            \App\Support\Admin\ColumnQuery::sort($query, $this->columnSorts['queue'] ?? null, [
                'submitted_at' => 'submitted_at',
                'reviewed' => "(SELECT COUNT(*) FROM lesson_assessment_manual_reviews WHERE lesson_assessment_attempt_id = lesson_assessment_attempts.id AND status = 'decided')",
            ], 'submitted_at:desc');
        });
        $rows = [];
        foreach ($paginator->items() as $attempt) {
            $reviews->assertActorCanAccessAttempt($actor, $attempt, 'assessment-reviews.read');
            $rows[] = $reviews->summaryPayload($attempt);
        }

        return ['rows' => $rows, 'currentPage' => $paginator->currentPage(), 'lastPage' => $paginator->lastPage(), 'total' => $paginator->total()];
    }
}
