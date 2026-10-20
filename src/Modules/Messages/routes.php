<?php

declare(strict_types=1);

use App\Core\Router;
use App\Modules\Messages\Controllers\AdminMessageController;
use App\Modules\Messages\Controllers\MessageController;

return static function (Router $router): void {
    $router->post('/messages', [MessageController::class, 'store'], ['captcha', 'throttle:messages,5,3600']);

    $router->group('/admin/messages', ['auth', 'csrf'], function (Router $router): void {
        $router->get('', [AdminMessageController::class, 'index']);
        $router->get('/{id:\d+}', [AdminMessageController::class, 'show']);
        $router->patch('/{id:\d+}', [AdminMessageController::class, 'update']);
        $router->post('/{id:\d+}/replies', [AdminMessageController::class, 'reply']);
    });
};
