<?php

declare(strict_types=1);

use App\Core\Router;
use App\Modules\Applications\Controllers\AdminApplicationController;
use App\Modules\Applications\Controllers\ApplicationController;

return static function (Router $router): void {
    $router->post('/applications', [ApplicationController::class, 'store'], ['throttle:applications,5,3600']);

    $router->group('/admin/applications', ['auth', 'csrf'], function (Router $router): void {
        $router->get('', [AdminApplicationController::class, 'index']);
        $router->get('/export', [AdminApplicationController::class, 'export']);
        $router->get('/{id:\d+}', [AdminApplicationController::class, 'show']);
        $router->patch('/{id:\d+}/status', [AdminApplicationController::class, 'updateStatus']);
        $router->post('/{id:\d+}/notes', [AdminApplicationController::class, 'storeNote']);
        $router->get('/{id:\d+}/documents/{docId:\d+}/url', [AdminApplicationController::class, 'documentUrl']);
        $router->delete('/{id:\d+}', [AdminApplicationController::class, 'destroy'], ['role:super_admin']);
    });
};
