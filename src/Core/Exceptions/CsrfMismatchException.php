<?php

declare(strict_types=1);

namespace App\Core\Exceptions;

final class CsrfMismatchException extends HttpException
{
    public function __construct(string $message = 'Your session token is invalid. Please refresh the page and try again.')
    {
        parent::__construct(419, 'csrf_mismatch', $message);
    }
}
