<?php

namespace App\Providers;

use App\Contracts\Kinescope\KinescopePlaybackToken;
use App\Contracts\ProtectedFiles\ProtectedPdfSourceNormalizer;
use App\Contracts\ProtectedFiles\ProtectedPdfWatermarker;
use App\Contracts\ProtectedFiles\TemporaryDownloadUrlIssuer;
use App\Services\Kinescope\JwtKinescopePlaybackToken;
use App\Services\ProtectedFiles\FilesystemTemporaryDownloadUrlIssuer;
use App\Services\ProtectedFiles\FpdiProtectedPdfWatermarker;
use App\Services\ProtectedFiles\QpdfProtectedPdfSourceNormalizer;
use App\Services\TrustedBrowser\TrustedBrowserEncryptCookies;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use LogicException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(EncryptCookies::class, TrustedBrowserEncryptCookies::class);
        $this->app->bind(KinescopePlaybackToken::class, JwtKinescopePlaybackToken::class);
        $this->app->bind(ProtectedPdfSourceNormalizer::class, QpdfProtectedPdfSourceNormalizer::class);
        $this->app->bind(ProtectedPdfWatermarker::class, FpdiProtectedPdfWatermarker::class);
        $this->app->bind(TemporaryDownloadUrlIssuer::class, FilesystemTemporaryDownloadUrlIssuer::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ((string) config('app.env') === 'production' && (bool) config('app.debug')) {
            config(['app.debug' => false]);

            throw new LogicException('APP_DEBUG must be disabled in production.');
        }

        if (! in_array((string) config('trusted_browser.mode', 'off'), ['off', 'observe', 'enforce'], true)) {
            throw new LogicException('TRUSTED_BROWSER_MODE must be off, observe, or enforce.');
        }

        if (
            (string) config('services.redsms.mode', 'mock') === 'mock'
            && ! in_array((string) config('app.env'), ['local', 'testing'], true)
        ) {
            throw new LogicException('REDSMS mock mode is allowed only in local and testing environments.');
        }

        RateLimiter::for('kinescope-callback', function (Request $request): Limit {
            $username = config('services.kinescope.callback_username');
            $password = config('services.kinescope.callback_password');
            $requestUsername = $request->getUser();
            $requestPassword = $request->getPassword();

            if (
                is_string($username)
                && $username !== ''
                && is_string($password)
                && $password !== ''
                && is_string($requestUsername)
                && is_string($requestPassword)
                && hash_equals($username, $requestUsername)
                && hash_equals($password, $requestPassword)
            ) {
                return Limit::none();
            }

            return Limit::perMinute(60)->by('kinescope-callback:'.$request->ip());
        });

        RateLimiter::for('assessment-review-update', function (Request $request): Limit {
            $actorId = $request->user()?->getAuthIdentifier();

            return Limit::perMinute(120)->by($actorId === null
                ? 'assessment-review-update:ip:'.$request->ip()
                : 'assessment-review-update:actor:'.$actorId);
        });

        RateLimiter::for('assessment-review-finalize', function (Request $request): Limit {
            $actorId = $request->user()?->getAuthIdentifier();

            return Limit::perMinute(30)->by($actorId === null
                ? 'assessment-review-finalize:ip:'.$request->ip()
                : 'assessment-review-finalize:actor:'.$actorId);
        });

        RateLimiter::for('payment-create-order', function (Request $request): Limit {
            $actorId = $request->user()?->getAuthIdentifier();

            return Limit::perMinute(10)->by($actorId === null
                ? 'payment-create-order:ip:'.$request->ip()
                : 'payment-create-order:actor:'.$actorId);
        });

        RateLimiter::for('payment-create-attempt', function (Request $request): Limit {
            $actorId = $request->user()?->getAuthIdentifier();

            return Limit::perMinute(20)->by($actorId === null
                ? 'payment-create-attempt:ip:'.$request->ip()
                : 'payment-create-attempt:actor:'.$actorId);
        });

        RateLimiter::for('payment-operator-mutation', function (Request $request): Limit {
            $actorId = $request->user()?->getAuthIdentifier();

            return Limit::perMinute(30)->by($actorId === null
                ? 'payment-operator-mutation:ip:'.$request->ip()
                : 'payment-operator-mutation:actor:'.$actorId);
        });
    }
}
