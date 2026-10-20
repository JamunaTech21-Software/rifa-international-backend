<?php

declare(strict_types=1);

namespace App\Modules\Applications\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Modules\Applications\Services\ApplicationService;
use App\Modules\Applications\Services\StatusLookupService;

final class ApplicationController
{
    public function __construct(
        private readonly ApplicationService $applications,
        private readonly StatusLookupService $statusLookup,
    ) {
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

    /** POST /api/v1/applications/status — applicant checks their status (POST keeps the mobile out of URLs/logs). */
    public function status(Request $request): Response
    {
        return Response::data($this->statusLookup->lookup($request->all(), $request->ip()));
    }
}
