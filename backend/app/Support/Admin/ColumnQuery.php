<?php

namespace App\Support\Admin;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;

/** Header controls operate on the authorized query, before pagination. */
final class ColumnQuery
{
    public static function search($query, array $columns, mixed $value): void
    {
        if (! is_string($value) || trim($value) === '') {
            return;
        }

        $needle = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower(mb_substr(trim($value), 0, 200))).'%';
        $query->where(function ($query) use ($columns, $needle): void {
            foreach ($columns as $column) {
                $query->orWhereRaw("LOWER(COALESCE({$column}, '')) LIKE ? ESCAPE '!'", [$needle]);
            }
        });
    }

    public static function dates($query, string $column, ?array $range, string $timezone = 'UTC'): void
    {
        $range = array_filter($range ?? [], fn ($value) => $value !== null && $value !== '');
        if (! $range) {
            return;
        }

        $rules = ['from' => 'sometimes|date_format:Y-m-d', 'to' => 'sometimes|date_format:Y-m-d'];
        if (isset($range['from'], $range['to'])) {
            $rules['to'] .= '|after_or_equal:from';
        }
        if (Validator::make($range, $rules)->fails()) {
            $query->whereRaw('1 = 0');

            return;
        }
        if (isset($range['from'])) {
            $query->where($column, '>=', Carbon::parse($range['from'], $timezone)->startOfDay()->utc());
        }
        if (isset($range['to'])) {
            $query->where($column, '<', Carbon::parse($range['to'], $timezone)->addDay()->startOfDay()->utc());
        }
    }

    /** Column names and SQL expressions must be supplied by code, never request input. */
    public static function sort($query, ?string $sort, array $allowed, string $default = 'id:desc', string $tie = 'id'): void
    {
        [$name, $direction] = array_pad(explode(':', $sort ?? $default, 2), 2, 'asc');
        if (! array_key_exists($name, $allowed) || ! in_array($direction, ['asc', 'desc'], true)) {
            [$name, $direction] = explode(':', $default);
        }
        $columns = (array) $allowed[$name];
        $query->reorder();
        foreach ($columns as $column) {
            $query->orderByRaw("CASE WHEN {$column} IS NULL THEN 1 ELSE 0 END")
                ->orderByRaw("{$column} {$direction}");
        }
        if (! in_array($tie, $columns, true)) {
            $query->orderByDesc($tie);
        }
    }
}
