<?php

declare(strict_types=1);

return [
    'name' => 'Rifa International',
    'env' => env('APP_ENV', 'production'),
    'debug' => (bool) env('APP_DEBUG', false),
    'url' => rtrim((string) env('APP_URL', 'http://localhost:5173'), '/'),
    // Base URL of the admin app (frontend/admin), used for links in emails: {admin_url}/applications/12
    'admin_url' => rtrim((string) env('ADMIN_URL', rtrim((string) env('APP_URL', 'http://localhost:5173'), '/') . '/admin'), '/'),
    'timezone' => env('APP_TIMEZONE', 'Asia/Dhaka'),
    'api_prefix' => '/api/v1',
    'log_level' => env('LOG_LEVEL', 'info'),
];
