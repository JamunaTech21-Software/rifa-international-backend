<?php

declare(strict_types=1);

use App\Core\Router;
use App\Modules\News\Controllers\AdminNewsController;
use App\Modules\News\Controllers\NewsController;

return static function (Router $router): void {
    $router->get('/news', [NewsController::class, 'index']);
    $router->get('/news/{slug:[a-z0-9-]+}', [NewsController::class, 'show']);

    $router->group('/admin/news', ['auth', 'csrf'], function (Router $router): void {
        $router->get('', [AdminNewsController::class, 'index']);
        $router->post('', [AdminNewsController::class, 'store']);
        $router->get('/{id:\d+}', [AdminNewsController::class, 'show']);
        $router->patch('/{id:\d+}', [AdminNewsController::class, 'update']);
        $router->delete('/{id:\d+}', [AdminNewsController::class, 'destroy'], ['role:super_admin']);
        $router->post('/{id:\d+}/image', [AdminNewsController::class, 'uploadImage']);
        $router->delete('/{id:\d+}/image', [AdminNewsController::class, 'removeImage']);
    });
};
