<?php

namespace App\Services\TrustedBrowser;

use Illuminate\Cookie\Middleware\EncryptCookies;

final class TrustedBrowserEncryptCookies extends EncryptCookies
{
    public function isDisabled($name): bool
    {
        $cookieName = (string) $name;
        $trustedBrowserPrefixes = [
            (string) config('trusted_browser.cookie.production_prefix', '__Host-cms-tb-v1-'),
            (string) config('trusted_browser.cookie.local_prefix', 'cms-tb-v1-'),
        ];

        foreach ($trustedBrowserPrefixes as $prefix) {
            if ($prefix !== '' && str_starts_with($cookieName, $prefix)) {
                return true;
            }
        }

        return parent::isDisabled($name);
    }
}
