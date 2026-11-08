<x-filament-panels::page>
    @include('filament.tables.previous-search', ['previousSearch' => $filters['q'] ?? ''])
    <form wire:submit="applyFilters" style="display:grid;gap:16px" data-ui="admin.reports.filters">
        {{ $this->form }}
        <div style="display:flex;flex-wrap:wrap;gap:12px">
            <x-filament::button type="submit">Обновить</x-filament::button>
            <x-filament::button color="gray" wire:click="currentMonth">Текущий месяц</x-filament::button>
            <x-filament::button tag="a" :href="$exportUrl" color="gray">Экспорт заказов CSV</x-filament::button>
        </div>
    </form>
    @php
        $money = fn ($value) => number_format($value / 100, 2, ',', ' ').' '.$report['meta']['currency'];
        $summary = $report['summary'];
        $previous = $report['comparison']['paid_amount'];
        $maxRevenue = max(1, ...array_column($report['periods'], 'paid_amount'));
    @endphp
    <x-filament::section heading="Выручка" data-ui="admin.reports.workspace">
        <p style="font-size:1.875rem;line-height:1.3;font-weight:600">{{ $money($summary['paid_amount']) }}</p>
        <p>{{ $report['meta']['from'] }} — {{ $report['meta']['to'] }} · {{ $report['meta']['timezone'] }}</p>
        <p style="margin-top:8px">Аналогичный период прошлого года: {{ $money($previous) }}
            @if($previous > 0) ({{ number_format(($summary['paid_amount'] - $previous) / $previous * 100, 1, ',', ' ') }}%)
            @else — нет данных для сравнения @endif
        </p>
        <dl style="margin-top:16px;display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:16px">
            <div><dt>Продано курсов</dt><dd style="font-size:1.25rem">{{ $summary['sold_courses'] }}</dd></div>
            <div><dt>Покупатели, уникальные</dt><dd style="font-size:1.25rem">{{ $summary['buyers'] }}</dd></div>
            <div><dt>Средний чек за курс</dt><dd style="font-size:1.25rem">{{ $summary['sold_courses'] ? $money(round($summary['paid_amount'] / $summary['sold_courses'])) : '—' }}</dd></div>
            <div><dt>Возвраты</dt><dd style="font-size:1.25rem">{{ $summary['refunded_amount'] ? $money($summary['refunded_amount']) : 'Нет' }}</dd>@if($summary['refunded_amount'] && $summary['paid_amount'])<p style="font-size:0.875rem">{{ number_format($summary['refunded_amount'] / $summary['paid_amount'] * 100, 1, ',', ' ') }}% от выручки</p>@endif</div>
        </dl>
        <p style="margin-top:16px;font-size:0.875rem;opacity:0.75">Выручка считается по оплаченным продажам. Legacy отражает исторические статусы оплаты и импортированную сумму корзины.</p>
    </x-filament::section>
    <x-filament::section heading="Динамика выручки">
        <div style="overflow-x:auto;max-width:100%">
            <div role="img" aria-label="Динамика выручки по периодам" style="display:flex;align-items:flex-end;gap:8px;height:220px;min-width:max-content">
                @foreach($report['periods'] as $period)
                    <div style="width:60px;text-align:center" title="{{ $period['bucket'] }}: {{ $money($period['paid_amount']) }}">
                        <div style="height:{{ max(2, round($period['paid_amount'] / $maxRevenue * 160)) }}px;background:var(--primary-500,#d97706);margin:0 12px"></div>
                        <span style="font-size:0.75rem">{{ $period['bucket'] }}</span>
                    </div>
                @endforeach
            </div>
        </div>
        <details wire:ignore.self style="margin-top:16px"><summary>Данные по периодам</summary>
            <div style="overflow-x:auto;max-width:100%"><table style="width:100%;font-size:0.875rem"><thead><tr>@include('filament.tables.plain-header', ['tableKey' => 'periods'])</tr></thead><tbody>
                @forelse($periodRows as $period)<tr><td style="padding:8px">{{ $period['bucket'] }}</td><td style="text-align:center">{{ $period['paid_orders'] }}</td><td style="text-align:center">{{ $money($period['paid_amount']) }}</td></tr>@empty<tr><td colspan="3">Нет данных</td></tr>@endforelse
            </tbody></table></div>
        </details>
    </x-filament::section>
    <x-filament::section heading="Продажи по продуктам">
        <div style="overflow-x:auto;max-width:100%"><table style="width:100%;font-size:0.875rem"><thead><tr>@include('filament.tables.plain-header', ['tableKey' => 'products'])</tr></thead><tbody>
            @forelse($productRows as $product)<tr><td style="padding:8px">{{ $product['label'] }}</td><td style="text-align:center">{{ $product['orders'] }}</td><td style="text-align:center">{{ $product['quantity'] }}</td><td style="text-align:center">{{ $money($product['amount']) }}</td></tr>@empty<tr><td style="padding:8px" colspan="4">Продаж за выбранный период нет.</td></tr>@endforelse
        </tbody></table></div>
    </x-filament::section>
    <x-filament::section heading="Заказы">
        <div style="overflow-x:auto;max-width:100%"><table style="width:100%;font-size:0.875rem"><thead><tr>@include('filament.tables.plain-header', ['tableKey' => 'orders'])</tr></thead><tbody>
            @forelse($report['orders']['rows'] as $order)<tr><td style="padding:8px">{{ $order['number'] }}<br>{{ $order['created_at'] ? \Illuminate\Support\Carbon::parse($order['created_at'])->timezone($report['meta']['timezone'])->format('d.m.Y H:i') : '—' }}</td><td>{{ $order['customer'] }}</td><td>{{ $order['product'] }}</td><td style="text-align:center">{{ $money($order['amount']) }}</td><td style="text-align:center">{{ $order['status_label'] ?? $order['status'] }}<br>{{ $order['source'] === 'legacy' ? 'Legacy' : 'Текущий сайт' }}</td></tr>@empty<tr><td style="padding:8px" colspan="5">Заказов за выбранный период нет.</td></tr>@endforelse
        </tbody></table></div>
        <div style="margin-top:16px;display:flex;align-items:center;gap:12px">
            @if($report['orders']['page'] > 1)<x-filament::button color="gray" wire:click="goToPage({{ $report['orders']['page'] - 1 }})">Назад</x-filament::button>@endif
            <span>Страница {{ $report['orders']['page'] }} · Всего {{ $report['orders']['total'] }}</span>
            @if($report['orders']['page'] * $report['orders']['per_page'] < $report['orders']['total'])<x-filament::button color="gray" wire:click="goToPage({{ $report['orders']['page'] + 1 }})">Далее</x-filament::button>@endif
        </div>
    </x-filament::section>
</x-filament-panels::page>
