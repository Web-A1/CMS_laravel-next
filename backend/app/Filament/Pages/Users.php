<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Services\AdminUserBulkDeletion;
use App\Services\AdminUserQueries;
use App\Support\Admin\ColumnQuery;
use App\Support\Admin\HasColumnControls;
use App\Support\Admin\UserBulkDeletionActions;
use App\Support\Admin\UsersActions;
use App\Support\Admin\UsersDisplay;
use App\Support\Admin\UsersPage;
use App\Support\Rbac;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Url;

final class Users extends UsersPage implements HasTable
{
    use HasColumnControls;
    use InteractsWithTable {
        handleTableFilterUpdates as protected updateNativeFilters;
        updatedTableSearch as protected updateNativeSearch;
    }
    use UserBulkDeletionActions;
    use UsersActions;

    #[Url(as: 'filters')]
    public ?array $tableFilters = null;

    #[Url(as: 'sort')]
    public ?string $tableSort = null;

    public const COLUMN_FILTERS = ['name' => ['name', 'scope'], 'email' => ['contact'], 'role' => ['role'], 'paid_orders' => ['paid'], 'created_at' => ['created']];

    public const COLUMN_SEARCHES = ['email' => 'contact'];

    public const CHOICE_FILTERS = ['role' => 'role', 'paid_orders' => 'paid'];

    protected static ?string $slug = 'users';

    protected static ?string $title = 'Пользователи';

    protected static ?int $navigationSort = -10;

    protected string $view = 'filament.pages.users';

    #[Url]
    public string $q = '';

    #[Url]
    public string $scope = 'users';

    public function mount(): void
    {
        $this->actor();
        $this->tableSearch = $this->q;
        $this->tableFilters ??= ['scope' => ['value' => $this->scope === 'users' ? null : $this->scope]];
        $this->scope = $this->tableFilters['scope']['value'] ?? $this->scope;
    }

    public function search(): void
    {
        $this->actor();
        $this->resetValidation();
        $this->tableSearch = $this->q;
        $this->resetPage();
    }

    public function updatedQ(): void
    {
        $this->search();
    }

    public function updatedTableSearch(): void
    {
        $this->q = $this->tableSearch ?? '';
        $this->resetValidation();
        $this->updateNativeSearch();
    }

    public function updatedScope(): void
    {
        $this->tableFilters['scope'] = ['value' => $this->scope === 'users' ? null : $this->scope];
        $this->resetValidation();
        $this->resetPage();
    }

    protected function handleTableFilterUpdates(): void
    {
        $this->scope = $this->tableFilters['scope']['value'] ?? 'users';
        $this->resetValidation();
        $this->updateNativeFilters();
    }

    protected function impersonationReturnPath(): string
    {
        return '/admin/users?'.http_build_query($this->listContext());
    }

    private function listContext(): array
    {
        return ['q' => trim($this->q), 'scope' => $this->scope, 'page' => $this->getTablePage(), 'filters' => $this->tableFilters, 'sort' => $this->tableSort];
    }

    protected function columnQuery(): \Closure
    {
        return function ($query): void {
            ColumnQuery::search($query, ['name'], $this->tableFilters['name']['value'] ?? null);
            ColumnQuery::search($query, ['email', 'phone'], $this->tableFilters['contact']['value'] ?? null);
            ColumnQuery::dates($query, 'users.created_at', $this->tableFilters['created'] ?? null);
            $sorts = ['id' => 'users.id', 'name' => 'name', 'created_at' => 'users.created_at'];
            if ($this->scope === 'staff') {
                $role = $this->tableFilters['role']['value'] ?? null;
                if (in_array($role, User::STAFF_ROLES, true)) {
                    $query->where('role', $role);
                }
            } elseif (Rbac::hasPermission($this->actor(), 'payments.read_order')) {
                $paidCount = "(SELECT COUNT(*) FROM orders WHERE orders.user_id = users.id AND orders.payment_status = 'paid')";
                $sorts['paid_orders'] = $paidCount;
                $paid = $this->tableFilters['paid']['value'] ?? null;
                if (in_array($paid, ['1', '0'], true)) {
                    $query->whereRaw($paidCount.($paid === '1' ? ' > 0' : ' = 0'));
                }
            }
            ColumnQuery::sort($query, $this->tableSort, $sorts, 'created_at:desc', 'users.id');
        };
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(function (int $page): LengthAwarePaginator {
                $listing = $this->listing(fn () => app(AdminUserQueries::class)->index($this->actor(), [
                    // Validate the original URL value before Livewire normalizes pagination.
                    'q' => $this->q, 'scope' => $this->scope, 'page' => request()->query('page', $page),
                ], configureQuery: $this->columnQuery()));
                $records = collect($listing['data'])->map(fn (array $row): array => [...$row, '__key' => (string) $row['id']])->keyBy('id');

                return new LengthAwarePaginator($records, $listing['meta']['total'], 20, $page);
            })
            ->defaultSort('created_at', 'desc')
            ->paginationPageOptions([20])->defaultPaginationPageOption(20)
            ->selectable(fn (): bool => $this->canBulkDeleteUsers())->selectCurrentPageOnly()->trackDeselectedRecords(false)
            ->maxSelectableRecords(AdminUserBulkDeletion::MAX_USERS)
            ->toolbarActions([$this->userDeletionAction()])
            ->emptyStateHeading('Пользователи не найдены')->emptyStateDescription('Измените поиск или выбранный список.')
            ->columns([
                TextColumn::make('id')->label('ID')->sortable()->color('gray'),
                TextColumn::make('name')->label('Пользователь')->sortable()->placeholder('Без имени')->wrap()
                    ->description(fn (array $record): ?string => $record['migration_hold'] ? 'Вход заблокирован: ожидает миграции' : null),
                TextColumn::make('email')->label('Контакты')
                    ->state(fn (array $record): array => array_values(array_filter([$record['email'], $record['phone']])))
                    ->listWithLineBreaks()->wrap()->copyable()->copyMessage('Скопировано')
                    ->placeholder('Не указаны'),
                TextColumn::make('role')->label('Роль')->wrap()
                    ->formatStateUsing(fn (string $state): string => UsersDisplay::role($state))
                    ->visible(fn (): bool => $this->scope === 'staff'),
                TextColumn::make('paid_orders')->label('Оплаченные заказы')->sortable()->tooltip('Сортировка по количеству оплаченных заказов')->state(fn (array $record): array => array_map(
                    fn (array $total): string => number_format($total['amount'] / 100, $total['amount'] % 100 === 0 ? 0 : 2, ',', ' ')
                        .' '.($total['currency'] === 'RUB' ? '₽' : $total['currency']).' · '
                        .trans_choice(':count заказ|:count заказа|:count заказов', $total['count'], [], 'ru'),
                    $record['paid_orders'] ?? [],
                ))->listWithLineBreaks()->wrap()->placeholder('Нет оплаченных заказов')
                    ->visible(fn (): bool => $this->scope !== 'staff' && Rbac::hasPermission($this->actor(), 'payments.read_order')),
                TextColumn::make('created_at')->label('Регистрация')->sortable()
                    ->formatStateUsing(fn (?string $state): string => UsersDisplay::date($state, true))->placeholder('Не указана'),
            ])
            ->filters([
                self::textFilter('name', 'Пользователь'),
                self::textFilter('contact', 'Email или телефон'),
                self::dateFilter('created', 'Регистрация'),
                SelectFilter::make('role')->label('Роль')->options(['admin_full' => 'Администратор', 'admin_limited' => 'Администратор с ограничениями', 'manager' => 'Менеджер', 'teacher' => 'Преподаватель', 'polina' => 'Полина'])
                    ->visible(fn (): bool => $this->scope === 'staff'),
                SelectFilter::make('paid')->label('Оплаченные заказы')->options(['1' => 'Есть', '0' => 'Нет'])
                    ->visible(fn (): bool => $this->scope !== 'staff' && Rbac::hasPermission($this->actor(), 'payments.read_order')),
                SelectFilter::make('scope')->label('Список')->options(['staff' => 'Сотрудники'])->placeholder('Ученики')
                    ->indicateUsing(fn (array $state): ?string => ($state['value'] ?? 'users') === 'staff' ? 'Сотрудники' : null)
                    ->visible(fn (): bool => in_array($this->actor()->role, ['admin_full', 'admin_limited'], true)),
            ], layout: FiltersLayout::Hidden)->deferFilters(false)
            ->recordUrl(fn (array $record): string => '/admin/users/'.$record['id'].'?'.http_build_query($this->listContext()))
            ->recordActions([
                Action::make('impersonate')->label('Открыть ЛК')->color('gray')
                    ->icon('heroicon-o-arrow-right-end-on-rectangle')->iconButton()->tooltip('Открыть ЛК')
                    ->visible(fn (array $record): bool => $this->actor()->role === 'admin_full'
                        && Rbac::hasPermission($this->actor(), 'users.impersonate')
                        && in_array($record['role'], ['user_with_purchase', 'user_no_purchase'], true))
                    ->action(fn (array $record) => $this->impersonate($record['id'])),
            ]);
    }
}
