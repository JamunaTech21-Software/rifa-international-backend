<?php

declare(strict_types=1);

namespace App\Core\Middleware;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;

/**
 * Alias: proxy (runs first on every API route).
 *
 * The public site and the admin app are served by Vercel, which forwards /api/* to this backend, so
 * REMOTE_ADDR is a Vercel address shared by every visitor. Vercel middleware adds two headers:
 *   X-Rifa-Proxy-Secret  = TRUSTED_PROXY_SECRET (shared secret, never sent to browsers)
 *   X-Rifa-Client-Ip     = the visitor's real IP
 * Only when the secret matches is the forwarded IP used for rate limiting, audit logs and stored IPs.
 * Without it (direct calls, wrong secret) REMOTE_ADDR is used, so the IP cannot be spoofed.
 */
final class TrustedProxy implements Middleware
{
    public function __construct(private readonly Config $config)
    {
    }

    public function handle(Request $request, callable $next, string ...$params): Response
    {
        $secret = (string) $this->config->get('security.proxy.secret', '');
        $given = $request->header((string) $this->config->get('security.proxy.secret_header', 'X-Rifa-Proxy-Secret'));

        if ($secret !== '' && is_string($given) && hash_equals($secret, $given)) {
            $ip = $this->forwardedIp($request);
            if ($ip !== null) {
                $request->setAttribute('client_ip', $ip);
            }
        }

        return $next($request);
    }

    private function forwardedIp(Request $request): ?string
    {
        $candidates = [
            $request->header((string) $this->config->get('security.proxy.client_ip_header', 'X-Rifa-Client-Ip')),
            // Fallback: first address of X-Forwarded-For (the original client).
            explode(',', (string) $request->header('X-Forwarded-For', ''))[0],
        ];

        foreach ($candidates as $candidate) {
            $ip = trim((string) $candidate);
            if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP) !== false) {
                return $ip;
            }
        }

        return null;
    }
}
