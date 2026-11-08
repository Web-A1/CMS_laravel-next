<?php

namespace App\Support\Admin;

use App\Models\User;
use App\Support\ImpersonationContext;
use App\Support\Rbac;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

abstract class UsersPage extends Page
{
    protected static string $readPermission = 'users.read';

    public static function canAccess(): bool
    {
        $actor = auth('web')->user();
        $actor = $actor instanceof User ? $actor->fresh() : null;

        return $actor instanceof User && $actor->canAccessPanel(Filament::getPanel('admin'))
            && Rbac::hasPermission($actor, static::$readPermission);
    }

    public function hydrate(): void
    {
        $this->actor();
    }

    protected function actor(): User
    {
        abort_unless(static::canAccess(), 403);

        return auth('web')->user()->fresh();
    }

    protected function writeActor(string $permission, string $operation, int $limit = 30): User
    {
        $actor = $this->actor();
        abort_if(ImpersonationContext::isActive(request()), 403);
        abort_unless(Rbac::hasPermission($actor, $permission), 403);
        $key = 'filament:users:'.$operation.':'.$actor->id;
        abort_if(RateLimiter::tooManyAttempts($key, $limit), 429);
        RateLimiter::hit($key, 60);

        return $actor;
    }

    protected function listing(callable $query): array
    {
        try {
            return $query();
        } catch (ValidationException $exception) {
            // Validation raised during rendering cannot use Livewire's action exception handler.
            // Keep invalid filters visible with errors and never execute a fallback query.
            $this->setErrorBag($exception->errors());

            return ['data' => [], 'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 20, 'total' => 0]];
        }
    }
}
