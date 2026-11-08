<?php

namespace App\Filament\Pages;

use App\Exceptions\LessonAssessmentException;
use App\Http\Requests\FinalizeLessonAssessmentManualReviewRequest;
use App\Http\Requests\UpdateLessonAssessmentManualReviewRequest;
use App\Models\User;
use App\Services\Learning\LessonAssessmentManualReviewService;
use App\Support\ImpersonationContext;
use App\Support\Rbac;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

final class AssessmentReviewDetail extends Page
{
    protected static ?string $slug = 'assessment-reviews/{attempt}';

    protected static ?string $title = 'Проверка работы';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.assessment-review-detail';

    #[Locked]
    public int $attemptId;

    #[Locked]
    public int $reviewRevision = 0;

    #[Locked]
    public string $finalizationKey = '';

    #[Locked]
    public array $submissions = [];

    public array $drafts = [];

    #[Locked]
    public string $savingOpening = '';

    #[Locked]
    public array $savingBaseline = [];

    private bool $batchSaving = false;

    public function getBreadcrumbs(): array
    {
        return [AssessmentReviews::getUrl() => 'Проверка заданий', $this->getTitle()];
    }

    public static function canAccess(): bool
    {
        return AssessmentReviews::canAccess();
    }

    public function mount(string $attempt): void
    {
        abort_unless(preg_match('/\A[1-9][0-9]*\z/', $attempt) === 1, 404);
        $this->attemptId = (int) $attempt;
        $this->loadEditor();
    }

    public function hydrate(): void
    {
        $this->detail();
    }

    public function loadEditor(): void
    {
        $detail = $this->detail();
        $this->reviewRevision = $detail['attempt']['reviewRevision'];
        $this->finalizationKey = (string) Str::uuid();
        $this->drafts = $this->submissions = [];
        $this->savingBaseline = [];
        foreach ($detail['items'] as $item) {
            $this->startSubmission($item);
        }
        $this->savingOpening = (string) Str::uuid();
        $this->resetErrorBag();
    }

    public function editItem(int $reviewId): array
    {
        $this->writeActor();
        $this->resetErrorBag();
        $detail = $this->detail();
        $item = collect($detail['items'])->firstWhere('id', $reviewId);
        abort_unless(is_array($item), 404);
        $this->startSubmission($item);
        $this->reviewRevision = $detail['attempt']['reviewRevision'];
        $this->finalizationKey = (string) Str::uuid();

        return $this->itemAck($reviewId);
    }

    public function saveItem(int $reviewId): array
    {
        $actor = $this->writeActor();
        $this->detail();
        $this->resetErrorBag();
        abort_unless(isset($this->submissions[$reviewId], $this->drafts[$reviewId]), 404);
        $this->throttle('assessment-review-update', 120, $actor);
        $payload = $this->itemInput($reviewId);
        $intent = ['scoreBps' => $payload['scoreBps'], 'comment' => $payload['comment']];
        if (($this->submissions[$reviewId]['saved'] ?? false) && ($this->submissions[$reviewId]['accepted'] ?? null) !== $intent) {
            $this->submissions[$reviewId]['revision'] = $this->submissions[$reviewId]['ackRevision'];
            $this->submissions[$reviewId]['key'] = (string) Str::uuid();
            $this->submissions[$reviewId]['saved'] = false;
            $payload['revision'] = $this->submissions[$reviewId]['revision'];
        }
        try {
            $result = app(LessonAssessmentManualReviewService::class)->update($actor, $this->attemptId, $reviewId, $payload['revision'], $payload['scoreBps'], $payload['comment'], $this->submissions[$reviewId]['key']);
        } catch (LessonAssessmentException $exception) {
            if ($this->batchSaving) {
                throw $exception;
            }

            return $this->domainFailure($exception);
        }
        // Exact retries retain their original revision/key. Only a new intent
        // after a confirmed ACK starts a new submission.
        $this->submissions[$reviewId]['saved'] = true;
        $this->submissions[$reviewId]['accepted'] = $intent;
        $this->submissions[$reviewId]['ackRevision'] = $result['item']['revision'];
        $this->reviewRevision = $result['attempt']['reviewRevision'];
        if (! $this->batchSaving) {
            Notification::make()->title('Оценка сохранена')->success()->send();
        }

        return $this->itemAck($reviewId);
    }

    private function itemInput(int $reviewId): array
    {
        abort_unless(isset($this->submissions[$reviewId], $this->drafts[$reviewId]), 404);
        $draft = $this->drafts[$reviewId];
        try {
            validator(['draft' => $draft], [
                'draft' => 'required|array:scorePercent,comment',
                'draft.scorePercent' => ['required', 'regex:/\A[0-9]{1,3}([.,][0-9]{1,2})?\z/'],
            ])->validate();
            // Convert the displayed percentage into the existing integer basis-point contract.
            $parts = explode('.', str_replace(',', '.', (string) $draft['scorePercent']));
            $score = (int) $parts[0] * 100 + (int) str_pad($parts[1] ?? '', 2, '0');
            $comment = $draft['comment'] ?? null;
            if (is_string($comment) && trim($comment) === '') {
                $comment = null;
            }

            return validator([
                'revision' => $this->submissions[$reviewId]['revision'],
                'scoreBps' => $score, 'comment' => $comment,
            ], UpdateLessonAssessmentManualReviewRequest::payloadRules())->validate();
        } catch (ValidationException $exception) {
            $errors = [];
            foreach ($exception->errors() as $field => $messages) {
                $target = match ($field) {
                    'draft.scorePercent', 'scoreBps' => 'drafts.'.$reviewId.'.scorePercent',
                    'draft.comment', 'comment' => 'drafts.'.$reviewId.'.comment',
                    default => 'drafts.'.$reviewId,
                };
                $errors[$target] = $messages;
            }
            throw ValidationException::withMessages($errors);
        }
    }

    private function itemAck(int $reviewId): array
    {
        $fields = [];
        foreach ($this->drafts[$reviewId] as $field => $value) {
            $fields['drafts.'.$reviewId.'.'.$field] = $value;
        }
        $this->savingBaseline = array_replace($this->savingBaseline, $fields);

        return ['ok' => true, 'revision' => $this->reviewRevision, 'fields' => $fields];
    }

    private function dirtyItems(): array
    {
        $dirty = [];
        foreach (array_keys($this->submissions) as $id) {
            $draft = $this->drafts[$id] ?? null;
            if (! is_array($draft)) {
                $dirty[] = $id;

                continue;
            }
            foreach (['scorePercent', 'comment'] as $field) {
                if ((string) ($draft[$field] ?? '') !== (string) ($this->savingBaseline['drafts.'.$id.'.'.$field] ?? '')) {
                    $dirty[] = $id;
                    break;
                }
            }
        }

        return $dirty;
    }

    public function saveOrdinary(): array
    {
        $this->writeActor();
        $this->detail();
        $dirtyItems = $this->dirtyItems();
        foreach ($dirtyItems as $id) {
            $this->itemInput($id);
        }
        $before = [$this->submissions, $this->savingBaseline, $this->reviewRevision];
        $this->batchSaving = true;
        try {
            $fields = DB::transaction(function () use ($dirtyItems): array {
                $fields = [];
                foreach ($dirtyItems as $id) {
                    $fields = array_replace($fields, $this->saveItem($id)['fields']);
                }

                return $fields;
            });
        } catch (\Throwable $exception) {
            [$this->submissions, $this->savingBaseline, $this->reviewRevision] = $before;
            if ($exception instanceof LessonAssessmentException) {
                return $this->domainFailure($exception);
            }
            throw $exception;
        } finally {
            $this->batchSaving = false;
        }
        Notification::make()->title('Оценка сохранена')->success()->send();

        return ['ok' => true, 'revision' => $this->reviewRevision, 'fields' => $fields];
    }

    public function savingConfig(): array
    {
        return ['statePath' => '', 'baseline' => $this->savingBaseline, 'revision' => $this->reviewRevision,
            'opening' => $this->savingOpening, 'openingPath' => 'savingOpening', 'existing' => true, 'standalone' => true,
            'parameters' => [], 'manual' => array_keys($this->savingBaseline), 'submitMethod' => 'saveOrdinary',
            'submitMethods' => ['saveItem', 'finalize'], 'commandActionMethod' => 'editItem',
            'guardForm' => 'assessment-ordinary'];
    }

    public function finalize(): array
    {
        $actor = $this->writeActor();
        $this->detail();
        $this->resetErrorBag();
        $this->throttle('assessment-review-finalize', 30, $actor);
        if ($this->dirtyItems() !== []) {
            throw ValidationException::withMessages(['drafts' => 'Сначала сохраните оценки или обновите данные.']);
        }
        $payload = validator(['reviewRevision' => $this->reviewRevision], FinalizeLessonAssessmentManualReviewRequest::payloadRules())->validate();
        try {
            $result = app(LessonAssessmentManualReviewService::class)->finalize($actor, $this->attemptId, $payload['reviewRevision'], $this->finalizationKey);
        } catch (LessonAssessmentException $exception) {
            return $this->domainFailure($exception);
        }
        Notification::make()->title('Проверка завершена')->success()->send();

        return ['ok' => true, 'revision' => $result['attempt']['reviewRevision'], 'fields' => []];
    }

    private function startSubmission(array $item): void
    {
        $this->submissions[$item['id']] = ['revision' => $item['revision'], 'key' => (string) Str::uuid(), 'saved' => false];
        $this->drafts[$item['id']] = ['scorePercent' => $item['scoreBps'] === null ? '' : number_format($item['scoreBps'] / 100, 2, '.', ''), 'comment' => $item['comment'] ?? ''];
        foreach ($this->drafts[$item['id']] as $field => $value) {
            $this->savingBaseline['drafts.'.$item['id'].'.'.$field] = $value;
        }
    }

    private function detail(): array
    {
        abort_unless(self::canAccess(), 403);
        try {
            return app(LessonAssessmentManualReviewService::class)->detail(auth('web')->user()->fresh(), $this->attemptId);
        } catch (LessonAssessmentException $exception) {
            abort($exception->httpStatus, $exception->errorCode);
        }
    }

    private function writeActor(): User
    {
        abort_unless(self::canAccess(), 403);
        abort_if(ImpersonationContext::isActive(request()), 403);
        $actor = auth('web')->user()->fresh();
        abort_unless(Rbac::hasPermission($actor, 'assessment-reviews.review'), 403);

        return $actor;
    }

    private function throttle(string $operation, int $limit, User $actor): void
    {
        $key = 'filament:'.$operation.':actor:'.$actor->id;
        abort_if(RateLimiter::tooManyAttempts($key, $limit), 429);
        RateLimiter::hit($key, 60);
    }

    private function domainFailure(LessonAssessmentException $exception): array
    {
        if (in_array($exception->httpStatus, [403, 404], true)) {
            abort($exception->httpStatus, $exception->errorCode);
        }
        $message = $exception->errorCode.': '.$exception->getMessage();
        $this->addError('review', $message);

        return ['ok' => false, 'terminal' => true, 'message' => $message];
    }

    protected function getViewData(): array
    {
        $detail = $this->detail();

        return ['detail' => $detail, 'canReview' => Rbac::hasPermission(auth('web')->user()->fresh(), 'assessment-reviews.review') && ! ImpersonationContext::isActive(request())];
    }
}
