<?php

declare(strict_types=1);

namespace App\Modules\Lookups\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Modules\Lookups\Services\LookupsService;

final class LookupsController
{
    public function __construct(private readonly LookupsService $lookups)
    {
    }

    /** GET /api/v1/lookups — dropdown options for the Apply and Contact forms. */
    public function index(Request $request): Response
    {
        return Response::data($this->lookups->publicLookups())
            ->withHeader('Cache-Control', 'public, max-age=3600');
    }
}
