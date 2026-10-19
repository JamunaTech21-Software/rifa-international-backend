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

    // Contact details shown in every email footer (same as frontend src/data/site.js).
    'site' => [
        'name' => 'Rifa International',
        'licence' => 'RL-693',
        'phone' => '+880 1990-359053',
        'whatsapp' => '01968522872',
        'email' => 'info@rifaint.com',
        'address' => 'House 62 (4th Floor), Road 15, Block-C, Banani, Dhaka-1213, Bangladesh',
        'hours' => 'Sunday – Thursday, 10:00 AM – 7:00 PM',
        'url' => rtrim((string) env('APP_URL', 'http://localhost:5173'), '/'),
    ],
];
