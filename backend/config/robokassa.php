<?php

return [
    'enabled' => (bool) env('ROBOKASSA_ENABLED', false),
    'test_mode' => (bool) env('ROBOKASSA_TEST_MODE', true),
    'merchant_login' => env('ROBOKASSA_MERCHANT_LOGIN'),
    'password1' => env('ROBOKASSA_PASSWORD1'),
    'password2' => env('ROBOKASSA_PASSWORD2'),
    'password3' => env('ROBOKASSA_PASSWORD3'),
    'hash' => env('ROBOKASSA_HASH', 'md5'),
    'refund_enabled' => (bool) env('ROBOKASSA_REFUND_ENABLED', false),
    'allowed_ips' => ['185.59.216.65', '185.59.217.65'],
];
