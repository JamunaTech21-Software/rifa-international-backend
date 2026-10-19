<?php

declare(strict_types=1);

return [
    'session' => [
        'name' => env('SESSION_NAME', 'rifa_session'),
        'secure' => (bool) env('SESSION_SECURE', true),
        'samesite' => 'Strict',
        'idle_minutes' => (int) env('SESSION_IDLE_MINUTES', 60),
    ],
    // Vercel forwards /api/* to this backend; see Core\Middleware\TrustedProxy and DEPLOY.md.
    'proxy' => [
        'secret' => (string) env('TRUSTED_PROXY_SECRET', ''),
        'secret_header' => 'X-Rifa-Proxy-Secret',
        'client_ip_header' => 'X-Rifa-Client-Ip',
    ],
    'login' => [
        'max_attempts' => 5,
        'lockout_minutes' => 15,
    ],
    'password_min_length' => 10,
    'password_reset_minutes' => 30,
    'upload_max_kb' => 5120,
];
