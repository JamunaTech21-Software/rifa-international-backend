<?php

declare(strict_types=1);

namespace App\Core\Http;

use finfo;

/**
 * One file from $_FILES. The real MIME type is always read from the file
 * contents (finfo), never trusted from the browser.
 */
final class UploadedFile
{
    public function __construct(
        private readonly string $clientName,
        private readonly string $path,
        private readonly int $size,
        private readonly int $error = UPLOAD_ERR_OK,
        private readonly bool $trusted = false, // true only for files created in tests
    ) {
    }

    /** @param array{name?: mixed, tmp_name?: mixed, size?: mixed, error?: mixed} $entry */
    public static function fromArray(array $entry): ?self
    {
        if (!is_string($entry['name'] ?? null)) {
            return null; // multi-file inputs are not supported
        }

        return new self(
            $entry['name'],
            (string) ($entry['tmp_name'] ?? ''),
            (int) ($entry['size'] ?? 0),
            (int) ($entry['error'] ?? UPLOAD_ERR_NO_FILE),
        );
    }

    public function clientName(): string
    {
        return $this->clientName;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function size(): int
    {
        return $this->size;
    }

    public function error(): int
    {
        return $this->error;
    }

    public function isMissing(): bool
    {
        return $this->error === UPLOAD_ERR_NO_FILE;
    }

    public function isValid(): bool
    {
        if ($this->error !== UPLOAD_ERR_OK || !is_file($this->path)) {
            return false;
        }

        return $this->trusted || is_uploaded_file($this->path);
    }

    public function isTooLarge(): bool
    {
        return in_array($this->error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true);
    }

    public function extension(): string
    {
        return strtolower(pathinfo($this->clientName, PATHINFO_EXTENSION));
    }

    public function mimeType(): string
    {
        if (!is_file($this->path)) {
            return '';
        }

        return (string) (new finfo(FILEINFO_MIME_TYPE))->file($this->path);
    }
}
