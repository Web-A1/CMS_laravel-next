@foreach ($this::TABLE_COLUMNS[$tableKey] as $name => $config)
    @php
        $label = $config['label'];
        $sort = $this->columnSort($tableKey);
        $sorted = str_starts_with($sort, $name.':');
        $direction = $sorted ? substr($sort, strlen($name) + 1) : null;
        $value = $this->columnFilters[$tableKey][$name] ?? '';
        $active = is_array($value) ? (bool) array_filter($value) : filled($value);
        $path = 'columnFilters.'.$tableKey.'.'.$name;
        $options = $config['options'] ?? [];
        if ($tableKey === 'legacy' && $name === 'mapping' && ! ($canUnmapped ?? false)) {
            $options = [];
        }
    @endphp
    <th scope="col" class="fi-ta-header-cell" data-column-label="{{ $label }}"
        @if ($config['sort'] ?? false) aria-sort="{{ $sorted ? ($direction === 'asc' ? 'ascending' : 'descending') : 'none' }}" @endif>
        <div class="admin-review-column-heading">
            @if ($config['sort'] ?? false)
                <button type="button" class="fi-ta-header-cell-sort-btn" wire:click="sortColumn('{{ $tableKey }}', '{{ $name }}')" title="{{ $label }}: изменить порядок">
                    <span>{{ $label }}</span>
                    <x-filament::icon :icon="$sorted ? ($direction === 'asc' ? 'heroicon-m-arrow-up' : 'heroicon-m-arrow-down') : 'heroicon-m-arrows-up-down'" class="fi-icon fi-size-sm" />
                </button>
            @else
                <span class="admin-review-column-label">{{ $label }}</span>
            @endif
            @if ($config['search'] ?? false)
                <div class="admin-review-text-search">
                    <x-filament::input.wrapper>
                        <div class="admin-review-text-input">
                            <x-filament::input type="search" wire:model.live.debounce.400ms="{{ $path }}" aria-label="Поиск: {{ $label }}" placeholder="Поиск по тексту" maxlength="200" />
                            @if ($active)
                                <x-filament::icon-button icon="heroicon-m-x-mark" color="gray" size="sm" label="Очистить поиск: {{ $label }}" wire:click="clearColumnFilter('{{ $tableKey }}', '{{ $name }}')" />
                            @endif
                        </div>
                    </x-filament::input.wrapper>
                </div>
            @elseif ($options || ($config['date'] ?? false))
                <x-filament::dropdown placement="bottom-start" :teleport="true" :shift="true" :size="true" :width="$options ? 'admin-column-choice-menu' : 'xs'" :menu="(bool) $options" class="admin-review-column-filter" wire:key="column-{{ $tableKey }}-{{ $name }}">
                    <x-slot name="trigger">
                        <x-filament::icon-button icon="heroicon-m-funnel" :color="$active ? 'primary' : 'gray'" size="sm" :label="'Фильтр: '.$label.($active ? ' (применён)' : '')"
                            data-filter-active="{{ $active ? 'true' : 'false' }}" x-bind:aria-expanded="isOpen" :aria-haspopup="$options ? 'menu' : 'dialog'" />
                    </x-slot>
                    @if ($options)
                        <x-filament::dropdown.list aria-label="{{ $label }}">
                            @foreach (['' => 'Все'] + $options as $option => $optionLabel)
                                @php($action = 'chooseColumnFilter('.\Illuminate\Support\Js::from($tableKey).', '.\Illuminate\Support\Js::from($name).', '.\Illuminate\Support\Js::from((string) $option).')')
                                <x-filament::dropdown.list.item :wire:click="$action" x-on:click.stop="close(); $nextTick(() => $root.querySelector('button')?.focus())" role="menuitemradio"
                                    :aria-checked="(string) $value === (string) $option ? 'true' : 'false'" :icon="(string) $value === (string) $option ? 'heroicon-m-check' : null">
                                    {{ $optionLabel }}
                                </x-filament::dropdown.list.item>
                            @endforeach
                        </x-filament::dropdown.list>
                    @else
                        <div class="admin-review-filter-content" role="dialog" aria-label="{{ $label }}">
                            <label>С<x-filament::input.wrapper><x-filament::input type="date" wire:model.live="{{ $path }}.from" aria-label="{{ $label }} с" /></x-filament::input.wrapper></label>
                            <label>По<x-filament::input.wrapper><x-filament::input type="date" wire:model.live="{{ $path }}.to" aria-label="{{ $label }} по" /></x-filament::input.wrapper></label>
                            <div class="admin-review-filter-actions">
                                <x-filament::link tag="button" color="gray" size="sm" wire:click="clearColumnFilter('{{ $tableKey }}', '{{ $name }}')">Сбросить</x-filament::link>
                                <x-filament::button size="sm" color="gray" x-on:click="close()">Готово</x-filament::button>
                            </div>
                        </div>
                    @endif
                </x-filament::dropdown>
            @endif
        </div>
    </th>
@endforeach
