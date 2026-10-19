<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Exceptions\HttpException;
use Throwable;

/**
 * Turns any exception into the standard JSON error body. Unexpected errors are
 * logged with the request id and shown as a generic 500 (details only in debug).
 */
final class ErrorHandler
{
    public function __construct(
        private readonly Logger $logger,
        private readonly bool $debug = false,
    ) {
    }

    public function render(Throwable $e, Request $request): Response
    {
        if ($e instanceof HttpException) {
            $error = ['code' => $e->errorCode(), 'message' => $e->getMessage()];
            if ($e->fields() !== []) {
                $error['fields'] = $e->fields();
            }

            return Response::json(['error' => $error], $e->status(), $e->headers());
        }

        $requestId = (string) $request->attribute('request_id', '');
        $this->logger->error($e->getMessage(), [
            'request_id' => $requestId,
            'exception' => $e::class,
            'file' => $e->getFile() . ':' . $e->getLine(),
            'method' => $request->method(),
            'path' => $request->path(),
            'trace' => array_slice(explode("\n", $e->getTraceAsString()), 0, 15),
        ]);

        $error = [
            'code' => 'server_error',
            'message' => 'Something went wrong. Please try again later.',
            'request_id' => $requestId,
        ];
        if ($this->debug) {
            $error['debug'] = [
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'file' => $e->getFile() . ':' . $e->getLine(),
            ];
        }

        return Response::json(['error' => $error], 500);
    }
}
