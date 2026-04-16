<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | SECURITY NOTE: In production, you should specify exact origins rather
    | than using wildcards to prevent unauthorized cross-origin requests.
    |
    */

    // Derive from ALLOWED_DOMAINS: root domain + any subdomain wildcard
    // Can override entirely with CORS_ALLOWED_ORIGINS
    'allowed_origins' => env('CORS_ALLOWED_ORIGINS')
        ? explode(',', env('CORS_ALLOWED_ORIGINS'))
        : (env('ALLOWED_DOMAINS')
            ? array_merge(...array_map(fn ($d) => [
                "https://{$d}",
                "https://*.{$d}",
            ], array_filter(explode(',', env('ALLOWED_DOMAINS', '')))))
            : ['http://localhost:5173', 'http://localhost:3000']),

    'allowed_methods' => [
        'GET',
        'POST',
        'PUT',
        'PATCH',
        'DELETE',
        'OPTIONS',
    ],

    'allowed_headers' => [
        'Content-Type',
        'Authorization',
        'X-Requested-With',
        'X-API-Key',
        'X-Secret-Key',
        'X-Partition',
        'X-Partition-ID',
        'Accept',
        'Accept-Language',
        'X-XSRF-TOKEN',
        'X-WS-Token',
    ],

    'exposed_headers' => [
        'X-RateLimit-Limit',
        'X-RateLimit-Remaining',
        'X-RateLimit-Reset',
        'Retry-After',
    ],

    'supports_credentials' => env('CORS_SUPPORTS_CREDENTIALS', false),

    'max_age' => env('CORS_MAX_AGE', 86400),

];
