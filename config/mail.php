<?php

declare(strict_types=1);

return [
    'driver' => env('MAIL_DRIVER', 'smtp'), // smtp | log
    'host' => env('MAIL_HOST', ''),
    'port' => (int) env('MAIL_PORT', 465),
    'username' => env('MAIL_USERNAME', ''),
    'password' => (string) env('MAIL_PASSWORD', ''),
    'encryption' => env('MAIL_ENCRYPTION', 'ssl'),
    'from_address' => env('MAIL_FROM_ADDRESS', 'info@rifaint.com'),
    'from_name' => env('MAIL_FROM_NAME', 'Rifa International'),
    'office_to' => env('MAIL_OFFICE_TO', 'info@rifaint.com'),
];
