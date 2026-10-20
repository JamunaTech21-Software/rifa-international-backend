<?php

declare(strict_types=1);

namespace App\Shared\Security;

/**
 * Bot check for the public forms (P5-06). Production: TurnstileVerifier.
 * Integration tests bind tests/Support/FakeCaptchaVerifier.
 */
interface CaptchaVerifier
{
    /** False while CAPTCHA_ENABLED=false: the forms are accepted without a token. */
    public function enabled(): bool;

    /** True when the token proves a human solved the challenge. */
    public function verify(string $token, string $ip): bool;
}
