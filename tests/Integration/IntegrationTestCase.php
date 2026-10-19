<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\App;
use App\Core\Database;
use App\Core\Kernel;
use App\Core\Migrator;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Shared\Clock;
use App\Shared\Mail\Mailer;
use App\Shared\Storage\DocumentStorage;
use Tests\Support\FakeDocumentStorage;
use PHPUnit\Framework\TestCase;

/**
 * Boots the real app against the rifa_test database (see phpunit.xml).
 * The schema is rebuilt once per run; every test runs inside a transaction
 * that is rolled back afterwards.
 */
abstract class IntegrationTestCase extends TestCase
{
    private static bool $migrated = false;

    protected App $app;

    protected Database $db;

    protected FakeDocumentStorage $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $basePath = dirname(__DIR__, 2);
        $this->app = Kernel::create($basePath);
        $this->db = $this->app->container()->get(Database::class);
        $this->storage = new FakeDocumentStorage();
        $this->app->container()->instance(DocumentStorage::class, $this->storage);

        if (!self::$migrated) {
            $migrator = new Migrator($this->db, $basePath . '/database/migrations');
            $migrator->fresh('testing');
            $migrator->runFile($basePath . '/database/seeds/job_categories.sql');
            self::$migrated = true;
        }

        $this->db->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->db->rollBack();
        parent::tearDown();
    }

    /**
     * @param array<string, mixed>  $body
     * @param array<string, string> $headers
     */
    protected function request(string $method, string $path, array $body = [], array $headers = [], array $files = [], string $ip = '203.0.113.10'): Response
    {
        $raw = '';
        if ($body !== [] && $files === []) {
            $headers += ['Content-Type' => 'application/json'];
            $raw = (string) json_encode($body);
        }

        $query = [];
        if (str_contains($path, '?')) {
            [$path, $queryString] = explode('?', $path, 2);
            parse_str($queryString, $query);
        }

        return $this->app->handle(new Request(
            $method,
            $path,
            $query,
            $files === [] ? [] : $body,
            $files,
            $headers,
            ['REMOTE_ADDR' => $ip],
            $raw,
        ));
    }

    protected function session(): Session
    {
        return $this->app->container()->get(Session::class);
    }

    protected function mailer(): Mailer
    {
        return $this->app->container()->get(Mailer::class);
    }

    protected function clock(): Clock
    {
        return $this->app->container()->get(Clock::class);
    }

    protected function createAdmin(string $role = 'super_admin', bool $active = true): int
    {
        return $this->db->insert('admins', [
            'name' => 'Test Admin',
            'email' => uniqid('admin', true) . '@example.com',
            'password_hash' => 'x',
            'role' => $role,
            'is_active' => $active,
        ]);
    }

    protected function loginAs(int $adminId): void
    {
        $this->session()->set('admin_id', $adminId);
        $this->session()->set('session_version', (int) $this->db->fetchValue('SELECT session_version FROM admins WHERE id = ?', [$adminId]));
        $this->session()->set('last_activity', $this->clock()->now()->getTimestamp());
    }
}
