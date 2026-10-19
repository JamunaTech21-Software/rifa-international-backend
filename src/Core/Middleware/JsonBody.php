<?php

declare(strict_types=1);

namespace App\Core\Middleware;

use App\Core\Exceptions\BadRequestException;
use App\Core\Exceptions\HttpException;
use App\Core\Request;
use App\Core\Response;

/**
 * Alias: json (applied to every API route).
 * - 413 when the body was larger than PHP's post_max_size (PHP would otherwise drop every field silently)
 * - 400 when a JSON body cannot be parsed
 */
final class JsonBody implements Middleware
{
    public function handle(Request $request, callable $next, string ...$params): Response
    {
        if ($request->isBodyTooLarge()) {
            throw new HttpException(413, 'payload_too_large', 'The upload is too large. Each file must be 5 MB or smaller.');
        }
        if ($request->hasInvalidJson()) {
            throw new BadRequestException('The request body is not valid JSON.', 'invalid_json');
        }

        return $next($request);
    }
}
