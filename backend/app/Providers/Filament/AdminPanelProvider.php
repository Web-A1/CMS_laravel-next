<?php

namespace App\Providers\Filament;

use App\Filament\Auth\LogoutController;
use App\Filament\Auth\LogoutResponse;
use App\Filament\Livewire\PublicHeader;
use App\Filament\Pages\Articles;
use App\Filament\Pages\AssessmentReviewDetail;
use App\Filament\Pages\AssessmentReviews;
use App\Filament\Pages\FinancialReport;
use App\Filament\Pages\LegacyUserDetail;
use App\Filament\Pages\LegacyUsers;
use App\Filament\Pages\Operations\Faqs;
use App\Filament\Pages\Operations\Orders;
use App\Filament\Pages\Operations\Promos;
use App\Filament\Pages\Operations\ShortLinks;
use App\Filament\Pages\Operations\SupportTicketDetail;
use App\Filament\Pages\Operations\SupportTickets;
use App\Filament\Pages\Operations\Tasks;
use App\Filament\Pages\Reviews\CreateReview;
use App\Filament\Pages\Reviews\EditReview;
use App\Filament\Pages\Reviews\Reviews;
use App\Filament\Pages\StaffProfile;
use App\Filament\Pages\UserDetail;
use App\Filament\Pages\Users;
use App\Filament\Pages\Watermark;
use App\Http\Middleware\AdminPanelUrls;
use App\Http\Middleware\EnsureFilamentImpersonation;
use App\Http\Middleware\EnsureFilamentStaff;
use App\Http\Middleware\EnsureMigrationAccountActive;
use App\Support\Rbac;
use Filament\Actions\Action;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Enums\Width;
use Filament\Tables\View\TablesRenderHook;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

final class AdminPanelProvider extends PanelProvider
{
    public function register(): void
    {
        parent::register();
        $this->app->bind(\Filament\Auth\Http\Controllers\LogoutController::class, LogoutController::class);
        $this->app->bind(\Filament\Auth\Http\Responses\Contracts\LogoutResponse::class, LogoutResponse::class);
    }

    public function boot(): void
    {
        Livewire::setUpdateRoute(fn ($handle, $path) => Route::post($path, $handle)->middleware([
            'web',
            AdminPanelUrls::class,
            EnsureMigrationAccountActive::class,
            EnsureFilamentStaff::class,
            EnsureFilamentImpersonation::class,
        ]));
    }

    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->authGuard('web')
            ->brandName('Christmedschool')
            ->favicon('/icons/admin.svg')
            ->topbarLivewireComponent(PublicHeader::class)
            ->sidebarWidth('14rem')
            ->maxContentWidth(Width::Full)
            ->navigationGroups([
                NavigationGroup::make()->label('Инструменты')->collapsible(),
            ])
            // Render within the panel URL scope, before Livewire's later
            // RequestHandled auto-injection (which runs after middleware).
            ->renderHook(PanelsRenderHook::STYLES_BEFORE, fn () => view('filament.livewire-styles'))
            ->renderHook(PanelsRenderHook::STYLES_AFTER, fn () => view('filament.panel-styles'))
            ->renderHook(PanelsRenderHook::STYLES_AFTER, fn () => view('filament.article-document-styles'))
            ->renderHook(PanelsRenderHook::STYLES_AFTER, fn () => view('filament.public-header-styles'))
            ->renderHook(PanelsRenderHook::SCRIPTS_AFTER, fn () => view('filament.livewire-scripts'))
            ->renderHook(PanelsRenderHook::SCRIPTS_AFTER, fn () => view('filament.table-resizing'))
            ->renderHook(PanelsRenderHook::STYLES_AFTER, fn () => view('filament.tables.column-styles'))
            ->renderHook(TablesRenderHook::HEADER_CELL, fn (array $data) => view('filament.tables.column-header', $data), scopes: [
                Reviews::class,
                Articles::class,
                Faqs::class,
                Users::class,
                Orders::class,
                Tasks::class,
                SupportTickets::class,
                Promos::class,
                ShortLinks::class,
            ])
            ->homeUrl(fn (): string => Users::canAccess()
                ? '/admin/users'
                : (AssessmentReviews::canAccess() ? '/admin/assessment-reviews' : '/admin/settings'))
            ->authenticatedRoutes(function (): void {
                Route::get('/profile', fn () => redirect('/admin/settings'))->name('legacy-profile');
                Route::get('/users/legacy', function () {
                    $query = http_build_query(request()->query());

                    return redirect('/admin/legacy-users'.($query === '' ? '' : '?'.$query));
                })->name('legacy-users-list');
                Route::get('/users/legacy/{sourceMemberId}', function (string $sourceMemberId) {
                    $query = http_build_query(request()->query());

                    return redirect('/admin/legacy-users/'.$sourceMemberId.($query === '' ? '' : '?'.$query));
                })->whereNumber('sourceMemberId')->name('legacy-user-detail');
            })
            ->pages([
                Articles::class, // Publication tools enforce full-admin authorization on every mutation.
                Faqs::class,
                StaffProfile::class,
                Users::class,
                UserDetail::class,
                LegacyUsers::class,
                LegacyUserDetail::class,
                Watermark::class,
                Reviews::class,
                CreateReview::class,
                EditReview::class,
                FinancialReport::class,
                AssessmentReviews::class,
                AssessmentReviewDetail::class,
                Orders::class,
                SupportTickets::class,
                Tasks::class,
                SupportTicketDetail::class,
                ShortLinks::class,
                Promos::class,
            ])
            ->userMenuItems([
                'profile' => Action::make('profile')->label('Настройки')->url('/admin/settings')
                    ->visible(fn () => Rbac::hasPermission(auth('web')->user()->fresh(), 'admin.self.view')),
                Action::make('site')->label('На сайт')->url('/'),
            ])
            ->middleware([
                'web',
                AdminPanelUrls::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                EnsureMigrationAccountActive::class,
                EnsureFilamentStaff::class,
                EnsureFilamentImpersonation::class,
            ], isPersistent: true);
    }
}
