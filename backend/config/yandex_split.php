<?php

return [
    'enabled' => (bool) env('PAYMENTS_YANDEX_SPLIT_ENABLED', false),
    'sandbox' => (bool) env('PAYMENTS_YANDEX_SPLIT_SANDBOX', true),
    'merchant_id' => env('PAYMENTS_YANDEX_SPLIT_MERCHANT_ID', ''),
    'api_key' => env('PAYMENTS_YANDEX_SPLIT_API_KEY', ''),
    'return_url_hosts' => array_values(array_filter(array_map('trim', explode(',', env('PAYMENTS_YANDEX_SPLIT_RETURN_HOSTS', ''))))),
];
