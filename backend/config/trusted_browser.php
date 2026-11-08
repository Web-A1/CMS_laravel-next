<?php

$configuredOrigins = (string) env(
    'TRUSTED_BROWSER_ALLOWED_ORIGINS',
    env('FRONTEND_URL', env('APP_URL', 'http://localhost')),
);

return [
    'mode' => env('TRUSTED_BROWSER_MODE', 'off'),

    'slot_limit' => 3,

    'cookie' => [
        'production_prefix' => '__Host-cms-tb-v1-',
        'local_prefix' => 'cms-tb-v1-',
        'selector_bytes' => 16,
        'secret_bytes' => 32,
        'same_site' => 'lax',
    ],

    'credential' => [
        'rolling_expiry_days' => 400,
        'refresh_interval_hours' => 24,
    ],

    'otp' => [
        'digits' => 6,
        'ttl_minutes' => 10,
        'max_attempts' => 5,
        'resend_cooldown_seconds' => 60,
    ],

    'rate_limits' => [
        'window_minutes' => 15,
        'send_per_user' => 5,
        'send_per_session' => 5,
        'send_per_ip' => 10,
        'verify_per_user' => 10,
        'verify_per_session' => 10,
        'verify_per_ip' => 20,
    ],

    'support' => [
        'phone_proof_ttl_minutes' => 10,
    ],

    'allowed_origins' => array_values(array_filter(array_map(
        static fn (string $origin): string => trim($origin),
        explode(',', $configuredOrigins),
    ))),
];
