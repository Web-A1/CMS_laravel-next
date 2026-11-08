<?php

use App\Providers\AppServiceProvider;

return [
    App\Providers\DemoSafetyProvider::class,
    AppServiceProvider::class,
    App\Providers\Filament\AdminPanelProvider::class,
];
