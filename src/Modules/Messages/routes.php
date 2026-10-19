<?php

declare(strict_types=1);

use App\Core\Router;
use App\Modules\Messages\Controllers\MessageController;

return static function (Router $router): void {
    $router->post('/messages', [MessageController::class, 'store'], ['throttle:messages,5,3600']);
};
