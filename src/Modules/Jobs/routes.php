<?php

declare(strict_types=1);

use App\Core\Router;
use App\Modules\Jobs\Controllers\AdminJobController;
use App\Modules\Jobs\Controllers\JobController;

return static function (Router $router): void {
    $router->get('/jobs', [JobController::class, 'index']);

    $router->group('/admin/jobs', ['auth', 'csrf'], function (Router $router): void {
        $router->get('', [AdminJobController::class, 'index']);
        $router->post('', [AdminJobController::class, 'store']);
        $router->get('/{id:\d+}', [AdminJobController::class, 'show']);
        $router->patch('/{id:\d+}', [AdminJobController::class, 'update']);
        $router->delete('/{id:\d+}', [AdminJobController::class, 'destroy'], ['role:super_admin']);
        $router->post('/{id:\d+}/image', [AdminJobController::class, 'uploadImage']);
        $router->delete('/{id:\d+}/image', [AdminJobController::class, 'removeImage']);
    });
};
