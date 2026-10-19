<?php

declare(strict_types=1);

use App\Core\Router;
use App\Modules\Auth\Controllers\AuthController;

return static function (Router $router): void {
    $router->group('/admin/auth', [], function (Router $router): void {
        // No session yet → no CSRF token; SameSite=Strict cookies protect these.
        $router->post('/login', [AuthController::class, 'login']);
        $router->post('/forgot-password', [AuthController::class, 'forgotPassword'], ['throttle:forgot-password,5,3600']);
        $router->post('/reset-password', [AuthController::class, 'resetPassword'], ['throttle:reset-password,10,3600']);

        $router->get('/me', [AuthController::class, 'me'], ['auth']);
        $router->post('/logout', [AuthController::class, 'logout'], ['auth', 'csrf']);
        $router->post('/change-password', [AuthController::class, 'changePassword'], ['auth', 'csrf']);
    });
};
