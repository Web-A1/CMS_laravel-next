<?php

return [
    'enabled' => (bool) env('PAYMENT_NOTIFICATIONS_ENABLED', false),
    'mailer' => env('PAYMENT_NOTIFICATIONS_MAILER', 'smtp'),
    'version' => '1',
];
