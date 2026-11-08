<?php

return [
    'enabled' => (bool) env('FISCAL_ENABLED', false),
    'exclusive_issuer_confirmed' => (bool) env('FISCAL_EXCLUSIVE_ISSUER_CONFIRMED', false),
    'version' => env('FISCAL_CONFIG_VERSION'),
    'public_id' => env('FISCAL_PUBLIC_ID'),
    'api_secret' => env('FISCAL_API_SECRET'),
    'inn' => env('FISCAL_INN'),
    'taxation_system' => env('FISCAL_TAXATION_SYSTEM'),
    // Explicit "none" means no VAT; an absent value is not a tax decision.
    'vat' => env('FISCAL_VAT'),
    'method' => env('FISCAL_METHOD'),
    'object' => env('FISCAL_OBJECT'),
];
