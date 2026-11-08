<?php

namespace App\Providers;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;
use LogicException;

class DemoSafetyProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (! config('demo.enabled')) {
            throw new LogicException('This showcase copy requires DEMO_MODE=true.');
        }

        $database = config('database.connections.sqlite.database');
        $testMemory = app()->environment('testing') && $database === ':memory:';
        $demoFile = is_string($database) && realpath($database) === realpath(database_path('demo.sqlite'))
            && realpath($database) !== false;

        if (config('database.default') !== 'sqlite' || (! $testMemory && ! $demoFile)) {
            throw new LogicException('Only the candidate demo.sqlite or an in-memory test database is allowed.');
        }

        if (! app()->environment(['local', 'testing'])) {
            throw new LogicException('This copy is for local demonstration only.');
        }

        // Covers Laravel HTTP integrations. Mail uses array transport and intake is blocked.
        Http::preventStrayRequests();
        config(['mail.default' => 'array', 'services.redsms.mode' => 'mock',
            'services.kinescope.enabled' => false, 'services.smtpbz.enabled' => false,
            'support.imap_enabled' => false, 'robokassa.enabled' => false,
            'podeli.enabled' => false, 'yandex_split.enabled' => false,
            'payment_notifications.enabled' => false, 'fiscal.enabled' => false]);
    }
}

