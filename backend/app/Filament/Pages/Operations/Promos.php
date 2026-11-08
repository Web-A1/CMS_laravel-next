<?php

namespace App\Filament\Pages\Operations;

use App\Services\AdminOperationsService;
use App\Support\Rbac;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\ArrayRecord;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

final class Promos extends OperationsPage
{
    protected static ?string $slug = 'promos';

    protected static ?string $title = 'Промокоды';

    protected static ?string $navigationLabel = 'Промокоды';

    protected static string $readPermission = 'promos.read';

    public const COLUMN_FILTERS = ['status' => ['status'], 'discount_kind' => ['discount_kind'], 'expires_at' => ['expires_at']];

    public const CHOICE_FILTERS = ['status' => 'status', 'discount_kind' => 'discount_kind'];

    public const QUERY_DATES = ['expires_at' => 'expires_at'];

    public const QUERY_CHOICES = ['discount_kind' => 'discount_kind'];

    public const QUERY_SORTS = [
        'id' => 'id', 'expires_at' => 'expires_at', 'activation_limit' => 'activation_limit', 'per_user_limit' => 'per_user_limit',
        'usage_count' => "(SELECT COUNT(*) FROM promo_reservations WHERE promo_reservations.promo_code_id = promo_codes.id AND promo_reservations.status = 'committed')",
    ];

    public function table(Table $table): Table
    {
        return $table->records(fn (int $page, int $recordsPerPage, ?array $filters) => app(AdminOperationsService::class)->promos($this->actor(), 'list', [
            'page' => $page, 'per_page' => $recordsPerPage, 'status' => $filters['status']['value'] ?? null,
        ], configureQuery: $this->columnQuery())['data']->through(fn (array $record): array => [
            ...$record, ArrayRecord::getKeyName() => (string) $record['id'],
        ]))
            ->emptyStateHeading('Промокоды не найдены')->emptyStateDescription(null)
            ->paginationPageOptions([25, 50, 100])->defaultPaginationPageOption(25)
            ->columns([
                TextColumn::make('status')->label('Статус'),
                TextColumn::make('discount_kind')->label('Скидка')->formatStateUsing(fn (array $record) => $record['discount_kind'] === 'percent_bps' ? ($record['discount_bps'] / 100).'%' : $record['discount_minor'].' коп.'),
                TextColumn::make('expires_at')->label('Действует до')->sortable()->dateTime('d.m.Y H:i'),
                TextColumn::make('usage_count')->label('Использовано')->sortable(),
                TextColumn::make('activation_limit')->label('Лимит')->sortable(),
                TextColumn::make('per_user_limit')->label('На пользователя')->sortable(),
            ])
            ->filters([SelectFilter::make('status')->label('Статус')->options([
                'active' => 'Активен', 'revoked' => 'Отключён', 'promotion_violation_review' => 'На проверке',
            ]), SelectFilter::make('discount_kind')->label('Скидка')->options(['percent_bps' => 'Процент', 'fixed_minor' => 'Фиксированная']),
                self::dateFilter('expires_at', 'Действует до'),
            ], layout: FiltersLayout::Hidden)->deferFilters(false)
            ->headerActions([
                Action::make('create')->label('Создать')->visible(fn () => Rbac::hasPermission($this->actor(), 'promos.create'))->schema([
                    TextInput::make('code')->label('Код')->required()->minLength(3)->maxLength(80),
                    Select::make('discount_kind')->label('Тип скидки')->options(['percent_bps' => 'Процент', 'fixed_minor' => 'Фиксированная'])
                        ->default('percent_bps')->required()->live(),
                    TextInput::make('percent')->label('Скидка, %')->numeric()->minValue(0.01)->maxValue(100)
                        ->visible(fn (Get $get) => $get('discount_kind') === 'percent_bps')->required(fn (Get $get) => $get('discount_kind') === 'percent_bps'),
                    TextInput::make('discount_minor')->label('Сумма, коп.')->integer()->minValue(0)
                        ->visible(fn (Get $get) => $get('discount_kind') === 'fixed_minor')->required(fn (Get $get) => $get('discount_kind') === 'fixed_minor'),
                    DateTimePicker::make('expires_at')->label('Действует до')->required(),
                    TextInput::make('activation_limit')->label('Лимит активаций')->integer()->minValue(1)->default(1)->required(),
                    TextInput::make('per_user_limit')->label('На пользователя')->integer()->minValue(1)->default(1)->required(),
                ])->mountUsing(fn (?Schema $schema) => $this->beginOperation($schema, ['discount_kind' => 'percent_bps', 'activation_limit' => 1, 'per_user_limit' => 1]))
                    ->beforeFormValidated(fn () => $this->assertFields(['code', 'discount_kind', 'percent', 'discount_minor', 'expires_at', 'activation_limit', 'per_user_limit']))
                    ->action(function (array $data): void {
                        $data['status'] = 'active';
                        $data['discount_bps'] = $data['discount_kind'] === 'percent_bps' ? (int) round((float) $data['percent'] * 100) : null;
                        unset($data['percent']);
                        $this->completeOperation(fn () => app(AdminOperationsService::class)->promos($this->actor('promos.create'), 'create', $data, key: $this->operationKey));
                    }),
            ])
            ->recordActions([
                Action::make('delete')->label('Удалить')->color('danger')->requiresConfirmation()
                    ->visible(fn (array $record) => Rbac::hasPermission($this->actor(), 'promos.delete_own')
                        && ($record['created_by'] ?? null) === $this->actor()->id)
                    ->mountUsing(fn (?Schema $schema) => $this->beginOperation($schema))
                    ->action(fn (array $record) => $this->completeOperation(fn () => app(AdminOperationsService::class)
                        ->deleteOwnPromo($this->actor('promos.delete_own'), (int) $record['id'], key: $this->operationKey))),
                Action::make('toggle')->label(fn (array $record) => $record['status'] === 'active' ? 'Отключить' : 'Включить')->requiresConfirmation()
                    ->visible(fn () => Rbac::hasPermission($this->actor(), 'promos.write'))
                    ->mountUsing(fn (?Schema $schema) => $this->beginOperation($schema))
                    ->action(fn (array $record) => $this->completeOperation(fn () => app(AdminOperationsService::class)
                        ->promos($this->actor('promos.write'), 'update', ['status' => $record['status'] === 'active' ? 'revoked' : 'active'], (int) $record['id'], $this->operationKey))),
            ]);
    }
}
