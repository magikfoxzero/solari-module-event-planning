<?php

return [
    'default' => env('CACHE_STORE', 'redis'),
    'stores' => [
        'redis' => [
            'driver' => 'redis',
            'connection' => env('CACHE_REDIS_CONNECTION', 'cache'),
        ],
        'array' => [
            'driver' => 'array',
            'serialize' => false,
        ],
    ],
    'prefix' => env('CACHE_PREFIX', 'tags_cache_'),
];
