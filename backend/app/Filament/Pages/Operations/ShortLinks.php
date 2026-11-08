<?php

namespace App\Filament\Pages\Operations;

use App\Filament\Concerns\HasManualModalSaving;
use App\Services\AdminOperationsService;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

final class ShortLinks extends OperationsPage
{
    use HasManualModalSaving;

    protected static ?string $slug = 'short-links';

    protected static ?string $title = 'Короткие ссылки';

    protected static ?string $navigationLabel = 'Короткие ссылки';

    protected static string|\UnitEnum|null $navigationGroup = 'Инструменты';

    protected static ?int $navigationSort = 1;

    protected static string $readPermission = 'short-links.read';

    public const COLUMN_FILTERS = ['slug' => ['slug'], 'target_path' => ['target_path'], 'is_active' => ['active'], 'created_at' => ['created_at']];

    public const COLUMN_SEARCHES = ['target_path' => 'target_path'];

    public const CHOICE_FILTERS = ['is_active' => 'active'];

    public const QUERY_SEARCHES = ['slug' => ['slug'], 'target_path' => ['target_path']];

    public const QUERY_DATES = ['created_at' => 'created_at'];

    public const QUERY_SORTS = ['id' => 'id', 'slug' => 'slug', 'created_at' => 'created_at'];

    private function fields(): array
    {
        return [
            $this->manualSavingStatus(),
            TextInput::make('slug')->label('Короткий путь')->required()->maxLength(80)->belowContent($this->manualSaveHint('slug')),
            TextInput::make('target_path')->label('Адрес перехода на сайте')->required()->maxLength(512)
                ->extraInputAttributes(fn (TextInput $component): array => ['aria-describedby' => implode(' ', [...$component->getDescriptionIds(), 'short-link-target-help'])])
                ->belowContent([
                    Text::make('Например, /courses/anatomy')->extraAttributes(['id' => 'short-link-target-help']),
                    $this->manualSaveHint('target_path'),
                ]),
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

            return app(AdminOperationsService::class)->links($this->actor(), 'list', $input, configureQuery: $this->columnQuery())['data'];
        })
            ->emptyStateHeading('Короткие ссылки не найдены')->emptyStateDescription(null)
            ->paginationPageOptions([25, 50, 100])->defaultPaginationPageOption(25)
            ->columns([
                TextColumn::make('slug')->label('Короткий путь')->sortable(),
                TextColumn::make('target_path')->label('Адрес перехода')->wrap(),
                TextColumn::make('is_active')->label('Активна')->formatStateUsing(fn ($state) => $state ? 'Да' : 'Нет'),
                TextColumn::make('created_at')->label('Создано')->sortable()->dateTime('d.m.Y H:i'),
            ])
            ->filters([
                self::textFilter('slug', 'Короткий путь'),
                self::textFilter('target_path', 'Адрес перехода'),
                SelectFilter::make('active')->label('Активность')->options(['1' => 'Да', '0' => 'Нет']),
                self::dateFilter('created_at', 'Создано'),
            ], layout: FiltersLayout::Hidden)->deferFilters(false)
            ->headerActions([
                $this->manualModalAction(Action::make('create')->label('Создать')->schema($this->fields()))
                    ->beforeFormValidated(fn () => $this->assertFields(['slug', 'target_path']))
                    ->mountUsing(fn (?Schema $schema) => $this->beginManualModal($schema))
                    ->action(fn (array $data) => $this->completeManualModal(fn () => app(AdminOperationsService::class)
                        ->links($this->actor('short-links.write'), 'create', $data, key: $this->operationKey))),
            ])
            ->recordActions([
                $this->manualModalAction(Action::make('edit')->label('Изменить')->schema($this->fields()))
                    ->beforeFormValidated(fn () => $this->assertFields(['slug', 'target_path']))
                    ->mountUsing(fn (?Schema $schema, Model $record) => $this->beginManualModal($schema, $record, ['slug', 'target_path']))
                    ->action(fn (array $data, Model $record) => $this->completeManualModal(fn () => app(AdminOperationsService::class)
                        ->links($this->actor('short-links.write'), 'update', $data, (int) $record->getKey(), $this->operationKey, expectedSnapshot: $this->manualSnapshot))),
                Action::make('delete')->label('Удалить')->color('danger')->requiresConfirmation()
                    ->mountUsing(fn (?Schema $schema) => $this->beginOperation($schema))
                    ->action(fn (Model $record) => $this->completeOperation(fn () => app(AdminOperationsService::class)
                        ->links($this->actor('short-links.write'), 'delete', id: (int) $record->getKey(), key: $this->operationKey))),
            ]);
    }
}
