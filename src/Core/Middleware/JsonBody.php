<?php

declare(strict_types=1);

namespace App\Core\Middleware;

use App\Core\Exceptions\BadRequestException;
use App\Core\Request;
use App\Core\Response;

/** Rejects requests whose JSON body cannot be parsed (alias: json, applied to every API route). */
final class JsonBody implements Middleware
{
    public function handle(Request $request, callable $next, string ...$params): Response
    {
        if ($request->hasInvalidJson()) {
            throw new BadRequestException('The request body is not valid JSON.', 'invalid_json');
        }

        return $next($request);
    }
}
