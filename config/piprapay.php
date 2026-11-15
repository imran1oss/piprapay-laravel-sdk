<?php

return [
    /*
    |--------------------------------------------------------------------------
    | PipraPay connection
    |--------------------------------------------------------------------------
    */
    'base_url' => env('PIPRAPAY_BASE_URL'),
    'api_key' => env('PIPRAPAY_API_KEY'),
    'currency' => env('PIPRAPAY_CURRENCY', 'BDT'),

    /*
    |--------------------------------------------------------------------------
    | HTTP behavior
    |--------------------------------------------------------------------------
    */
    'timeout' => (int) env('PIPRAPAY_TIMEOUT', 15),
    'connect_timeout' => (int) env('PIPRAPAY_CONNECT_TIMEOUT', 5),
    // Verification only. Checkout creation is always sent once.
    'attempts' => (int) env('PIPRAPAY_ATTEMPTS', 3),
    'retry_sleep_ms' => (int) env('PIPRAPAY_RETRY_SLEEP_MS', 200),

    /*
    |--------------------------------------------------------------------------
    | Current PipraPay API paths
    |--------------------------------------------------------------------------
    */
    'endpoints' => [
        'checkout' => '/api/checkout/redirect',
        'verify' => '/api/verify-payment',
    ],
];
