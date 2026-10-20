<?php

declare(strict_types=1);

namespace App\Core\Middleware;

use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Core\Response;
use App\Shared\Security\CaptchaVerifier;

/**
 * Alias: captcha — on the public form endpoints (POST /applications, POST /messages).
 *
 * Reads the Turnstile token from the `captcha_token` field (JSON or multipart). The widget's own
 * hidden field `cf-turnstile-response` is accepted too, so a FormData built from the <form> works.
 * Does nothing while CAPTCHA_ENABLED=false.
 */
final class Captcha implements Middleware
{
    public const FIELD = 'captcha_token';
    private const WIDGET_FIELD = 'cf-turnstile-response';

    public function __construct(private readonly CaptchaVerifier $verifier)
    {
    }

    public function handle(Request $request, callable $next, string ...$params): Response
    {
        if (!$this->verifier->enabled()) {
            return $next($request);
        }

        $token = $request->input(self::FIELD) ?? $request->input(self::WIDGET_FIELD);
        if (!is_string($token) || !$this->verifier->verify(trim($token), $request->ip())) {
            throw new ValidationException([self::FIELD => 'Please complete the security check and try again.']);
        }

        return $next($request);
    }
}
