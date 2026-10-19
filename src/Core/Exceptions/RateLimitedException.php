<?php

declare(strict_types=1);

namespace App\Core\Exceptions;

final class RateLimitedException extends HttpException
{
    public function __construct(int $retryAfterSeconds, string $message = 'Too many attempts. Please try again later.')
    {
        parent::__construct(429, 'rate_limited', $message, [], [
            'Retry-After' => (string) max(1, $retryAfterSeconds),
        ]);
    }
}
