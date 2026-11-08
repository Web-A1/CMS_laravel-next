<?php

namespace App\Filament\Pages\Operations;

use App\Services\AdminOperationsService;
use Filament\Support\ArrayRecord;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

final class Orders extends OperationsPage
{
    protected static ?string $slug = 'orders';
    protected static ?string $title = 'Заказы';
    protected static ?string $navigationLabel = 'Заказы';
    protected static string $readPermission = 'payments.read_order';
    public const COLUMN_FILTERS = ['user.name' => ['customer'], 'payment_status' => ['payment_status'], 'access_status' => ['access_status'], 'created_at' => ['dates']];
    public const COLUMN_SEARCHES = ['user.name' => 'customer'];
    public const CHOICE_FILTERS = ['payment_status' => 'payment_status', 'access_status' => 'access_status'];
    public const QUERY_DATES = ['dates' => 'orders.created_at'];
    public const QUERY_CHOICES = ['access_status' => 'access_status'];
    public const QUERY_SORTS = ['id' => 'orders.id', 'amount_final' => ['currency', 'amount_final'], 'amount_paid' => ['currency', 'amount_paid'], 'created_at' => 'orders.created_at'];

    public function table(Table $table): Table
    {
        return $table->records(fn (int $page, int $recordsPerPage, ?array $filters) => app(AdminOperationsService::class)->orders($this->actor(), [
            'page' => $page, 'per_page' => $recordsPerPage,
            'payment_status' => $filters['payment_status']['value'] ?? null,
        ], configureQuery: function ($query): void {
            ($this->columnQuery())($query);
            if (filled($this->tableFilters['customer']['value'] ?? null)) {
                $query->whereHas('user', function ($users): void {
                    \App\Support\Admin\ColumnQuery::search($users, ['name', 'email'], $this->tableFilters['customer']['value']);
                });
            }
        })['data']->through(fn (array $record): array => [
            ...$record, ArrayRecord::getKeyName() => (string) $record['id'],
        ]))
            ->emptyStateHeading('Заказы не найдены')->emptyStateDescription(null)
            ->paginationPageOptions([25, 50, 100])->defaultPaginationPageOption(25)
            ->columns([
                TextColumn::make('id')->label('Заказ')->sortable(),
                TextColumn::make('user.name')->label('Пользователь')->description(fn (array $record) => $record['user']['email'] ?? ''),
                TextColumn::make('amount_final')->label('Сумма')->sortable()->formatStateUsing(fn ($state, array $record) => number_format((int) $state / 100, 2, ',', ' ').' '.$record['currency']),
                TextColumn::make('amount_paid')->label('Оплачено')->sortable()->formatStateUsing(fn ($state, array $record) => number_format((int) $state / 100, 2, ',', ' ').' '.$record['currency']),
                TextColumn::make('payment_status')->label('Оплата'),
                TextColumn::make('access_status')->label('Доступ'),
                TextColumn::make('created_at')->label('Создан')->sortable()->dateTime('d.m.Y H:i'),
            ])->filters([
                static::textFilter('customer', 'Пользователь: имя или email'),
                SelectFilter::make('payment_status')->label('Оплата')->options([
                    'pending' => 'Ожидает', 'paid' => 'Оплачен', 'partially_paid' => 'Частично оплачен',
                    'failed' => 'Ошибка', 'cancelled' => 'Отменён', 'refunded' => 'Возвращён',
                ]),
                SelectFilter::make('access_status')->label('Доступ')->options(['not_granted' => 'Не выдан', 'pending_grant' => 'Выдаётся', 'granted' => 'Выдан', 'grant_failed' => 'Ошибка выдачи', 'revoked' => 'Отозван']),
                static::dateFilter('dates', 'Создан'),
            ], layout: \Filament\Tables\Enums\FiltersLayout::Hidden)->deferFilters(false);
    }
}
