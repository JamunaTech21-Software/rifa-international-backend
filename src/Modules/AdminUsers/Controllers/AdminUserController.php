<?php

declare(strict_types=1);

namespace App\Modules\AdminUsers\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Modules\AdminUsers\Services\AdminUserService;

/** /api/v1/admin/users — super admin only (story BE-14). */
final class AdminUserController
{
    public function __construct(private readonly AdminUserService $users)
    {
    }

    public function index(Request $request): Response
    {
        return Response::data($this->users->list());
    }

    public function store(Request $request): Response
    {
        return Response::data($this->users->create($request->attribute('admin'), $request->all(), $request->ip()), 201);
    }

    public function update(Request $request): Response
    {
        return Response::data($this->users->update(
            (int) $request->param('id'),
            $request->attribute('admin'),
            $request->all(),
            $request->ip(),
        ));
    }
}
