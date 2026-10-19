<?php

declare(strict_types=1);

return [
    'name' => 'Rifa International',
    'env' => env('APP_ENV', 'production'),
    'debug' => (bool) env('APP_DEBUG', false),
    'url' => rtrim((string) env('APP_URL', 'http://localhost:5173'), '/'),
    'timezone' => env('APP_TIMEZONE', 'Asia/Dhaka'),
    'api_prefix' => '/api/v1',
    'log_level' => env('LOG_LEVEL', 'info'),
];
