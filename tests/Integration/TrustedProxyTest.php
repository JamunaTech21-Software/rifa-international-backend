<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Config;

final class TrustedProxyTest extends IntegrationTestCase
{
    private const VERCEL_IP = '76.76.21.21';

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->container()->get(Config::class)->set('security.proxy.secret', 'shared-secret-123');
    }

    /** @param array<string, string> $headers */
    private function send(array $headers, string $name = 'Md. Rahim Uddin'): int
    {
        $response = $this->request('POST', '/api/v1/messages', [
            'full_name' => $name, 'email' => 'r@example.com', 'phone' => '01712345678',
            'subject' => 'support', 'message' => 'Testing the proxy handling.',
        ], $headers, [], self::VERCEL_IP);

        return $response->status();
    }

    private function lastIp(): string
    {
        return (string) $this->db->fetchValue('SELECT ip_address FROM contact_messages ORDER BY id DESC LIMIT 1');
    }

    public function test_forwarded_ip_is_used_only_with_the_right_secret(): void
    {
        $this->send(['X-Rifa-Proxy-Secret' => 'shared-secret-123', 'X-Rifa-Client-Ip' => '203.0.113.50']);
        $this->assertSame('203.0.113.50', $this->lastIp());

        $this->send(['X-Rifa-Proxy-Secret' => 'shared-secret-123', 'X-Forwarded-For' => '198.51.100.7, 76.76.21.21']);
        $this->assertSame('198.51.100.7', $this->lastIp(), 'falls back to the first X-Forwarded-For address');

        $this->send(['X-Rifa-Proxy-Secret' => 'wrong', 'X-Rifa-Client-Ip' => '203.0.113.50']);
        $this->assertSame(self::VERCEL_IP, $this->lastIp(), 'wrong secret: header ignored');

        $this->send(['X-Rifa-Client-Ip' => '203.0.113.50']);
        $this->assertSame(self::VERCEL_IP, $this->lastIp(), 'no secret: header ignored (no spoofing)');

        $this->send(['X-Rifa-Proxy-Secret' => 'shared-secret-123', 'X-Rifa-Client-Ip' => 'not-an-ip']);
        $this->assertSame(self::VERCEL_IP, $this->lastIp(), 'invalid forwarded value ignored');
    }

    public function test_empty_secret_trusts_nobody(): void
    {
        $this->app->container()->get(Config::class)->set('security.proxy.secret', '');

        $this->send(['X-Rifa-Proxy-Secret' => '', 'X-Rifa-Client-Ip' => '203.0.113.50']);

        $this->assertSame(self::VERCEL_IP, $this->lastIp());
    }

    public function test_rate_limits_apply_per_real_visitor_behind_the_proxy(): void
    {
        $visitor = fn (string $ip) => ['X-Rifa-Proxy-Secret' => 'shared-secret-123', 'X-Rifa-Client-Ip' => $ip];

        for ($i = 0; $i < 5; $i++) {
            $this->assertSame(201, $this->send($visitor('203.0.113.1')));
        }
        $this->assertSame(429, $this->send($visitor('203.0.113.1')), 'same visitor is limited');
        $this->assertSame(201, $this->send($visitor('203.0.113.2')), 'another visitor behind the same proxy is not');
    }
}
