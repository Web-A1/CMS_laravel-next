<?php

namespace App\Filament\Pages;

use App\Services\AdminLegacyUserQueries;
use App\Services\LegacyUsers\LegacyUserSourcePolicy;
use App\Support\Admin\UsersPage;
use App\Support\Rbac;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;

final class LegacyUserDetail extends UsersPage
{
    protected static ?string $slug = 'legacy-users/{sourceMemberId}';
    protected static ?string $title = 'История старого сайта';
    protected static bool $shouldRegisterNavigation = false;
    protected string $view = 'filament.pages.legacy-user-detail';

    #[Locked]
    public string $sourceMemberId;
    #[Url]
    public string $tab = 'summary';
    #[Url]
    public string $category = 'profile';
    #[Url(as: 'orders_page')]
    public int $ordersPage = 1;
    #[Url(as: 'access_page')]
    public int $accessPage = 1;
    #[Url(as: 'records_page')]
    public int $recordsPage = 1;

    public function getBreadcrumbs(): array
    {
        return [
            '/admin/users' => 'Пользователи',
            '/admin/legacy-users' => 'Старый сайт',
            'Пользователь № '.$this->sourceMemberId,
        ];
    }

    public function mount(string $sourceMemberId): void
    {
        $this->sourceMemberId = $sourceMemberId;
        $this->detail();
    }

    public function hydrate(): void
    {
        $this->detail();
    }

    public function updatedCategory(): void
    {
        $this->detail();
        $this->recordsPage = 1;
    }

    private function detail(): array
    {
        return app(AdminLegacyUserQueries::class)->show($this->actor(), [], $this->sourceMemberId);
    }

    protected function getViewData(): array
    {
        $actor = $this->actor();
        $detail = $this->detail();
        $queries = app(AdminLegacyUserQueries::class);
        $tabs = ['summary' => 'Анкета', 'records' => 'Записи'];
        if (Rbac::hasPermission($actor, 'payments.read_order')) {
            $tabs['orders'] = 'Заказы';
        }
        if (Rbac::hasPermission($actor, 'access.read')) {
            $tabs['access'] = 'Доступы';
        }
        abort_unless(array_key_exists($this->tab, $tabs), 403);
        $data = ['detail' => $detail, 'tabs' => $tabs];
        if ($this->tab === 'orders') {
            $data['orders'] = $this->listing(fn () => $queries->orders($actor, ['page' => $this->ordersPage], $this->sourceMemberId, app(LegacyUserSourcePolicy::class)));
        } elseif ($this->tab === 'access') {
            $data['access'] = $this->listing(fn () => $queries->accesses($actor, ['page' => $this->accessPage], $this->sourceMemberId));
        } elseif ($this->tab === 'records') {
            $data['records'] = $this->listing(fn () => $queries->records($actor, ['page' => $this->recordsPage, 'category' => $this->category], $this->sourceMemberId));
        }

        return $data;
    }
}
