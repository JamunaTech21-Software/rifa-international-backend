<?php

declare(strict_types=1);

namespace App\Modules\News\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Modules\News\Services\NewsService;

/** /api/v1/admin/news — News & Updates (P5-03). Deleting is super admin only; staff can unpublish. */
final class AdminNewsController
{
    public function __construct(private readonly NewsService $news)
    {
    }

    public function index(Request $request): Response
    {
        return Response::data($this->news->adminList($request->query()));
    }

    public function show(Request $request): Response
    {
        return Response::data($this->news->show((int) $request->param('id')));
    }

    public function store(Request $request): Response
    {
        return Response::data($this->news->create($request->attribute('admin'), $request->all(), $request->ip()), 201);
    }

    public function update(Request $request): Response
    {
        return Response::data($this->news->update((int) $request->param('id'), $request->attribute('admin'), $request->all(), $request->ip()));
    }

    public function destroy(Request $request): Response
    {
        $this->news->delete((int) $request->param('id'), $request->attribute('admin'), $request->ip());

        return Response::noContent();
    }

    /** POST /admin/news/{id}/image — multipart, field "image". */
    public function uploadImage(Request $request): Response
    {
        return Response::data($this->news->uploadImage(
            (int) $request->param('id'),
            $request->attribute('admin'),
            $request->file('image'),
            $request->ip(),
        ));
    }

    public function removeImage(Request $request): Response
    {
        return Response::data($this->news->removeImage((int) $request->param('id'), $request->attribute('admin'), $request->ip()));
    }
}
