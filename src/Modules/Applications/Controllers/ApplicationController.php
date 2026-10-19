<?php

declare(strict_types=1);

namespace App\Modules\Applications\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Modules\Applications\Services\ApplicationService;

final class ApplicationController
{
    public function __construct(private readonly ApplicationService $applications)
    {
    }

    /** POST /api/v1/applications (multipart/form-data) — public Apply Now form. */
    public function store(Request $request): Response
    {
        $result = $this->applications->submit(
            $request->all(),
            $request->files(),
            $request->ip(),
            $request->userAgent(),
        );

        return Response::data($result, 201);
    }
}
