<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Shared\Security\CaptchaVerifier;

/** Enabled captcha that accepts only the token "pass" and remembers what it was asked. */
final class FakeCaptchaVerifier implements CaptchaVerifier
{
    /** @var list<array{token: string, ip: string}> */
    public array $calls = [];

    public function enabled(): bool
    {
        return true;
    }

    public function verify(string $token, string $ip): bool
    {
        $this->calls[] = ['token' => $token, 'ip' => $ip];

        return $token === 'pass';
    }
}
