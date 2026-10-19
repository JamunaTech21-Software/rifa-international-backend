<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Http\UploadedFile;

/**
 * Incoming HTTP request. Build it with Request::capture() in production or
 * with the constructor in tests.
 */
final class Request
{
    /** @var array<string, string> lower-cased header name => value */
    private array $headers = [];

    /** @var array<string, mixed> */
    private array $attributes = [];

    /** @var array<string, mixed> */
    private array $json = [];

    private bool $invalidJson = false;

    /**
     * @param array<string, mixed>        $query
     * @param array<string, mixed>        $body   form fields ($_POST)
     * @param array<string, UploadedFile> $files
     * @param array<string, string>       $headers
     * @param array<string, mixed>        $server
     */
    public function __construct(
        private readonly string $method,
        private readonly string $path,
        private readonly array $query = [],
        private readonly array $body = [],
        private readonly array $files = [],
        array $headers = [],
        private readonly array $server = [],
        private readonly string $rawBody = '',
    ) {
        foreach ($headers as $name => $value) {
            $this->headers[strtolower($name)] = $value;
        }

        if ($this->isJson() && trim($this->rawBody) !== '') {
            $decoded = json_decode($this->rawBody, true);
            if (is_array($decoded)) {
                $this->json = $decoded;
            } else {
                $this->invalidJson = true;
            }
        }
    }

    public static function capture(): self
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $headers[str_replace('_', '-', substr($key, 5))] = (string) $value;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['Content-Type'] = (string) $_SERVER['CONTENT_TYPE'];
        }

        $files = [];
        foreach ($_FILES as $name => $entry) {
            $file = UploadedFile::fromArray($entry);
            if ($file !== null) {
                $files[$name] = $file;
            }
        }

        $path = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));

        return new self(
            strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            $path,
            $_GET,
            $_POST,
            $files,
            $headers,
            $_SERVER,
            (string) file_get_contents('php://input'),
        );
    }

    public function method(): string
    {
        return strtoupper($this->method);
    }

    public function path(): string
    {
        $path = '/' . trim($this->path, '/');

        return $path === '/' ? '/' : $path;
    }

    public function query(?string $key = null, mixed $default = null): mixed
    {
        return $key === null ? $this->query : ($this->query[$key] ?? $default);
    }

    /** Body fields: JSON body when the request is JSON, otherwise form fields. */
    public function input(?string $key = null, mixed $default = null): mixed
    {
        $data = $this->isJson() ? $this->json : $this->body;

        return $key === null ? $data : ($data[$key] ?? $default);
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->input();
    }

    public function file(string $key): ?UploadedFile
    {
        return $this->files[$key] ?? null;
    }

    /** @return array<string, UploadedFile> */
    public function files(): array
    {
        return $this->files;
    }

    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    public function isJson(): bool
    {
        return str_contains(strtolower($this->header('Content-Type', '') ?? ''), 'application/json');
    }

    public function hasInvalidJson(): bool
    {
        return $this->invalidJson;
    }

    public function ip(): string
    {
        return (string) ($this->server['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    public function userAgent(): string
    {
        return mb_substr($this->header('User-Agent', '') ?? '', 0, 255);
    }

    public function setAttribute(string $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
    }

    public function attribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    /** Route parameter, e.g. {id} in /admin/applications/{id}. */
    public function param(string $key, ?string $default = null): ?string
    {
        return $this->attributes['params'][$key] ?? $default;
    }
}
