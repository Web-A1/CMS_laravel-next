<?php

namespace App\Filament\Pages;

use App\Services\AdminLegacyUserQueries;
use App\Support\Admin\UsersPage;
use Livewire\Attributes\Url;

final class LegacyUsers extends UsersPage
{
    use \App\Support\Admin\HasPlainColumnControls;

    public const TABLE_COLUMNS = ['legacy' => [
        'name' => ['label' => 'Пользователь', 'search' => true],
        'contact' => ['label' => 'Контакты', 'search' => true],
        'mapping' => ['label' => 'Связь с новым сайтом', 'options' => ['1' => 'Есть', '0' => 'Нет']],
        'registered' => ['label' => 'Регистрация', 'sort' => true, 'date' => true],
    ]];
    protected static ?string $slug = 'legacy-users';
    protected static ?string $title = 'Пользователи старого сайта';
    protected static bool $shouldRegisterNavigation = false;
    protected string $view = 'filament.pages.legacy-users';

    #[Url]
    public string $q = '';
    #[Url(as: 'unmapped_only')]
    public bool $unmappedOnly = false;
    #[Url]
    public int $page = 1;

    public function getBreadcrumbs(): array
    {
        return ['/admin/users' => 'Пользователи', 'Старый сайт'];
    }

    public function mount(): void
    {
        $this->actor();
        if ($this->unmappedOnly) {
            abort_unless($this->actor()->role === 'admin_full', 403);
            $this->columnFilters['legacy']['mapping'] ??= '0';
            $this->unmappedOnly = false;
        }
    }

    public function updatedUnmappedOnly(): void
    {
        if ($this->unmappedOnly) {
            abort_unless($this->actor()->role === 'admin_full', 403);
            $this->columnFilters['legacy']['mapping'] = '0';
            $this->unmappedOnly = false;
            $this->updatedColumnFilters();
        }
    }

    public function clearPreviousSearch(): void
    {
        $this->q = '';
        $this->search();
    }

    public function search(): void
    {
        $this->actor();
        $this->resetValidation();
        $this->page = 1;
    }

    protected function getViewData(): array
    {
        $actor = $this->actor();

        return [
            'listing' => $this->listing(fn () => app(AdminLegacyUserQueries::class)->index($actor, ['q' => $this->q, 'unmapped_only' => $this->unmappedOnly, 'page' => $this->page], function ($query): void {
                $filters = $this->columnFilters['legacy'] ?? [];
                \App\Support\Admin\ColumnQuery::search($query, ['display_name', 'CAST(source_member_id AS TEXT)'], $filters['name'] ?? null);
                \App\Support\Admin\ColumnQuery::search($query, ['source_email', 'source_phone'], $filters['contact'] ?? null);
                \App\Support\Admin\ColumnQuery::dates($query, 'source_created_at', $filters['registered'] ?? null);
                if (($filters['mapping'] ?? '') === '1') {
                    $query->whereNotNull('user_id');
                } elseif (($filters['mapping'] ?? '') === '0') {
                    abort_unless($this->actor()->role === 'admin_full', 403);
                    $query->whereNull('user_id');
                }
                \App\Support\Admin\ColumnQuery::sort($query, $this->columnSorts['legacy'] ?? null, [
                    'source_member_id' => 'source_member_id', 'registered' => 'source_created_at',
                ], 'source_member_id:asc');
            })),
            'canUnmapped' => $actor->role === 'admin_full',
        ];
    }
}
