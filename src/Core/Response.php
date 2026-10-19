<?php

declare(strict_types=1);

namespace App\Core;

use Closure;

/**
 * Outgoing HTTP response. Controllers always return one of:
 *   Response::data($data, 201)            → { "data": ... }
 *   Response::data($rows, 200, $meta)     → { "data": [...], "meta": {...} }
 *   Response::noContent()
 *   Response::stream($writer, $headers)   → CSV downloads
 */
final class Response
{
    /** @var array<string, string> */
    private array $headers = [];

    /** @param array<string, string> $headers */
    public function __construct(
        private string|Closure $body = '',
        private int $status = 200,
        array $headers = [],
    ) {
        foreach ($headers as $name => $value) {
            $this->withHeader($name, $value);
        }
    }

    /** @param array<string, string> $headers */
    public static function json(mixed $payload, int $status = 200, array $headers = []): self
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return new self($body, $status, ['Content-Type' => 'application/json; charset=utf-8'] + $headers);
    }

    /** @param array<string, mixed>|null $meta */
    public static function data(mixed $data, int $status = 200, ?array $meta = null): self
    {
        $payload = ['data' => $data];
        if ($meta !== null) {
            $payload['meta'] = $meta;
        }

        return self::json($payload, $status);
    }

    public static function noContent(): self
    {
        return new self('', 204);
    }

    /**
     * @param callable(): void      $writer  echoes the body (e.g. fputcsv to php://output)
     * @param array<string, string> $headers
     */
    public static function stream(callable $writer, array $headers = [], int $status = 200): self
    {
        return new self(Closure::fromCallable($writer), $status, $headers);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function withHeader(string $name, string $value): self
    {
        $this->headers[$this->normaliseName($name)] = $value;

        return $this;
    }

    public function header(string $name): ?string
    {
        return $this->headers[$this->normaliseName($name)] ?? null;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }

    /** The body as a string (streams are rendered into a buffer). */
    public function body(): string
    {
        if ($this->body instanceof Closure) {
            ob_start();
            ($this->body)();

            return (string) ob_get_clean();
        }

        return $this->body;
    }

    /** @return array<string, mixed> decoded JSON body (tests) */
    public function decodedJson(): array
    {
        return (array) json_decode($this->body(), true);
    }

    public function send(): void
    {
        if (!headers_sent()) {
            header_remove('X-Powered-By');
            http_response_code($this->status);
            foreach ($this->headers as $name => $value) {
                header("{$name}: {$value}");
            }
        }

        if ($this->body instanceof Closure) {
            ($this->body)();
        } else {
            echo $this->body;
        }
    }

    private function normaliseName(string $name): string
    {
        return implode('-', array_map('ucfirst', explode('-', strtolower($name))));
    }
}
