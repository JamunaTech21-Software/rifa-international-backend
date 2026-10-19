<?php

declare(strict_types=1);

namespace App\Core\Exceptions;

final class UnauthenticatedException extends HttpException
{
    public function __construct(string $message = 'Please log in to continue.')
    {
        parent::__construct(401, 'unauthenticated', $message);
    }
}
