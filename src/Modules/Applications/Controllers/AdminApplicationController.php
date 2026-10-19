<?php

declare(strict_types=1);

namespace App\Modules\Applications\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Modules\Applications\Services\AdminApplicationService;

/** /api/v1/admin/applications/* (story BE-12). */
final class AdminApplicationController
{
    public function __construct(private readonly AdminApplicationService $applications)
    {
    }

    public function index(Request $request): Response
    {
        $result = $this->applications->list($request->query());

        return Response::data($result['data'], 200, $result['meta']);
    }

    public function show(Request $request): Response
    {
        return Response::data($this->applications->detail($this->id($request), $request->attribute('admin')));
    }

    public function updateStatus(Request $request): Response
    {
        return Response::data($this->applications->changeStatus(
            $this->id($request),
            $request->attribute('admin'),
            $request->all(),
            $request->ip(),
        ));
    }

    public function storeNote(Request $request): Response
    {
        return Response::data($this->applications->addNote(
            $this->id($request),
            $request->attribute('admin'),
            $request->all(),
            $request->ip(),
        ), 201);
    }

    public function documentUrl(Request $request): Response
    {
        return Response::data($this->applications->documentUrl(
            $this->id($request),
            (int) $request->param('docId'),
            $request->attribute('admin'),
            $request->ip(),
        ));
    }

    public function destroy(Request $request): Response
    {
        $this->applications->delete($this->id($request), $request->attribute('admin'), $request->ip());

        return Response::noContent();
    }

    private function id(Request $request): int
    {
        return (int) $request->param('id');
    }
}
