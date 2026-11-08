<?php

return [
    // Enable only after checkout, settlement and refund integration are verified.
    'checkout_enabled' => (bool) env('BONUS_CHECKOUT_ENABLED', false),
    // Approved by the owner on 2026-09-15: one coin equals one ruble.
    'coin_minor_units' => 100,
];
