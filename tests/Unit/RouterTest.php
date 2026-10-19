<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Exceptions\MethodNotAllowedException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Router;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    public function test_matches_static_route(): void
    {
        $router = new Router();
        $router->get('/health', 'handler');

        [$route, $params] = $router->match('GET', '/health');

        $this->assertSame('handler', $route['handler']);
        $this->assertSame([], $params);
    }

    public function test_extracts_params_and_honours_constraints(): void
    {
        $router = new Router();
        $router->get('/applications/{id:\d+}/documents/{docId}', 'doc');

        [, $params] = $router->match('GET', '/applications/42/documents/7');
        $this->assertSame(['id' => '42', 'docId' => '7'], $params);

        $this->expectException(NotFoundException::class);
        $router->match('GET', '/applications/abc/documents/7');
    }

    public function test_group_applies_prefix_and_middleware_in_order(): void
    {
        $router = new Router();
        $router->group('/api/v1', ['json'], function (Router $r): void {
            $r->group('/admin', ['auth'], function (Router $r): void {
                $r->post('/notes', 'note', ['csrf']);
            });
        });

        [$route] = $router->match('POST', '/api/v1/admin/notes/');

        $this->assertSame('/api/v1/admin/notes', $route['path']);
        $this->assertSame(['json', 'auth', 'csrf'], $route['middleware']);
    }

    public function test_wrong_method_is_405_with_allow_header(): void
    {
        $router = new Router();
        $router->get('/lookups', 'x');
        $router->patch('/lookups', 'y');

        try {
            $router->match('DELETE', '/lookups');
            $this->fail('Expected 405');
        } catch (MethodNotAllowedException $e) {
            $this->assertSame(405, $e->status());
            $this->assertSame('GET, PATCH', $e->headers()['Allow']);
        }
    }

    public function test_head_is_served_by_get_route(): void
    {
        $router = new Router();
        $router->get('/health', 'h');

        [$route] = $router->match('HEAD', '/health');

        $this->assertSame('h', $route['handler']);
    }

    public function test_unknown_path_is_404(): void
    {
        $this->expectException(NotFoundException::class);
        (new Router())->match('GET', '/missing');
    }
}
