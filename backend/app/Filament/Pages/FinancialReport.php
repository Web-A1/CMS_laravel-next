<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Services\AdminFinancialReportService;
use App\Support\Rbac;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Schemas\Schema;

final class FinancialReport extends Page
{
    use \App\Support\Admin\HasPlainColumnControls;

    public const TABLE_DEFAULT_SORTS = ['periods' => 'bucket:asc', 'orders' => 'created_at:desc'];

    public const TABLE_COLUMNS = [
        'periods' => [
            'bucket' => ['label' => 'Период', 'sort' => true],
            'paid_orders' => ['label' => 'Оплаченные заказы', 'sort' => true],
            'paid_amount' => ['label' => 'Выручка', 'sort' => true],
        ],
        'products' => [
            'label' => ['label' => 'Продукт', 'search' => true],
            'orders' => ['label' => 'Заказы', 'sort' => true],
            'quantity' => ['label' => 'Количество', 'sort' => true],
            'amount' => ['label' => 'Сумма', 'sort' => true],
        ],
        'orders' => [
            'created_at' => ['label' => 'Номер / дата', 'sort' => true],
            'customer' => ['label' => 'Покупатель', 'search' => true],
            'product' => ['label' => 'Продукт', 'search' => true],
            'amount' => ['label' => 'Сумма', 'sort' => true],
            'status' => ['label' => 'Статус / источник', 'options' => ['paid' => 'Оплачен', 'unpaid' => 'Не оплачен', 'refunded' => 'Возврат', 'cancelled' => 'Отменён']],
        ],
    ];
    protected static ?string $slug = 'reports/financial';
    protected static ?string $title = 'Финансовые отчёты';
    protected string $view = 'filament.pages.financial-report';

    #[\Livewire\Attributes\Url(as: 'report')]
    public ?array $filters = [];
    #[\Livewire\Attributes\Url]
    public int $ordersPage = 1;

    public static function canAccess(): bool
    {
        $actor = auth('web')->user();
        $actor = $actor instanceof User ? $actor->fresh() : null;

        return $actor instanceof User && $actor->canAccessPanel(Filament::getPanel('admin'))
            && Rbac::hasPermission($actor, 'reports.read');
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $today = now(config('app.report_timezone', 'Europe/Moscow'));
        $this->form->fill(array_replace([
            'source' => 'all', 'status' => 'all', 'granularity' => 'day', 'q' => '',
            'from' => $today->copy()->startOfMonth()->toDateString(), 'to' => $today->toDateString(),
        ], request()->only(['source', 'status', 'granularity', 'q', 'from', 'to']), $this->filters ?? []));
    }

    public function hydrate(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('source')->label('Источник')->options(['all' => 'Все источники', 'current' => 'Текущий сайт', 'legacy' => 'Legacy'])->required(),
            Select::make('status')->label('Статус')->options(['all' => 'Все статусы', 'paid' => 'Оплачены', 'unpaid' => 'Не оплачены', 'refunded' => 'Возвраты', 'cancelled' => 'Отменены'])->required(),
            Select::make('granularity')->label('Разбивка')->options(['day' => 'По дням', 'week' => 'По неделям', 'month' => 'По месяцам'])->required(),
            DatePicker::make('from')->label('С')->required(),
            DatePicker::make('to')->label('По')->required()->afterOrEqual('from'),
        ])->columns(['default' => 1, 'md' => 3])->statePath('filters');
    }

    public function applyFilters(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->form->getState();
        $this->ordersPage = 1;
    }

    public function currentMonth(): void
    {
        abort_unless(static::canAccess(), 403);
        $today = now(config('app.report_timezone', 'Europe/Moscow'));
        $this->filters['from'] = $today->copy()->startOfMonth()->toDateString();
        $this->filters['to'] = $today->toDateString();
        $this->ordersPage = 1;
    }

    public function goToPage(int $page): void
    {
        abort_unless(static::canAccess(), 403);
        $this->ordersPage = max(1, $page);
    }

    public function clearPreviousSearch(): void
    {
        $this->filters['q'] = '';
        $this->updatedColumnFilters();
    }

    private function tableRows(array $rows, string $table): array
    {
        $search = $this->columnFilters[$table]['label'] ?? '';
        if (is_string($search) && trim($search) !== '') {
            $rows = array_values(array_filter($rows, fn ($row) => mb_stripos($row['label'], trim($search)) !== false));
        }
        [$column, $direction] = array_pad(explode(':', $this->columnSort($table), 2), 2, 'asc');
        if (static::TABLE_COLUMNS[$table][$column]['sort'] ?? false) {
            usort($rows, fn ($a, $b) => ($direction === 'desc' ? -1 : 1) * ($a[$column] <=> $b[$column])
                ?: strcmp($a['label'] ?? $a['bucket'], $b['label'] ?? $b['bucket']));
        }

        return $rows;
    }

    protected function getViewData(): array
    {
        abort_unless(static::canAccess(), 403);
        $query = array_intersect_key($this->filters ?? [], array_flip(['from', 'to', 'source', 'status', 'granularity', 'q']));
        $query = array_filter($query, fn ($value) => $value !== '' && $value !== null);
        $query['view'] = 'workspace';
        $query['page'] = max(1, $this->ordersPage);
        foreach (['customer', 'product', 'status'] as $field) {
            if (filled($this->columnFilters['orders'][$field] ?? null)) {
                $query['order_'.$field] = $this->columnFilters['orders'][$field];
            }
        }
        if (isset($this->columnSorts['orders'])) {
            $query['order_sort'] = $this->columnSorts['orders'];
        }
        $report = app(AdminFinancialReportService::class)->report($query);

        return [
            'report' => $report,
            'periodRows' => $this->tableRows($report['periods'], 'periods'),
            'productRows' => $this->tableRows($report['breakdowns']['products']['rows'], 'products'),
            'exportUrl' => '/backend/api/v1/admin/reports/financial?'.http_build_query([...$query, 'export' => 'orders']),
        ];
    }
}
