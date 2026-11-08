<?php

namespace App\Filament\Pages\Reviews;

use App\Exceptions\AdminReviewException;
use App\Filament\Pages\Operations\OperationsPage;
use App\Models\Review;
use App\Services\Reviews\AdminReviewDeletion;
use App\Support\Admin\ReviewsPanel;
use App\Support\ImpersonationContext;
use App\Support\Rbac;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class Reviews extends OperationsPage
{
    protected static ?string $slug = 'reviews';

    protected static ?string $title = 'Отзывы';

    protected static ?string $navigationLabel = 'Отзывы';

    protected static string $readPermission = 'reviews.read';

    #[Url(as: 'filters')]
    public ?array $tableFilters = null;

    #[Url(as: 'perPage')]
    public $tableRecordsPerPage = null;

    #[Url(as: 'sort')]
    public ?string $tableSort = null;

    public const COLUMN_FILTERS = [
        'id' => ['id'],
        'display_name' => ['author'], 'text' => ['text'], 'scope' => ['subjectId', 'courseId', 'scope'],
        'review_written_at' => ['date'], 'show_on_home' => ['home'],
        'author_photo_path' => ['photo'], 'media_count' => ['screen'], 'publication_status' => ['status'],
    ];

    public const CHOICE_FILTERS = [
        'show_on_home' => 'home', 'author_photo_path' => 'photo', 'media_count' => 'screen', 'publication_status' => 'status',
    ];

    public const COLUMN_SEARCHES = ['text' => 'text'];

    public function getTableSortColumn(): ?string
    {
        $column = explode(':', $this->tableSort ?? '')[0];

        return in_array($column, ['id', 'display_name', 'scope', 'review_written_at'], true)
            ? $column : 'review_written_at';
    }

    public function getTableSortDirection(): ?string
    {
        if (filled($this->tableSort)) {
            return str_ends_with($this->tableSort, ':asc') ? 'asc' : 'desc';
        }

        return 'desc';
    }

    public function sortTable(?string $column = null, ?string $direction = null): void
    {
        if (! in_array($column, ['id', 'display_name', 'scope', 'review_written_at'], true)) {
            return;
        }

        $direction ??= $column === $this->getTableSortColumn()
            ? ($this->getTableSortDirection() === 'asc' ? 'desc' : 'asc')
            : ($column === 'review_written_at' ? 'desc' : 'asc');
        $this->tableSort = $column.':'.($direction === 'desc' ? 'desc' : 'asc');
        $this->updatedTableSort();
    }

    public function updatedTableFilters($value = null, ?string $key = null): void
    {
        if ($key === 'date.value' && in_array($value, ['this_month', 'undated'], true)) {
            $this->tableFilters['date']['from'] = null;
            $this->tableFilters['date']['to'] = null;
        }
        parent::updatedTableFilters();
    }

    public function columnFilterIsActive(string $column): bool
    {
        foreach (self::COLUMN_FILTERS[$column] ?? [] as $name) {
            if ($this->getTable()->getFilter($name)?->getIndicators()) {
                return true;
            }
        }

        return false;
    }

    public function clearColumnFilters(string $column): void
    {
        foreach (self::COLUMN_FILTERS[$column] ?? [] as $name) {
            $this->removeTableFilter($name);
        }
    }

    public function chooseColumnFilter(string $column, ?string $value): void
    {
        $name = self::CHOICE_FILTERS[$column] ?? null;
        if (! $name || ($value !== null && ! array_key_exists($value, $this->getTable()->getFilter($name)->getOptions()))) {
            return;
        }

        $this->tableFilters[$name]['value'] = $value;
        $this->updatedTableFilters();
    }

    #[Locked]
    public array $bulkDeletionReviews = [];

    #[Locked]
    public array $bulkPlacementReviews = [];

    #[Locked]
    public bool $bulkShowOnHome = false;

    private function canBulkManage(): bool
    {
        return self::canAccess() && Rbac::hasPermission(auth('web')->user()->fresh(), 'reviews.write')
            && ! ImpersonationContext::isActive(request());
    }

    private function bulkSelection(): array
    {
        $this->actor('reviews.write');
        Validator::make(['selection' => $this->selectedTableRecords, 'all' => $this->isTrackingDeselectedTableRecords], [
            'selection' => ['required', 'array', 'min:1', 'max:'.AdminReviewDeletion::MAX_BULK_REVIEWS],
            'selection.*' => ['required', 'integer', 'min:1', 'distinct'],
            'all' => ['declined'],
        ], ['selection.required' => 'Выберите отзывы.', 'all.declined' => 'Выберите конкретные отзывы на текущей странице.'])->validate();
        $ids = array_map('intval', $this->selectedTableRecords);
        $records = $this->getTableRecords()->keyBy('id');
        if (array_diff($ids, $records->keys()->all()) !== []) {
            throw ValidationException::withMessages(['selection' => 'Выбранные отзывы недоступны на текущей странице. Обновите список и выберите их заново.']);
        }
        sort($ids, SORT_NUMERIC);

        return array_map(fn (int $id) => ['id' => $id, 'revision' => (int) $records[$id]->moderation_events_max_id], $ids);
    }

    private function beginBulkDeletion(?Schema $schema): void
    {
        $this->bulkDeletionReviews = $this->bulkSelection();
        $this->beginOperation($schema);
    }

    private function deleteSelectedReviews(array $data): void
    {
        $this->completeBulkOperation(
            fn () => app(ReviewsPanel::class)->deleteMany($this->bulkDeletionReviews, $data['confirmation'], $this->operationKey),
            'Отзывы не удалены', 'Удалено отзывов',
        );
    }

    private function placementAction(string $name, bool $showOnHome): BulkAction
    {
        return BulkAction::make($name)->label($showOnHome ? 'Показывать на главной' : 'Убрать с главной')
            ->color('gray')->requiresConfirmation()->visible(fn () => $this->canBulkManage())
            ->modalHeading($showOnHome ? 'Включить показ на главной?' : 'Убрать выбранные отзывы с главной?')
            ->modalDescription(fn () => 'Выбрано: '.count($this->bulkPlacementReviews).'. ID: '.implode(', ', array_column($this->bulkPlacementReviews, 'id')).'. '
                .($showOnHome ? 'На главной появляются только опубликованные отзывы с этой отметкой. ' : '')
                .'Статус публикации и остальные данные отзывов сохранятся.')
            ->modalSubmitActionLabel('Применить')->modalCancelActionLabel('Отмена')
            ->mountUsing(function (?Schema $schema) use ($showOnHome): void {
                $this->bulkPlacementReviews = $this->bulkSelection();
                $this->bulkShowOnHome = $showOnHome;
                $this->beginOperation($schema);
            })
            ->action(fn () => $this->completeBulkOperation(
                fn () => app(ReviewsPanel::class)->placeMany($this->bulkPlacementReviews, $this->bulkShowOnHome, $this->operationKey),
                'Изменения не сохранены', $this->bulkShowOnHome ? 'Показ на главной включён для отзывов' : 'Показ на главной отключён для отзывов',
            ))->deselectRecordsAfterCompletion();
    }

    private function completeBulkOperation(callable $operation, string $failureTitle, string $successTitle): void
    {
        try {
            $result = $operation();
        } catch (AdminReviewException $exception) {
            Notification::make()->title($failureTitle)->body($exception->getMessage().' Закройте подтверждение и выберите отзывы заново.')->danger()->send();
            throw new Halt;
        } catch (QueryException $exception) {
            report($exception);
            Notification::make()->title($failureTitle)->body('Не удалось сохранить изменения. Повторите действие.')->danger()->send();
            throw new Halt;
        } catch (HttpExceptionInterface $exception) {
            if ($exception->getStatusCode() !== 429) {
                throw $exception;
            }
            Notification::make()->title($failureTitle)->body('Слишком много попыток. Подождите минуту и повторите действие.')->danger()->send();
            throw new Halt;
        }
        $this->selectedTableRecords = [];
        $this->bulkDeletionReviews = [];
        $this->bulkPlacementReviews = [];
        unset($this->cachedSelectedTableRecords);
        $this->flushCachedTableRecords();
        $this->resetPage();
        Notification::make()->title($successTitle.': '.$result['count'])->success()->send();
    }

    public function listUrl(): string
    {
        return '/admin/reviews?'.http_build_query([
            'filters' => $this->tableFilters,
            'page' => $this->getTablePage(),
            'perPage' => $this->getTableRecordsPerPage(),
            'sort' => $this->tableSort,
        ]);
    }

    private function hasActiveFilters(): bool
    {
        return collect($this->getTable()->getFilters())
            ->contains(fn ($filter) => filled($filter->getIndicators()));
    }

    private function editorUrl(string $id): string
    {
        return '/admin/reviews/'.$id.'?'.http_build_query(['returnTo' => $this->listUrl()]);
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('create')->label('Добавить отзыв')->url(fn () => $this->editorUrl('new'))];
    }

    public function table(Table $table): Table
    {
        return $table->extraAttributes(['data-admin-table-controls-location' => 'page-header', 'data-admin-reviews' => ''])
            ->records(function (int $page, int $recordsPerPage, ?array $filters, ?string $sortColumn, ?string $sortDirection) {
                $criteria = [
                    'reviewId' => $filters['id']['value'] ?? null,
                    'status' => $filters['status']['value'] ?? 'all',
                    'authorPhoto' => $filters['photo']['value'] ?? 'all',
                    'photo' => $filters['screen']['value'] ?? 'all',
                    'date' => $filters['date']['value'] ?? 'newest',
                    'subjectId' => $filters['subjectId']['value'] ?? null,
                    'home' => $filters['home']['value'] ?? 'all',
                    'author' => $filters['author']['value'] ?? null,
                    'text' => $filters['text']['value'] ?? null,
                    'courseId' => $filters['courseId']['value'] ?? null,
                    'scope' => $filters['scope']['value'] ?? null,
                    'writtenFrom' => $filters['date']['from'] ?? null,
                    'writtenTo' => $filters['date']['to'] ?? null,
                ];
                $errorPaths = [
                    'reviewId' => 'id.value',
                    'author' => 'author.value', 'text' => 'text.value', 'courseId' => 'courseId.value', 'scope' => 'scope.value',
                    'writtenFrom' => 'date.from', 'writtenTo' => 'date.to',
                ];
                foreach ($errorPaths as $path) {
                    $this->resetErrorBag('tableFilters.'.$path);
                }
                try {
                    $query = app(ReviewsPanel::class)->query($criteria, $sortColumn, $sortDirection);
                } catch (ValidationException $exception) {
                    foreach ($exception->errors() as $field => $messages) {
                        $this->addError('tableFilters.'.($errorPaths[$field] ?? $field), $messages[0]);
                    }
                    $query = app(ReviewsPanel::class)->query([])->whereRaw('1 = 0');
                }

                return $query->paginate($recordsPerPage, ['*'], 'page', $page);
            })
            ->emptyStateHeading(fn () => $this->hasActiveFilters() ? 'Отзывы не найдены' : 'Отзывов пока нет')
            ->emptyStateDescription(fn () => $this->hasActiveFilters() ? 'Измените условия поиска или сбросьте фильтры.' : 'Добавьте первый отзыв и сохраните его как черновик.')
            ->emptyStateActions([
                    Action::make('resetSearch')->label('Сбросить поиск и фильтры')
                        ->visible(fn () => $this->hasActiveFilters())
                        ->action(function (): void {
                            $this->resetTableFiltersForm();
                            $this->resetPage();
                        }),
                ])
            ->paginationPageOptions([20, 50, 100])->defaultPaginationPageOption(20)
            ->selectable(fn () => $this->canBulkManage())->selectCurrentPageOnly()->trackDeselectedRecords(false)
            ->maxSelectableRecords(AdminReviewDeletion::MAX_BULK_REVIEWS)
            ->toolbarActions([
                $this->placementAction('showOnHome', true),
                $this->placementAction('hideFromHome', false),
                BulkAction::make('deleteSelected')->label('Удалить выбранные')->color('danger')->requiresConfirmation()
                        ->visible(fn () => $this->canBulkManage())
                        ->modalHeading('Удалить выбранные отзывы навсегда?')
                        ->modalDescription(fn () => 'Выбрано: '.count($this->bulkDeletionReviews).'. ID: '.implode(', ', array_column($this->bulkDeletionReviews, 'id')).'. Отзывы, их источники и история будут удалены без возможности восстановления. Все связанные рабочие изображения и их версии также будут удалены; общие файлы других отзывов сохранятся.')
                        ->modalSubmitActionLabel('Удалить навсегда')->modalCancelActionLabel('Отмена')
                        ->schema([TextInput::make('confirmation')->label('Введите количество выбранных отзывов')->required()->maxLength(3)
                            ->rules([fn () => 'in:'.count($this->bulkDeletionReviews)])
                            ->validationMessages(['required' => 'Введите количество отзывов для подтверждения.', 'in' => 'Введите точное количество выбранных отзывов.'])])
                        ->mountUsing(fn (?Schema $schema) => $this->beginBulkDeletion($schema))
                        ->action(fn (array $data) => $this->deleteSelectedReviews($data))->deselectRecordsAfterCompletion(),
            ])
            ->columns([
                TextColumn::make('id')->label('ID')->color('gray')->sortable()->extraHeaderAttributes(['data-admin-column-min-width' => '104']),
                TextColumn::make('display_name')->label('Автор')->sortable()
                        ->formatStateUsing(fn (Review $record) => $record->is_anonymous ? 'Анонимный автор' : $record->display_name)
                        ->tooltip(fn (Review $record) => $record->is_anonymous ? 'Анонимный автор' : $record->display_name)
                        ->extraAttributes(['style' => 'max-width: 9rem'])->wrap()->lineClamp(1),
                TextColumn::make('text')->label('Отзыв')->wrap()->lineClamp(1)
                        ->extraAttributes(['style' => 'max-width: 16rem'])->tooltip(fn (Review $record) => $record->text),
                TextColumn::make('scope')->label('Предмет / курс')->sortable()->state(fn (Review $record) => $record->course ? $record->course->subject?->title.' · '.$record->course->title
                            : ($record->subject?->title ?? 'Общий отзыв'))
                        ->wrap()->lineClamp(1)->extraAttributes(['style' => 'max-width: 10rem'])
                        ->tooltip(fn (Review $record) => $record->course
                            ? $record->course->subject?->title.' · '.$record->course->title
                            : ($record->subject?->title ?? 'Общий отзыв')),
                TextColumn::make('review_written_at')->label('Дата отзыва')->sortable()->date('d.m.Y')->placeholder('—'),
                TextColumn::make('show_on_home')->label('Главная')->badge()->color(fn (bool $state) => $state ? 'success' : 'gray')
                        ->formatStateUsing(fn (bool $state) => $state ? 'Выбран' : 'Нет'),
                TextColumn::make('author_photo_path')->label('Фото')->state(fn (Review $record) => filled($record->author_photo_path) ? 'Есть' : 'Нет'),
                TextColumn::make('media_count')->label('Скрин')->formatStateUsing(fn (Review $record) => $record->media_count + ($record->media_managed ? 0 : $record->photo_evidence_count) > 0 ? 'Есть' : 'Нет'),
                TextColumn::make('publication_status')->label('Статус')->badge()
                        ->color(fn (string $state) => match ($state) {
                            'published' => 'success', 'hidden' => 'warning', default => 'gray'
                        })
                        ->formatStateUsing(fn (string $state): string => ReviewsPanel::PUBLICATION_LABELS[$state] ?? $state),
            ])->filters([
                Filter::make('id')->schema([TextInput::make('value')->label('Номер отзыва')->placeholder('Введите ID')
                    ->inputMode('numeric')->maxLength(19)->live(debounce: 400)])
                    ->indicateUsing(fn (array $data) => filled($data['value'] ?? null) ? 'ID: '.$data['value'] : null),
                Filter::make('author')->schema([TextInput::make('value')->label('Имя автора')->placeholder('Поиск по имени')->maxLength(200)->live(debounce: 400)])
                        ->indicateUsing(fn (array $data) => filled($data['value'] ?? null) ? 'Автор: '.$data['value'] : null),
                Filter::make('text')->schema([TextInput::make('value')->label('Текст отзыва')->placeholder('Найти в тексте отзыва')->maxLength(200)->live(debounce: 400)])
                        ->indicateUsing(fn (array $data) => filled($data['value'] ?? null) ? 'Текст: '.$data['value'] : null),
                SelectFilter::make('status')->label('Статус')->options(ReviewsPanel::PUBLICATION_LABELS)->placeholder('Все')
                        ->indicateUsing(fn (array $data) => filled($data['value'] ?? null) && $data['value'] !== 'all' ? 'Статус: '.(ReviewsPanel::PUBLICATION_LABELS[$data['value']] ?? '') : null),
                SelectFilter::make('photo')->label('Фото')->options(['yes' => 'Есть', 'no' => 'Нет'])->placeholder('Все')
                        ->indicateUsing(fn (array $data) => ($data['value'] ?? 'all') === 'all' ? null : (($data['value'] ?? '') === 'yes' ? 'С фото' : 'Без фото')),
                SelectFilter::make('screen')->label('Скрин')->options(['yes' => 'Есть', 'no' => 'Нет'])->placeholder('Все')
                        ->indicateUsing(fn (array $data) => ($data['value'] ?? 'all') === 'all' ? null : (($data['value'] ?? '') === 'yes' ? 'Со скрином' : 'Без скрина')),
                Filter::make('date')->label('Дата отзыва')->schema([
                        Select::make('value')->label('Показывать')->options(['this_month' => 'За этот месяц', 'undated' => 'Без даты'])->placeholder('За любое время'),
                        DatePicker::make('from')->label('Дата отзыва с')->native()
                            ->disabled(fn (Get $get) => in_array($get('value'), ['this_month', 'undated'], true)),
                        DatePicker::make('to')->label('Дата отзыва по')->native()->afterOrEqual('from')
                            ->disabled(fn (Get $get) => in_array($get('value'), ['this_month', 'undated'], true)),
                ])->indicateUsing(fn (array $data) => array_filter([
                        match ($data['value'] ?? null) {
                            'this_month' => 'Отзывы за этот месяц', 'undated' => 'Отзывы без даты', default => null
                        },
                        filled($data['from'] ?? null) ? 'Дата отзыва с '.$data['from'] : null,
                        filled($data['to'] ?? null) ? 'Дата отзыва по '.$data['to'] : null,
                ])),
                SelectFilter::make('subjectId')->label('Предмет')->options(fn () => app(ReviewsPanel::class)->subjects()),
                SelectFilter::make('courseId')->label('Курс')->options(fn () => app(ReviewsPanel::class)->courses()),
                SelectFilter::make('scope')->label('Привязка')->options(['general' => 'Общие отзывы', 'subject' => 'К предмету', 'course' => 'К курсу'])->placeholder('Все'),
                SelectFilter::make('home')->label('Главная страница')->options(['yes' => 'Есть', 'no' => 'Нет'])->placeholder('Все')
                        ->indicateUsing(fn (array $data) => match ($data['value'] ?? null) {
                            'yes' => 'Выбраны для главной','no' => 'Не выбраны для главной',default => null
                        }),
            ], layout: FiltersLayout::Hidden)->deferFilters(false)->filtersFormColumns(1)
            ->recordUrl(fn (Review $record) => $this->editorUrl((string) $record->id));
    }
}
