<?php

return [
    'default' => env('DB_CONNECTION', 'mysql'),
    'connections' => [
        'mysql' => [
            'driver' => 'mysql',
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'solari_db'),
            'username' => env('DB_USERNAME', 'solari_user'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
        ],
        'sqlite' => [
            'driver' => 'sqlite',
            'foreign_key_constraints' => true,
            'database' => env('DB_DATABASE', database_path('database.sqlite')),
            'prefix' => '',
        ],
    ],
    'migrations' => [
        'table' => 'migrations',
    ],
];
