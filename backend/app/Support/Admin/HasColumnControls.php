<?php

namespace App\Support\Admin;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Filters\Filter;

trait HasColumnControls
{
    public function columnControlPrefix(): string
    {
        return str_replace('/', '.', static::$slug);
    }

    public function sortColumn(string $name): void
    {
        if (! $this->getTable()->getColumn($name)?->isSortable()) {
            return;
        }

        $direction = $this->getTableSortColumn() === $name && $this->getTableSortDirection() === 'asc' ? 'desc' : 'asc';
        $this->sortTable($name, $direction);
    }

    public function columnFilterIsActive(string $column): bool
    {
        foreach (static::COLUMN_FILTERS[$column] ?? [] as $name) {
            if ($this->getTable()->getFilter($name)?->getIndicators()) {
                return true;
            }
        }

        return false;
    }

    public function clearColumnFilters(string $column): void
    {
        foreach (static::COLUMN_FILTERS[$column] ?? [] as $name) {
            $this->removeTableFilter($name);
        }
    }

    public function chooseColumnFilter(string $column, ?string $value): void
    {
        $name = static::CHOICE_FILTERS[$column] ?? null;
        $filter = $name ? $this->getTable()->getFilter($name) : null;
        if (! $filter || ! $filter->isVisible() || ($value !== null && ! array_key_exists($value, $filter->getOptions()))) {
            return;
        }

        $this->tableFilters[$name]['value'] = $value;
        $this->updatedTableFilters();
    }

    protected static function textFilter(string $name, string $label): Filter
    {
        return Filter::make($name)->schema([
            TextInput::make('value')->label($label)->maxLength(200)->live(debounce: 400),
        ])->indicateUsing(fn (array $data) => filled($data['value'] ?? null) ? $label.': '.$data['value'] : null);
    }

    protected static function dateFilter(string $name, string $label): Filter
    {
        return Filter::make($name)->schema([
            DatePicker::make('from')->label($label.' с')->native(),
            DatePicker::make('to')->label($label.' по')->native()->afterOrEqual('from'),
        ])->indicateUsing(fn (array $data) => array_filter([
            filled($data['from'] ?? null) ? $label.' с '.$data['from'] : null,
            filled($data['to'] ?? null) ? $label.' по '.$data['to'] : null,
        ]));
    }

    protected function columnQuery(): \Closure
    {
        return function ($query): void {
            foreach (static::QUERY_SEARCHES as $name => $columns) {
                ColumnQuery::search($query, (array) $columns, $this->tableFilters[$name]['value'] ?? null);
            }
            foreach (static::QUERY_DATES as $name => $column) {
                ColumnQuery::dates($query, $column, $this->tableFilters[$name] ?? null);
            }
            foreach (static::QUERY_CHOICES as $name => $column) {
                $value = $this->tableFilters[$name]['value'] ?? null;
                $filter = $this->getTable()->getFilter($name);
                if ($value !== null && $value !== '' && $filter?->isVisible() && array_key_exists($value, $filter->getOptions())) {
                    $query->where($column, $value);
                }
            }
            ColumnQuery::sort($query, $this->tableSort, static::QUERY_SORTS, static::QUERY_DEFAULT_SORT);
        };
    }
}
