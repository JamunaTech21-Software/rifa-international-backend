<?php

declare(strict_types=1);

use App\Core\Router;
use App\Modules\AdminUsers\Controllers\AdminUserController;

return static function (Router $router): void {
    $router->group('/admin/users', ['auth', 'csrf', 'role:super_admin'], function (Router $router): void {
        $router->get('', [AdminUserController::class, 'index']);
        $router->post('', [AdminUserController::class, 'store']);
        $router->patch('/{id:\d+}', [AdminUserController::class, 'update']);
    });
};
