<?php
return [
    'pgsql' => [
        'driver' => 'pgsql',
        'host' => env('DB_HOST', '127.0.0.1'),
        'port' => env('DB_PORT', '5432'),
        'database' => env('DB_DATABASE'),
        'username' => env('DB_USERNAME'),
        'password' => env('DB_PASSWORD'),
        'charset' => 'utf8',
        'prefix' => '',
        'schema' => env('DB_SCHEMA', 'public'),
        'sslmode' => env('DB_SSLMODE', 'prefer'),
    ],
];
