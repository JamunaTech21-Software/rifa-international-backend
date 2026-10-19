<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\App;
use App\Core\Container;
use App\Core\ErrorHandler;
use App\Core\Exceptions\ValidationException;
use App\Core\Logger;
use App\Core\Middleware\JsonBody;
use App\Core\Middleware\Middleware;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AppTest extends TestCase
{
    private string $logDir;

    protected function setUp(): void
    {
        $this->logDir = sys_get_temp_dir() . '/rifa-test-logs-' . uniqid();
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->logDir . '/*') ?: []);
        @rmdir($this->logDir);
    }

    private function app(Router $router, bool $debug = false): App
    {
        return new App(
            new Container(),
            $router,
            new ErrorHandler(new Logger($this->logDir), $debug),
            ['json' => JsonBody::class, 'tag' => TagMiddleware::class],
        );
    }

    public function test_returns_controller_response_with_default_headers(): void
    {
        $router = new Router();
        $router->get('/ping', fn () => Response::data(['pong' => true]));

        $response = $this->app($router)->handle(new Request('GET', '/ping'));

        $this->assertSame(200, $response->status());
        $this->assertSame(['data' => ['pong' => true]], $response->decodedJson());
        $this->assertSame('no-store', $response->header('Cache-Control'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{16}$/', (string) $response->header('X-Request-Id'));
    }

    public function test_unknown_route_returns_404_json(): void
    {
        $response = $this->app(new Router())->handle(new Request('GET', '/nope'));

        $this->assertSame(404, $response->status());
        $this->assertSame('not_found', $response->decodedJson()['error']['code']);
    }

    public function test_validation_exception_maps_to_422_with_fields(): void
    {
        $router = new Router();
        $router->post('/form', fn () => throw new ValidationException(['mobile' => 'Enter a valid mobile number.']));

        $response = $this->app($router)->handle(new Request('POST', '/form'));

        $this->assertSame(422, $response->status());
        $this->assertSame([
            'error' => [
                'code' => 'validation_failed',
                'message' => 'Please check the highlighted fields.',
                'fields' => ['mobile' => 'Enter a valid mobile number.'],
            ],
        ], $response->decodedJson());
    }

    public function test_unexpected_error_is_500_logged_and_hidden_without_debug(): void
    {
        $router = new Router();
        $router->get('/boom', fn () => throw new RuntimeException('secret db detail'));

        $response = $this->app($router)->handle(new Request('GET', '/boom'));
        $body = $response->decodedJson();

        $this->assertSame(500, $response->status());
        $this->assertSame('server_error', $body['error']['code']);
        $this->assertArrayNotHasKey('debug', $body['error']);
        $this->assertStringNotContainsString('secret db detail', $response->body());
        $this->assertStringContainsString('secret db detail', (string) file_get_contents(glob($this->logDir . '/*.log')[0]));
    }

    public function test_debug_mode_includes_exception_details(): void
    {
        $router = new Router();
        $router->get('/boom', fn () => throw new RuntimeException('detail'));

        $body = $this->app($router, true)->handle(new Request('GET', '/boom'))->decodedJson();

        $this->assertSame('detail', $body['error']['debug']['message']);
    }

    public function test_invalid_json_body_is_400(): void
    {
        $router = new Router();
        $router->post('/data', fn () => Response::data([]), ['json']);

        $response = $this->app($router)->handle(
            new Request('POST', '/data', [], [], [], ['Content-Type' => 'application/json'], [], '{oops'),
        );

        $this->assertSame(400, $response->status());
        $this->assertSame('invalid_json', $response->decodedJson()['error']['code']);
    }

    public function test_middleware_receives_params_and_runs_in_order(): void
    {
        $router = new Router();
        $router->get('/tagged', fn (Request $r) => Response::data($r->attribute('tags')), ['tag:a,b', 'tag:c']);

        $response = $this->app($router)->handle(new Request('GET', '/tagged'));

        $this->assertSame(['a+b', 'c'], $response->decodedJson()['data']);
    }

    public function test_controller_class_is_resolved_from_container(): void
    {
        $router = new Router();
        $router->get('/items/{id}', [EchoController::class, 'show']);

        $response = $this->app($router)->handle(new Request('GET', '/items/9'));

        $this->assertSame(['id' => '9'], $response->decodedJson()['data']);
    }
}

final class TagMiddleware implements Middleware
{
    public function handle(Request $request, callable $next, string ...$params): Response
    {
        $request->setAttribute('tags', [...$request->attribute('tags', []), implode('+', $params)]);

        return $next($request);
    }
}

final class EchoController
{
    public function show(Request $request): Response
    {
        return Response::data(['id' => $request->param('id')]);
    }
}
