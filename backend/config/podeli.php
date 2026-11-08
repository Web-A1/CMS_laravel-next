<?php

return [
    'enabled' => (bool) env('PAYMENTS_PODELI_ENABLED', false),
    'sandbox' => (bool) env('PAYMENTS_PODELI_SANDBOX', true),
    'capture_mode' => env('PAYMENTS_PODELI_CAPTURE_MODE', ''),
    'login' => env('PAYMENTS_PODELI_LOGIN', ''),
    'password' => env('PAYMENTS_PODELI_PASSWORD', ''),
    'certificate_path' => env('PAYMENTS_PODELI_CERTIFICATE_PATH', ''),
    'private_key_path' => env('PAYMENTS_PODELI_PRIVATE_KEY_PATH', ''),
    // Production addresses must come from the partner's integration manager.
    'production_base_url' => env('PAYMENTS_PODELI_PRODUCTION_BASE_URL', ''),
    'production_redirect_hosts' => array_values(array_filter(array_map('trim', explode(',', env('PAYMENTS_PODELI_PRODUCTION_REDIRECT_HOSTS', ''))))),
    'notification_url' => env('PAYMENTS_PODELI_NOTIFICATION_URL', ''),
    'return_url_hosts' => array_values(array_filter(array_map('trim', explode(',', env('PAYMENTS_PODELI_RETURN_HOSTS', ''))))),
    'production_webhook_ips' => array_values(array_filter(array_map('trim', explode(',', env('PAYMENTS_PODELI_PRODUCTION_WEBHOOK_IPS', ''))))),
];
