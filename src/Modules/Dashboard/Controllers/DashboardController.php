<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Modules\Dashboard\Services\DashboardService;

final class DashboardController
{
    public function __construct(private readonly DashboardService $dashboard)
    {
    }

    /** GET /api/v1/admin/dashboard */
    public function show(Request $request): Response
    {
        return Response::data($this->dashboard->stats());
    }
}
