<?php

declare(strict_types=1);

namespace App\Modules\Messages\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Modules\Messages\Services\MessageService;

final class MessageController
{
    public function __construct(private readonly MessageService $messages)
    {
    }

    /** POST /api/v1/messages — Contact Us "Send Us a Message" form. */
    public function store(Request $request): Response
    {
        $result = $this->messages->receive($request->all(), $request->ip(), $request->userAgent());

        return Response::data($result, 201);
    }
}
