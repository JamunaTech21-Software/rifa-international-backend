<?php

declare(strict_types=1);

namespace App\Modules\Applications\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Modules\Applications\Services\ApplicantMessageService;

/** /api/v1/admin/applications/{id}/messages — emails to the applicant (P5-01). */
final class AdminApplicationMessageController
{
    public function __construct(private readonly ApplicantMessageService $messages)
    {
    }

    public function templates(Request $request): Response
    {
        return Response::data($this->messages->templates((int) $request->param('id')));
    }

    public function index(Request $request): Response
    {
        return Response::data($this->messages->log((int) $request->param('id')));
    }

    public function store(Request $request): Response
    {
        return Response::data($this->messages->send(
            (int) $request->param('id'),
            $request->attribute('admin'),
            $request->all(),
            $request->ip(),
        ), 201);
    }
}
