<?php

declare(strict_types=1);

namespace App\Core\Exceptions;

use RuntimeException;

/**
 * Base for every error that maps to an HTTP response:
 * { "error": { "code": "...", "message": "...", "fields": {...} } }
 */
class HttpException extends RuntimeException
{
    /**
     * @param array<string, string> $fields  per-field messages (validation)
     * @param array<string, string> $headers extra response headers
     */
    public function __construct(
        private readonly int $status,
        private readonly string $errorCode,
        string $message,
        private readonly array $fields = [],
        private readonly array $headers = [],
    ) {
        parent::__construct($message);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    /** @return array<string, string> */
    public function fields(): array
    {
        return $this->fields;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }
}
