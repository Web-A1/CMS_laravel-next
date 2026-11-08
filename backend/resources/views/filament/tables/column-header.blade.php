@php
    $name = $column->getName();
    $label = $column->getLabel();
    $sortable = $column->isSortable() && ! $isReordering;
    $sorted = $sortable && $this->getTableSortColumn() === $name;
    $direction = $sorted ? $this->getTableSortDirection() : null;
    $filters = $this::COLUMN_FILTERS[$name] ?? [];
    $search = $this::COLUMN_SEARCHES[$name] ?? null;
    $prefix = $this->columnControlPrefix();
@endphp
<th scope="col" class="fi-ta-header-cell fi-ta-header-cell-{{ str($name)->kebab() }} {{ $sorted ? 'fi-ta-header-cell-sorted' : '' }}"
    @if ($minimumWidth = $column->getExtraHeaderAttributes()['data-admin-column-min-width'] ?? null) data-admin-column-min-width="{{ $minimumWidth }}" @endif
    data-column-label="{{ $label }}" @if ($sortable) aria-sort="{{ $sorted ? ($direction === 'asc' ? 'ascending' : 'descending') : 'none' }}" @endif>
    <div class="admin-review-column-heading">
        @if ($sortable)
            <button type="button" wire:click="sortColumn('{{ $name }}')" class="fi-ta-header-cell-sort-btn"
                data-ui="{{ $prefix }}.sort.{{ $name }}" title="{{ $label }}: изменить порядок">
                <span>{{ $label }}</span>
                <x-filament::icon :icon="$sorted ? ($direction === 'asc' ? 'heroicon-m-arrow-up' : 'heroicon-m-arrow-down') : 'heroicon-m-arrows-up-down'"
                    class="fi-icon fi-size-sm" />
            </button>
        @else
            <span class="admin-review-column-label">{{ $label }}</span>
        @endif
        @if ($search)
            <div class="admin-review-text-search">
                <x-filament::input.wrapper>
                    <div class="admin-review-text-input">
                        <x-filament::input type="search" wire:model.live.debounce.400ms="tableFilters.{{ $search }}.value"
                            data-ui="{{ $prefix }}.search.{{ $name }}" name="{{ $prefix }}-{{ $name }}" aria-label="Поиск: {{ $label }}"
                            placeholder="Поиск по тексту" maxlength="200" />
                        <x-filament::icon-button icon="heroicon-m-x-mark" color="gray" size="sm"
                            label="Очистить поиск: {{ $label }}" wire:click="clearColumnFilters('{{ $name }}')"
                            x-show="$wire.tableFilters?.{{ $search }}?.value" x-cloak />
                    </div>
                </x-filament::input.wrapper>
                @error('tableFilters.'.$search.'.value')
                    <span class="fi-fo-field-wrp-error-message" role="alert">{{ $message }}</span>
                @enderror
            </div>
        @elseif ($filters)
            @include('filament.tables.column-filter', ['filterKey' => $name, 'filterLabel' => $label, 'filterNames' => $filters])
        @endif
    </div>
</th>
