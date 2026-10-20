<?php

declare(strict_types=1);

namespace App\Shared\Security;

use App\Core\Logger;

/**
 * Cloudflare Turnstile server-side check (POST /turnstile/v0/siteverify).
 *
 * If Cloudflare cannot be reached, the request is allowed and a warning is logged:
 * losing a real application is worse than letting a few bots through during an outage,
 * and the per-IP rate limits still apply.
 *
 * Cloudflare test secrets: 1x0000000000000000000000000000000AA always passes,
 * 2x0000000000000000000000000000000AA always fails (ConfigCheck rejects them on production).
 */
final class TurnstileVerifier implements CaptchaVerifier
{
    private const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
    private const MAX_TOKEN_LENGTH = 2048;

    /** @param array{enabled?: bool, secret?: string, timeout?: int} $config */
    public function __construct(private readonly array $config, private readonly Logger $logger)
    {
    }

    public function enabled(): bool
    {
        return ($this->config['enabled'] ?? false) === true;
    }

    public function verify(string $token, string $ip): bool
    {
        if ($token === '' || strlen($token) > self::MAX_TOKEN_LENGTH) {
            return false;
        }

        $result = $this->post([
            'secret' => (string) ($this->config['secret'] ?? ''),
            'response' => $token,
            'remoteip' => $ip,
        ]);

        if ($result === null) {
            $this->logger->warning('Captcha check skipped: Turnstile unreachable');

            return true;
        }
        if (($result['success'] ?? false) !== true) {
            $this->logger->info('Captcha rejected', ['codes' => $result['error-codes'] ?? []]);

            return false;
        }

        return true;
    }

    /**
     * @param array<string, string> $fields
     * @return array<string, mixed>|null null when Cloudflare could not be reached or answered garbage
     */
    private function post(array $fields): ?array
    {
        $curl = curl_init(self::VERIFY_URL);
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($fields),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => (int) ($this->config['timeout'] ?? 5),
            CURLOPT_CONNECTTIMEOUT => 3,
        ]);
        $body = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);

        if (!is_string($body) || $status !== 200) {
            return null;
        }
        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : null;
    }
}
