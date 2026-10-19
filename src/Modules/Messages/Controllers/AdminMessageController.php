<?php

declare(strict_types=1);

namespace App\Modules\Messages\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Modules\Messages\Services\AdminMessageService;

/** /api/v1/admin/messages/* (story BE-13). */
final class AdminMessageController
{
    public function __construct(private readonly AdminMessageService $messages)
    {
    }

    public function index(Request $request): Response
    {
        $result = $this->messages->list($request->query());

        return Response::data($result['data'], 200, $result['meta']);
    }

    public function show(Request $request): Response
    {
        return Response::data($this->messages->open((int) $request->param('id')));
    }

    public function update(Request $request): Response
    {
        return Response::data($this->messages->changeStatus(
            (int) $request->param('id'),
            $request->attribute('admin'),
            $request->all(),
            $request->ip(),
        ));
    }

    public function reply(Request $request): Response
    {
        return Response::data($this->messages->reply(
            (int) $request->param('id'),
            $request->attribute('admin'),
            $request->all(),
            $request->ip(),
        ), 201);
    }
}
