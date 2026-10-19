<?php

declare(strict_types=1);

// Local dev front controller: composer serve  (php -S localhost:8000 -t public public/index.php)
// On cPanel, public_html/api/index.php requires ~/rifa_backend/bootstrap.php instead.

/** @var App\Core\App $app */
$app = require dirname(__DIR__) . '/bootstrap.php';
$app->run();
