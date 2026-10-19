<?php

declare(strict_types=1);

use App\Core\Router;
use App\Modules\Dashboard\Controllers\DashboardController;

return static function (Router $router): void {
    $router->get('/admin/dashboard', [DashboardController::class, 'show'], ['auth', 'csrf']);
};
