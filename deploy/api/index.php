<?php

declare(strict_types=1);

// Production front controller — copy into the API document root on cPanel (see DEPLOY.md).
// The backend code lives OUTSIDE the web root, in ~/rifa_backend. Works for a document root
// directly in the home folder (~/api.rifaint.com) or one level deeper (~/public_html/api).

foreach ([dirname(__DIR__), dirname(__DIR__, 2)] as $home) {
    if (is_file($home . '/rifa_backend/bootstrap.php')) {
        /** @var App\Core\App $app */
        $app = require $home . '/rifa_backend/bootstrap.php';
        $app->run();

        return;
    }
}

http_response_code(503);
header('Content-Type: application/json');
echo '{"error":{"code":"server_misconfigured","message":"The service is temporarily unavailable."}}';
