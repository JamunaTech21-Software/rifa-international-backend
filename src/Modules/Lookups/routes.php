<?php

declare(strict_types=1);

use App\Core\Router;
use App\Modules\Lookups\Controllers\LookupsController;

return static function (Router $router): void {
    $router->get('/lookups', [LookupsController::class, 'index']);
};
