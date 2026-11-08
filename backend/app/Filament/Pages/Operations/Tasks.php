<?php

namespace App\Filament\Pages\Operations;

use App\Filament\Concerns\HasManualModalSaving;
use App\Models\User;
use App\Services\AdminOperationsService;
use App\Support\Rbac;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

final class Tasks extends OperationsPage
{
    use HasManualModalSaving;

    protected static ?string $slug = 'tasks';

    protected static ?string $title = 'Задачи';

    protected static ?string $navigationLabel = 'Задачи';

    protected static string $readPermission = 'tasks.read';

    public const COLUMN_FILTERS = ['title' => ['title'], 'status' => ['status'], 'priority' => ['priority'], 'due_at' => ['due_at']];

    public const COLUMN_SEARCHES = ['title' => 'title'];

    public const CHOICE_FILTERS = ['status' => 'status', 'priority' => 'priority'];

    public const QUERY_SEARCHES = ['title' => ['title']];

    public const QUERY_DATES = ['due_at' => 'due_at'];

    public const QUERY_CHOICES = ['priority' => 'priority'];

    public const QUERY_SORTS = ['id' => 'id', 'due_at' => 'due_at'];

    private function fields(): array
    {
        return [
            $this->manualSavingStatus(),
            TextInput::make('title')->label('Название')->required()->maxLength(180)->belowContent($this->manualSaveHint('title')),
            Textarea::make('description')->label('Описание')->maxLength(20000)->rows(6)->belowContent($this->manualSaveHint('description')),
            Select::make('assignee_id')->label('Исполнитель')->options(fn () => User::query()
                ->whereIn('role', User::STAFF_ROLES)->where('migration_hold', false)
                ->where(fn ($query) => $query->whereNull('password_state')->orWhere('password_state', '!=', User::PASSWORD_STATE_MIGRATION_HOLD))
                ->orderBy('name')->pluck('name', 'id')->all())->searchable()->nullable()->belowContent($this->manualSaveHint('assignee_id')),
            Select::make('status')->label('Статус')->options(['open' => 'Открыта', 'in_progress' => 'В работе', 'done' => 'Готово', 'cancelled' => 'Отменена'])
                ->default('open')->dehydrated(fn ($state) => filled($state))->belowContent($this->manualSaveHint('status')),
        ];
    }

    public function table(Table $table): Table
    {
        return $table->records(function (int $page, int $recordsPerPage, ?array $filters) {
            $input = ['page' => $page, 'per_page' => $recordsPerPage];
            foreach ($filters ?? [] as $name => $filter) {
                if (($filter['value'] ?? '') !== '' && ($filter['value'] ?? null) !== null) {
                    $input[$name] = $filter['value'];
                }
            }

            return app(AdminOperationsService::class)->tasks($this->actor(), 'list', $input, configureQuery: $this->columnQuery())['data'];
        })
            ->emptyStateHeading('Задачи не найдены')->emptyStateDescription(null)
            ->paginationPageOptions([25, 50, 100])->defaultPaginationPageOption(25)
            ->columns([
                TextColumn::make('title')->label('Задача')->wrap(),
                TextColumn::make('description')->label('Описание')->wrap(),
                TextColumn::make('status')->label('Статус'),
                TextColumn::make('priority')->label('Приоритет'),
                TextColumn::make('due_at')->label('Срок')->sortable()->dateTime('d.m.Y H:i'),
            ])
            ->filters([
                self::textFilter('title', 'Задача'),
                SelectFilter::make('status')->label('Статус')->options(['open' => 'Открыта', 'in_progress' => 'В работе', 'done' => 'Готово', 'cancelled' => 'Отменена']),
                SelectFilter::make('priority')->label('Приоритет')->options(['low' => 'Низкий', 'normal' => 'Обычный', 'high' => 'Высокий']),
                self::dateFilter('due_at', 'Срок'),
            ], layout: FiltersLayout::Hidden)->deferFilters(false)
            ->headerActions([
                $this->manualModalAction(Action::make('create')->label('Создать')->schema($this->fields()))
                    ->visible(fn () => Rbac::hasPermission($this->actor(), 'tasks.write'))
                    ->beforeFormValidated(fn () => $this->assertFields(['title', 'description', 'assignee_id', 'status']))
                    ->mountUsing(fn (?Schema $schema) => $this->beginManualModal($schema))
                    ->action(fn (array $data) => $this->completeManualModal(fn () => app(AdminOperationsService::class)
                        ->tasks($this->actor('tasks.write'), 'create', $data, key: $this->operationKey))),
            ])
            ->recordActions([
                $this->manualModalAction(Action::make('edit')->label('Изменить')->schema($this->fields()))
                    ->visible(fn () => Rbac::hasPermission($this->actor(), 'tasks.write'))
                    ->beforeFormValidated(fn () => $this->assertFields(['title', 'description', 'assignee_id', 'status']))
                    ->mountUsing(fn (?Schema $schema, Model $record) => $this->beginManualModal($schema, $record, ['title', 'description', 'assignee_id', 'status']))
                    ->action(fn (array $data, Model $record) => $this->completeManualModal(fn () => app(AdminOperationsService::class)
                        ->tasks($this->actor('tasks.write'), 'update', $data, (int) $record->getKey(), $this->operationKey, expectedSnapshot: $this->manualSnapshot))),
                Action::make('delete')->label('Удалить')->color('danger')->requiresConfirmation()
                    ->visible(fn () => Rbac::hasPermission($this->actor(), 'tasks.write'))
                    ->mountUsing(fn (?Schema $schema) => $this->beginOperation($schema))
                    ->action(fn (Model $record) => $this->completeOperation(fn () => app(AdminOperationsService::class)
                        ->tasks($this->actor('tasks.write'), 'delete', id: (int) $record->getKey(), key: $this->operationKey))),
                Action::make('complete')->label('Выполнено')->requiresConfirmation()
                    ->visible(fn (Model $record) => Rbac::hasPermission($this->actor(), 'tasks.complete_assigned')
                        && $record->assignee_id === $this->actor()->id && in_array($record->status, ['open', 'in_progress'], true))
                    ->mountUsing(fn (?Schema $schema) => $this->beginOperation($schema))
                    ->action(fn (Model $record) => $this->completeOperation(fn () => app(AdminOperationsService::class)
                        ->completeAssignedTask($this->actor('tasks.complete_assigned'), (int) $record->getKey(), key: $this->operationKey))),
            ]);
    }
}
