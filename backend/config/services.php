<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'redsms' => [
        'base_url' => env('REDSMS_BASE_URL', 'https://cp.redsms.ru'),
        'login' => env('REDSMS_LOGIN', ''),
        'api_key' => env('REDSMS_API_KEY', ''),
        'timeout_seconds' => (int) env('REDSMS_TIMEOUT_SECONDS', 15),
        'mode' => env('REDSMS_MODE', 'mock'),
        'webhook_allowed_ips' => array_values(array_filter(array_map(
            static fn (string $ip): string => trim($ip),
            explode(',', env('REDSMS_WEBHOOK_ALLOWED_IPS', '37.9.15.244,5.188.131.157,5.188.131.158,82.202.237.68'))
        ))),
    ],

    'smtpbz' => [
        'enabled' => env('SMTPBZ_ENABLED', false),
        'base_url' => env('SMTPBZ_BASE_URL', 'https://api.smtp.bz'),
        'api_key' => env('SMTPBZ_API_KEY', ''),
        'from_email' => env('SMTPBZ_FROM_EMAIL', ''),
        'from_name' => env('SMTPBZ_FROM_NAME', env('APP_NAME', 'Laravel')),
        'timeout_seconds' => (int) env('SMTPBZ_TIMEOUT_SECONDS', 15),
    ],

    'kinescope' => [
        'enabled' => env('KINESCOPE_ENABLED', false),
        'api_base_url' => env('KINESCOPE_API_BASE_URL', 'https://api.kinescope.io'),
        'api_token' => env('KINESCOPE_API_TOKEN', ''),
        'project_id' => env('KINESCOPE_PROJECT_ID', ''),
        'embed_base_url' => env('KINESCOPE_EMBED_BASE_URL', 'https://kinescope.io/embed'),
        'playback_secret' => env('KINESCOPE_PLAYBACK_SECRET', ''),
        'playback_issuer' => env('KINESCOPE_PLAYBACK_ISSUER', 'cms-laravel-next'),
        'playback_key_id' => env('KINESCOPE_PLAYBACK_KEY_ID', 'new-2026-01'),
        'playback_audience' => env('KINESCOPE_PLAYBACK_AUDIENCE', 'kinescope-player'),
        'playback_ttl_seconds' => (int) env('KINESCOPE_PLAYBACK_TTL_SECONDS', 900),
        'legacy_playback_issuer' => env('KINESCOPE_LEGACY_PLAYBACK_ISSUER', 'christmedschool-legacy'),
        'legacy_playback_key_id' => env('KINESCOPE_LEGACY_PLAYBACK_KEY_ID', 'legacy-2026-01'),
        'legacy_playback_audience' => env('KINESCOPE_LEGACY_PLAYBACK_AUDIENCE', 'kinescope-player'),
        'legacy_playback_public_key_base64' => env('KINESCOPE_LEGACY_PLAYBACK_PUBLIC_KEY_BASE64', ''),
        'callback_username' => env('KINESCOPE_CALLBACK_USERNAME', ''),
        'callback_password' => env('KINESCOPE_CALLBACK_PASSWORD', ''),
    ],

    'payments' => [
        'providers' => [
            'podeli' => [
                'webhook_secret' => env('PAYMENTS_PODELI_WEBHOOK_SECRET', ''),
                'webhook_allowed_ips' => array_values(array_filter(array_map(
                    static fn (string $ip): string => trim($ip),
                    explode(',', env('PAYMENTS_PODELI_WEBHOOK_ALLOWED_IPS', ''))
                ))),
            ],
            'yandex_split' => [
                'webhook_secret' => env('PAYMENTS_YANDEX_SPLIT_WEBHOOK_SECRET', ''),
                'webhook_public_key' => env('PAYMENTS_YANDEX_SPLIT_WEBHOOK_PUBLIC_KEY', ''),
                'webhook_allowed_ips' => array_values(array_filter(array_map(
                    static fn (string $ip): string => trim($ip),
                    explode(',', env('PAYMENTS_YANDEX_SPLIT_WEBHOOK_ALLOWED_IPS', ''))
                ))),
            ],
            'robokassa' => [
                'webhook_secret' => env('PAYMENTS_ROBOKASSA_PASSWORD2', ''),
                'webhook_allowed_ips' => array_values(array_filter(array_map(
                    static fn (string $ip): string => trim($ip),
                    explode(',', env('PAYMENTS_ROBOKASSA_WEBHOOK_ALLOWED_IPS', ''))
                ))),
            ],
            'test_provider' => [
                'webhook_secret' => env('PAYMENTS_TEST_PROVIDER_WEBHOOK_SECRET', ''),
                'webhook_allowed_ips' => array_values(array_filter(array_map(
                    static fn (string $ip): string => trim($ip),
                    explode(',', env('PAYMENTS_TEST_PROVIDER_WEBHOOK_ALLOWED_IPS', ''))
                ))),
            ],
            'cloudpayments' => [
                'webhook_secret' => env('PAYMENTS_CLOUDPAYMENTS_WEBHOOK_SECRET', ''),
                'public_id' => env('PAYMENTS_CLOUDPAYMENTS_PUBLIC_ID', ''),
                'test_mode' => (bool) env('PAYMENTS_CLOUDPAYMENTS_TEST_MODE', false),
                'webhook_allowed_ips' => array_values(array_filter(array_map(
                    static fn (string $ip): string => trim($ip),
                    explode(',', env('PAYMENTS_CLOUDPAYMENTS_WEBHOOK_ALLOWED_IPS', ''))
                ))),
            ],
        ],
    ],

];
