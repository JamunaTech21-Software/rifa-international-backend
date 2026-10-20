<?php

declare(strict_types=1);

namespace App\Modules\Jobs\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Modules\Jobs\Services\JobService;

final class JobController
{
    public function __construct(private readonly JobService $jobs)
    {
    }

    /** GET /api/v1/jobs — published listings for the Home and Malaysia Jobs pages. */
    public function index(Request $request): Response
    {
        return Response::data($this->jobs->publicList())
            ->withHeader('Cache-Control', 'public, max-age=300');
    }
}
