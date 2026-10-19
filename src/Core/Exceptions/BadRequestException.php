<?php

declare(strict_types=1);

namespace App\Core\Exceptions;

final class BadRequestException extends HttpException
{
    public function __construct(string $message = 'The request is invalid.', string $code = 'bad_request')
    {
        parent::__construct(400, $code, $message);
    }
}
