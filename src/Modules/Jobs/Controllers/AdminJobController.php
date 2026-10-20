<?php

declare(strict_types=1);

namespace App\Modules\Jobs\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Modules\Jobs\Services\JobService;

/** /api/v1/admin/jobs — job listings (P5-02). Deleting is super admin only; staff can unpublish. */
final class AdminJobController
{
    public function __construct(private readonly JobService $jobs)
    {
    }

    public function index(Request $request): Response
    {
        return Response::data($this->jobs->adminList($request->query()));
    }

    public function show(Request $request): Response
    {
        return Response::data($this->jobs->show((int) $request->param('id')));
    }

    public function store(Request $request): Response
    {
        return Response::data($this->jobs->create($request->attribute('admin'), $request->all(), $request->ip()), 201);
    }

    public function update(Request $request): Response
    {
        return Response::data($this->jobs->update((int) $request->param('id'), $request->attribute('admin'), $request->all(), $request->ip()));
    }

    public function destroy(Request $request): Response
    {
        $this->jobs->delete((int) $request->param('id'), $request->attribute('admin'), $request->ip());

        return Response::noContent();
    }

    /** POST /admin/jobs/{id}/image — multipart, field "image". */
    public function uploadImage(Request $request): Response
    {
        return Response::data($this->jobs->uploadImage(
            (int) $request->param('id'),
            $request->attribute('admin'),
            $request->file('image'),
            $request->ip(),
        ));
    }

    public function removeImage(Request $request): Response
    {
        return Response::data($this->jobs->removeImage((int) $request->param('id'), $request->attribute('admin'), $request->ip()));
    }
}
