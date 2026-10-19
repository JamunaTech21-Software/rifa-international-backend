<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Admin session. Started lazily (only admin routes use it), so public
 * endpoints never set a cookie. In CLI/tests it is backed by a plain array.
 */
final class Session
{
    /** @var array<string, mixed> */
    private array $memory = [];

    private bool $started = false;

    /**
     * @param array{name?: string, secure?: bool, samesite?: string, idle_minutes?: int, save_path?: string} $config
     */
    public function __construct(
        private readonly array $config = [],
        private readonly bool $native = PHP_SAPI !== 'cli',
    ) {
    }

    public function start(): void
    {
        if ($this->started) {
            return;
        }
        $this->started = true;

        if (!$this->native || session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $savePath = $this->config['save_path'] ?? null;
        if (is_string($savePath) && (is_dir($savePath) || @mkdir($savePath, 0700, true)) && is_writable($savePath)) {
            session_save_path($savePath);
        }
        ini_set('session.gc_maxlifetime', (string) ((int) ($this->config['idle_minutes'] ?? 60) * 60));
        session_name($this->config['name'] ?? 'rifa_session');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => (bool) ($this->config['secure'] ?? true),
            'httponly' => true,
            'samesite' => $this->config['samesite'] ?? 'Strict',
        ]);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        session_start();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->start();

        return $this->store()[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->start();
        $store = &$this->store();
        $store[$key] = $value;
    }

    public function has(string $key): bool
    {
        $this->start();

        return array_key_exists($key, $this->store());
    }

    public function forget(string $key): void
    {
        $this->start();
        $store = &$this->store();
        unset($store[$key]);
    }

    /** New session id (call after login to prevent session fixation). */
    public function regenerate(): void
    {
        $this->start();
        if ($this->native && session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public function destroy(): void
    {
        $this->start();
        $store = &$this->store();
        $store = [];

        if ($this->native && session_status() === PHP_SESSION_ACTIVE) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 3600,
                'path' => $params['path'],
                'secure' => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite'],
            ]);
            session_destroy();
            $this->started = false;
        }
    }

    /** @return array<string, mixed> */
    private function &store(): array
    {
        if ($this->native) {
            $_SESSION ??= [];

            return $_SESSION;
        }

        return $this->memory;
    }
}
