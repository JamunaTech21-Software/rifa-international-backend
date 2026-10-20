<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\App;
use App\Core\Config;
use App\Core\ConfigCheck;
use App\Core\Container;
use App\Core\ErrorHandler;
use App\Core\Logger;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use PHPUnit\Framework\TestCase;

final class ConfigCheckTest extends TestCase
{
    /** @return array<string, mixed> */
    private function production(array $overrides = []): array
    {
        $config = [
            'app' => ['env' => 'production', 'debug' => false, 'url' => 'https://rifaint.com', 'admin_url' => 'https://admin.rifaint.com', 'log_level' => 'info'],
            'security' => [
                'session' => ['secure' => true],
                'proxy' => ['secret' => str_repeat('a', 64)],
                'captcha' => ['enabled' => true, 'secret' => '0x4AAAAAAA-real-secret'],
            ],
            'cloudinary' => ['cloud_name' => 'c', 'api_key' => 'k', 'api_secret' => 's'],
            'database' => ['password' => 'secret'],
            'mail' => ['driver' => 'smtp', 'host' => 'smtp.gmail.com'],
        ];

        return array_replace_recursive($config, $overrides);
    }

    public function test_good_production_config_has_no_problems(): void
    {
        $this->assertSame(['errors' => [], 'warnings' => []], ConfigCheck::run(new Config($this->production())));
        $this->assertTrue(ConfigCheck::isStrict(new Config($this->production())));
        $this->assertFalse(ConfigCheck::isStrict(new Config(['app' => ['env' => 'local']])));
    }

    public function test_each_unsafe_setting_is_reported(): void
    {
        $result = ConfigCheck::run(new Config($this->production([
            'app' => ['debug' => true, 'url' => 'http://rifaint.com', 'log_level' => 'debug'],
            'security' => ['session' => ['secure' => false], 'proxy' => ['secret' => 'short'], 'captcha' => ['enabled' => false]],
            'cloudinary' => ['api_secret' => ''],
            'database' => ['password' => ''],
            'mail' => ['driver' => 'log'],
        ])));

        $this->assertCount(6, $result['errors']);
        $this->assertStringContainsString('APP_DEBUG', $result['errors'][0]);
        $this->assertStringContainsString('SESSION_SECURE', $result['errors'][1]);
        $this->assertStringContainsString('TRUSTED_PROXY_SECRET', $result['errors'][2]);
        $this->assertStringContainsString('APP_URL', $result['errors'][3]);
        $this->assertStringContainsString('CLOUDINARY_API_SECRET', $result['errors'][4]);
        $this->assertStringContainsString('DB_PASSWORD', $result['errors'][5]);
        $this->assertCount(3, $result['warnings']);
        $this->assertStringContainsString('CAPTCHA_ENABLED', $result['warnings'][1]);
    }

    public function test_enabled_captcha_needs_the_real_secret(): void
    {
        foreach (['', '1x0000000000000000000000000000000AA', '2x0000000000000000000000000000000AA'] as $secret) {
            $errors = ConfigCheck::run(new Config($this->production(['security' => ['captcha' => ['secret' => $secret]]])))['errors'];

            $this->assertCount(1, $errors, $secret);
            $this->assertStringContainsString('TURNSTILE_SECRET_KEY', $errors[0]);
        }
    }

    public function test_misconfigured_app_answers_503_without_details_and_sends_hsts(): void
    {
        $dir = sys_get_temp_dir() . '/rifa-cfg-' . uniqid();
        $container = new Container();
        $container->instance(Logger::class, new Logger($dir));
        $router = new Router();
        $router->get('/ping', fn () => Response::data('pong'));
        $app = new App($container, $router, new ErrorHandler(new Logger($dir)), [], ['APP_DEBUG must be false'], true);

        $response = $app->handle(new Request('GET', '/ping'));

        $this->assertSame(503, $response->status());
        $this->assertSame('server_misconfigured', $response->decodedJson()['error']['code']);
        $this->assertStringNotContainsString('APP_DEBUG', $response->body());
        $this->assertSame('max-age=31536000; includeSubDomains', $response->header('Strict-Transport-Security'));
        $this->assertStringContainsString('APP_DEBUG must be false', (string) file_get_contents(glob($dir . '/*.log')[0]));

        array_map('unlink', glob($dir . '/*') ?: []);
        rmdir($dir);
    }
}
