<?php

declare(strict_types=1);

use App\Core\Router;
use App\Modules\Health\Controllers\HealthController;

return static function (Router $router): void {
    $router->get('/health', [HealthController::class, 'show']);
};
