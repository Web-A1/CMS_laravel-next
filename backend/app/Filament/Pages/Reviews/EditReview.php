<?php

namespace App\Filament\Pages\Reviews;

use Filament\Pages\PageConfiguration;
use Filament\Panel;
use Illuminate\Support\Facades\Route;

final class EditReview extends ReviewEditor
{
    protected static ?string $slug = 'reviews/{reviewId}';

    public static function routes(Panel $panel, ?PageConfiguration $configuration = null): void
    {
        Route::get(static::getRoutePath($panel), static::class)
            ->whereNumber('reviewId')
            ->middleware(static::getRouteMiddleware($panel))
            ->name(static::getRelativeRouteName($panel));
    }
}
