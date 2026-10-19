<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Middleware\Auth;
use App\Core\Middleware\Csrf;
use App\Core\Middleware\JsonBody;
use App\Core\Middleware\RateLimit;
use App\Core\Middleware\Role;
use App\Shared\Clock;
use Dotenv\Dotenv;
use ErrorException;

/**
 * Builds the application: env → config → services → module routes.
 * Used by public/index.php, the CLI scripts in database/ and the tests.
 */
final class Kernel
{
    public const MIDDLEWARE = [
        'json' => JsonBody::class,
        'throttle' => RateLimit::class,
        'auth' => Auth::class,
        'role' => Role::class,
        'csrf' => Csrf::class,
    ];

    public static function create(string $basePath): App
    {
        if (is_file($basePath . '/.env')) {
            Dotenv::createImmutable($basePath)->safeLoad();
        }

        $config = Config::load($basePath . '/config');
        $config->set('app.base_path', $basePath);

        date_default_timezone_set((string) $config->get('app.timezone', 'Asia/Dhaka'));
        if ($config->get('app.env') !== 'testing') {
            self::registerErrorHandler(); // PHPUnit installs its own handler
        }

        $container = new Container();
        self::registerServices($container, $config);

        $router = new Router();
        $router->group((string) $config->get('app.api_prefix', '/api/v1'), ['json'], function (Router $router) use ($basePath): void {
            foreach (glob($basePath . '/src/Modules/*/routes.php') ?: [] as $file) {
                (require $file)($router);
            }
        });

        return new App($container, $router, $container->get(ErrorHandler::class), self::MIDDLEWARE);
    }

    private static function registerServices(Container $container, Config $config): void
    {
        $container->instance(Config::class, $config);

        $container->singleton(Logger::class, fn () => new Logger(
            $config->get('app.base_path') . '/storage/logs',
            (string) $config->get('app.log_level', 'debug'),
        ));

        $container->singleton(ErrorHandler::class, fn (Container $c) => new ErrorHandler(
            $c->get(Logger::class),
            (bool) $config->get('app.debug', false),
        ));

        $container->singleton(Database::class, fn () => new Database($config->get('database')));

        $container->singleton(Clock::class, fn () => new Clock((string) $config->get('app.timezone', 'Asia/Dhaka')));

        $container->singleton(Session::class, fn () => new Session($config->get('security.session', [])));
    }

    /** Turn PHP warnings/notices into exceptions so they are handled and logged. */
    private static function registerErrorHandler(): void
    {
        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if (!(error_reporting() & $severity)) {
                return false;
            }
            throw new ErrorException($message, 0, $severity, $file, $line);
        });
    }
}
