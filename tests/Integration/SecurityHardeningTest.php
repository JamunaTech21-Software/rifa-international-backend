<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Http\UploadedFile;
use App\Core\Logger;
use App\Core\Request;
use Tests\Support\Fixtures;

/** BE-15 checks that guard the whole API, not one feature. */
final class SecurityHardeningTest extends IntegrationTestCase
{
    /** Admin routes that must work without a session. */
    private const PUBLIC_ADMIN_ROUTES = [
        'POST /api/v1/admin/auth/login',
        'POST /api/v1/admin/auth/forgot-password',
        'POST /api/v1/admin/auth/reset-password',
    ];

    public function test_every_route_parses_proxy_and_json_and_every_admin_route_requires_auth_and_csrf(): void
    {
        $routes = $this->app->router()->routes();
        $this->assertGreaterThan(20, count($routes));

        foreach ($routes as $route) {
            $id = "{$route['method']} {$route['path']}";
            $this->assertSame(['proxy', 'json'], array_slice($route['middleware'], 0, 2), "{$id} must start with proxy, json");

            if (!str_starts_with($route['path'], '/api/v1/admin/') || in_array($id, self::PUBLIC_ADMIN_ROUTES, true)) {
                continue;
            }
            $this->assertContains('auth', $route['middleware'], "{$id} must require login");
            if ($id !== 'GET /api/v1/admin/auth/me') {
                $this->assertContains('csrf', $route['middleware'], "{$id} must check the CSRF token");
            }
        }
    }

    public function test_super_admin_only_routes(): void
    {
        $byId = [];
        foreach ($this->app->router()->routes() as $route) {
            $byId["{$route['method']} {$route['path']}"] = $route['middleware'];
        }

        foreach (['GET /api/v1/admin/users', 'POST /api/v1/admin/users', 'PATCH /api/v1/admin/users/{id:\d+}', 'DELETE /api/v1/admin/applications/{id:\d+}'] as $id) {
            $this->assertContains('role:super_admin', $byId[$id] ?? [], $id);
        }
    }

    public function test_security_headers_on_success_and_error_responses(): void
    {
        foreach (['/api/v1/health', '/api/v1/nope', '/api/v1/admin/applications'] as $path) {
            $response = $this->request('GET', $path);
            $this->assertSame('nosniff', $response->header('X-Content-Type-Options'), $path);
            $this->assertSame('DENY', $response->header('X-Frame-Options'), $path);
            $this->assertSame("default-src 'none'; frame-ancestors 'none'", $response->header('Content-Security-Policy'), $path);
            $this->assertSame('no-referrer', $response->header('Referrer-Policy'), $path);
            $this->assertNull($response->header('Strict-Transport-Security'), 'HSTS only on staging/production');
        }
        $this->assertSame('no-store', $this->request('GET', '/api/v1/admin/applications')->header('Cache-Control'));
    }

    public function test_oversized_upload_gets_a_clear_413(): void
    {
        $request = new Request('POST', '/api/v1/applications', [], [], [], ['Content-Type' => 'multipart/form-data; boundary=x'], ['REMOTE_ADDR' => '1.2.3.4']);
        $request->markBodyTooLarge();

        $response = $this->app->handle($request);

        $this->assertSame(413, $response->status());
        $this->assertSame([
            'code' => 'payload_too_large',
            'message' => 'The upload is too large. Each file must be 5 MB or smaller.',
        ], $response->decodedJson()['error']);
    }

    public function test_logs_contain_no_personal_data(): void
    {
        $dir = sys_get_temp_dir() . '/rifa-pii-' . uniqid();
        $this->app->container()->instance(Logger::class, new Logger($dir, 'debug'));
        $pdf = Fixtures::tempFile(Fixtures::pdf());

        $this->app->handle(new Request('POST', '/api/v1/applications', [], [
            'full_name' => 'Zubaida Khatun', 'father_name' => 'Abdul Khatun', 'date_of_birth' => '1990-01-01',
            'gender' => 'female', 'nid_no' => '19901234567890123', 'mobile' => '01798765432', 'email' => 'zubaida@example.com',
            'present_address' => 'Unique Street 77, Sylhet', 'job_category' => 'cleaning-service',
            'passport_status' => 'have_passport', 'declaration' => '1',
        ], ['passport_copy' => new UploadedFile('p.pdf', $pdf, (int) filesize($pdf), UPLOAD_ERR_OK, true)], [], ['REMOTE_ADDR' => '1.2.3.4']));
        // A login attempt with a wrong password must not log the password or email either.
        $this->request('POST', '/api/v1/admin/auth/login', ['email' => 'zubaida@example.com', 'password' => 'Secret-Pass-99']);

        $logs = implode("\n", array_map('file_get_contents', glob($dir . '/*.log') ?: []));
        $this->assertStringContainsString('Application submitted', $logs, 'the submission itself is logged');
        foreach (['Zubaida', '19901234567890123', '01798765432', 'zubaida@example.com', 'Unique Street', 'Secret-Pass-99'] as $secret) {
            $this->assertStringNotContainsString($secret, $logs);
        }

        unlink($pdf);
        array_map('unlink', glob($dir . '/*') ?: []);
        rmdir($dir);
    }
}
