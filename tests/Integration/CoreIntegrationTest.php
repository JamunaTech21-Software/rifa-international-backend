<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Exceptions\RateLimitedException;
use App\Core\Exceptions\UnauthenticatedException;
use App\Core\Middleware\Auth;
use App\Core\Middleware\RateLimit;
use App\Core\Migrator;
use App\Core\Request;
use App\Core\Response;
use App\Shared\Security\RateLimiter;
use RuntimeException;

final class CoreIntegrationTest extends IntegrationTestCase
{
    public function test_all_tables_exist_and_rerunning_migrations_is_a_noop(): void
    {
        $tables = array_column(
            $this->db->fetchAll('SELECT table_name AS t FROM information_schema.tables WHERE table_schema = DATABASE()'),
            't',
        );

        foreach (['migrations', 'admins', 'password_resets', 'job_categories', 'applications', 'application_documents',
            'application_status_history', 'application_notes', 'reference_counters', 'contact_messages',
            'message_replies', 'rate_limits', 'audit_logs'] as $table) {
            $this->assertContains($table, $tables);
        }

        $migrator = new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations');
        $this->assertSame([], $migrator->pending());
    }

    public function test_fresh_refuses_production(): void
    {
        $this->expectException(RuntimeException::class);
        (new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations'))->fresh('production');
    }

    public function test_database_insert_fetch_and_transaction_rollback(): void
    {
        $id = $this->db->insert('contact_messages', [
            'full_name' => 'Karim', 'email' => 'k@example.com', 'phone' => '01712345678',
            'subject' => 'support', 'message' => 'Hello there', 'ip_address' => '127.0.0.1',
        ]);
        $this->assertSame('Karim', $this->db->fetchValue('SELECT full_name FROM contact_messages WHERE id = ?', [$id]));

        try {
            $this->db->transaction(function () use ($id): void {
                $this->db->execute('UPDATE contact_messages SET full_name = ? WHERE id = ?', ['Changed', $id]);
                throw new RuntimeException('fail');
            });
        } catch (RuntimeException) {
        }

        // The outer test transaction was rolled back too, so the row is gone.
        $this->assertNull($this->db->fetchOne('SELECT id FROM contact_messages WHERE id = ?', [$id]));
        $this->db->beginTransaction(); // keep tearDown balanced
    }

    public function test_database_uses_dhaka_timezone(): void
    {
        $this->assertSame('+06:00', $this->db->fetchValue('SELECT @@session.time_zone'));
    }

    public function test_rate_limiter_counts_and_resets_after_window(): void
    {
        $limiter = $this->app->container()->get(RateLimiter::class);
        $this->clock()->freeze('2026-10-20 10:00:00');

        $this->assertSame(1, $limiter->hit('t|1.1.1.1', 3600));
        $this->assertSame(2, $limiter->hit('t|1.1.1.1', 3600));
        $this->assertSame(2, $limiter->attempts('t|1.1.1.1', 3600));
        $this->assertSame(3600, $limiter->availableIn('t|1.1.1.1', 3600));

        $this->clock()->freeze('2026-10-20 11:00:01');
        $this->assertSame(0, $limiter->attempts('t|1.1.1.1', 3600));
        $this->assertSame(1, $limiter->hit('t|1.1.1.1', 3600));
    }

    public function test_throttle_counts_only_successful_requests_per_ip(): void
    {
        $middleware = $this->app->container()->get(RateLimit::class);
        $ok = fn () => Response::data('ok', 201);
        $invalid = fn () => Response::json(['error' => []], 422);
        $request = fn (string $ip) => new Request('POST', '/x', [], [], [], [], ['REMOTE_ADDR' => $ip]);

        $middleware->handle($request('9.9.9.9'), $invalid, 'apply', '2', '3600');
        $middleware->handle($request('9.9.9.9'), $ok, 'apply', '2', '3600');
        $middleware->handle($request('9.9.9.9'), $ok, 'apply', '2', '3600');
        $this->assertSame(201, $middleware->handle($request('8.8.8.8'), $ok, 'apply', '2', '3600')->status());

        try {
            $middleware->handle($request('9.9.9.9'), $ok, 'apply', '2', '3600');
            $this->fail('Expected 429');
        } catch (RateLimitedException $e) {
            $this->assertSame(429, $e->status());
            $this->assertGreaterThan(0, (int) $e->headers()['Retry-After']);
        }
    }

    public function test_auth_rejects_missing_session(): void
    {
        $this->expectException(UnauthenticatedException::class);
        $this->app->container()->get(Auth::class)->handle(new Request('GET', '/x'), fn () => Response::data('ok'));
    }

    public function test_auth_rejects_inactive_admin(): void
    {
        $this->loginAs($this->createAdmin('staff', false));

        $this->expectException(UnauthenticatedException::class);
        $this->app->container()->get(Auth::class)->handle(new Request('GET', '/x'), fn () => Response::data('ok'));
    }

    public function test_auth_expires_idle_session(): void
    {
        $this->clock()->freeze('2026-10-20 10:00:00');
        $this->loginAs($this->createAdmin());
        $this->clock()->freeze('2026-10-20 11:00:01');

        try {
            $this->app->container()->get(Auth::class)->handle(new Request('GET', '/x'), fn () => Response::data('ok'));
            $this->fail('Expected 401');
        } catch (UnauthenticatedException $e) {
            $this->assertSame('Your session has expired. Please log in again.', $e->getMessage());
            $this->assertNull($this->session()->get('admin_id'));
        }
    }

    public function test_auth_sets_admin_attribute_and_refreshes_activity(): void
    {
        $this->clock()->freeze('2026-10-20 10:00:00');
        $id = $this->createAdmin('staff');
        $this->loginAs($id);
        $this->clock()->freeze('2026-10-20 10:30:00');

        $request = new Request('GET', '/x');
        $this->app->container()->get(Auth::class)->handle($request, fn () => Response::data('ok'));

        $this->assertSame($id, $request->attribute('admin')['id']);
        $this->assertSame('staff', $request->attribute('admin')['role']);
        $this->assertArrayNotHasKey('password_hash', $request->attribute('admin'));
        $this->assertSame($this->clock()->now()->getTimestamp(), $this->session()->get('last_activity'));
    }
}
