<?php

namespace App\Support\Admin;

use Livewire\Attributes\Url;

trait HasPlainColumnControls
{
    #[Url(as: 'columns')]
    public array $columnFilters = [];

    #[Url(as: 'sorts')]
    public array $columnSorts = [];

    public function columnSort(string $table): string
    {
        $defaults = defined(static::class.'::TABLE_DEFAULT_SORTS') ? static::TABLE_DEFAULT_SORTS : [];

        return $this->columnSorts[$table] ?? ($defaults[$table] ?? '');
    }

    public function updatedColumnFilters(): void
    {
        $this->resetValidation();
        if (property_exists($this, 'page')) {
            $this->page = 1;
        }
        if (property_exists($this, 'ordersPage')) {
            $this->ordersPage = 1;
        }
    }

    public function sortColumn(string $table, string $column): void
    {
        if (! (static::TABLE_COLUMNS[$table][$column]['sort'] ?? false)) {
            return;
        }
        $this->columnSorts[$table] = $column.':'.($this->columnSort($table) === $column.':asc' ? 'desc' : 'asc');
        $this->updatedColumnFilters();
    }

    public function chooseColumnFilter(string $table, string $column, string $value): void
    {
        $options = static::TABLE_COLUMNS[$table][$column]['options'] ?? [];
        if ($value !== '' && ! array_key_exists($value, $options)) {
            return;
        }
        $this->columnFilters[$table][$column] = $value;
        $this->updatedColumnFilters();
    }

    public function clearColumnFilter(string $table, string $column): void
    {
        if (! isset(static::TABLE_COLUMNS[$table][$column])) {
            return;
        }
        unset($this->columnFilters[$table][$column]);
        $this->updatedColumnFilters();
    }
}
