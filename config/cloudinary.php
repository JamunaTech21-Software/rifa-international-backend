<?php

declare(strict_types=1);

return [
    'cloud_name' => env('CLOUDINARY_CLOUD_NAME', ''),
    'api_key' => env('CLOUDINARY_API_KEY', ''),
    'api_secret' => env('CLOUDINARY_API_SECRET', ''),
    'applications_folder' => env('CLOUDINARY_APPLICATIONS_FOLDER', 'rifa/applications'),
    'signed_url_ttl' => 300, // seconds an admin document link stays valid
];
