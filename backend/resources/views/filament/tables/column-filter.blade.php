@php
    $active = $this->columnFilterIsActive($filterKey);
    $choiceName = $this::CHOICE_FILTERS[$filterKey] ?? null;
    $choices = $choiceName ? ['' => 'Все'] + $this->getTable()->getFilter($choiceName)->getOptions() : [];
    $selected = $choiceName ? (string) ($this->tableFilters[$choiceName]['value'] ?? '') : null;
@endphp
<x-filament::dropdown placement="bottom-start" :teleport="true" :shift="true" :size="true"
    :width="$choiceName ? 'admin-column-choice-menu' : 'xs'" :menu="(bool) $choiceName" wire:key="column-filter-{{ $filterKey }}" class="admin-review-column-filter">
    <x-slot name="trigger">
        <x-filament::icon-button icon="heroicon-m-funnel" :color="$active ? 'primary' : 'gray'" size="sm"
            :label="'Фильтр: '.$filterLabel.($active ? ' (применён)' : '')"
            data-ui="{{ $prefix }}.filter.{{ $filterKey }}" data-filter-active="{{ $active ? 'true' : 'false' }}"
            x-bind:aria-expanded="isOpen" :aria-haspopup="$choiceName ? 'menu' : 'dialog'" />
    </x-slot>
    @if ($choiceName)
        <x-filament::dropdown.list aria-label="{{ $filterLabel }}">
            @foreach ($choices as $value => $choiceLabel)
                @php($choiceAction = 'chooseColumnFilter('.\Illuminate\Support\Js::from($filterKey).', '.\Illuminate\Support\Js::from($value === '' ? null : (string) $value).')')
                <x-filament::dropdown.list.item
                    :wire:click="$choiceAction"
                    x-on:click.stop="close(); $nextTick(() => $root.querySelector('button')?.focus())"
                    role="menuitemradio" :aria-checked="$selected === (string) $value ? 'true' : 'false'"
                    :icon="$selected === (string) $value ? 'heroicon-m-check' : null"
                    :color="$selected === (string) $value ? 'primary' : 'gray'">
                    {{ $choiceLabel }}
                </x-filament::dropdown.list.item>
            @endforeach
        </x-filament::dropdown.list>
    @else
    <div class="admin-review-filter-content" role="dialog" aria-label="{{ $filterLabel }}"
        x-on:keydown.escape.stop.prevent="close(); $nextTick(() => $root.querySelector('button')?.focus())">
        @foreach ($filterNames as $filterName)
            {{ $this->getTableFiltersForm()->getComponentByStatePath($filterName)?->getChildSchema() }}
        @endforeach
        <div class="admin-review-filter-actions">
            <x-filament::link tag="button" color="gray" size="sm"
                wire:click="clearColumnFilters('{{ $filterKey }}')">Сбросить</x-filament::link>
            <x-filament::button size="sm" color="gray" x-on:click="close()">Готово</x-filament::button>
        </div>
    </div>
    @endif
</x-filament::dropdown>
