<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Shared\Storage\DocumentStorage;
use App\Shared\Storage\InvalidDocumentException;
use RuntimeException;

/** In-memory DocumentStorage for tests. Can be told to fail on a given upload. */
final class FakeDocumentStorage implements DocumentStorage
{
    /** @var array<string, array{folder: string, bytes: int}> public_id => info */
    public array $stored = [];

    /** @var list<string> */
    public array $deleted = [];

    /** @var array<string, 'invalid'|'error'|'long_id'> public id (e.g. 'cv') => failure to simulate */
    public array $failOn = [];

    public function uploadPrivate(string $localPath, string $folder, string $publicId): array
    {
        $failure = $this->failOn[$publicId] ?? null;
        if ($failure === 'invalid') {
            throw new InvalidDocumentException('Invalid PDF file');
        }
        if ($failure === 'error') {
            throw new RuntimeException('Cloudinary is down');
        }

        $id = trim($folder, '/') . '/' . $publicId;
        if ($failure === 'long_id') {
            $id .= str_repeat('x', 300); // too long for the DB column → insert fails after upload
        }
        $bytes = (int) filesize($localPath);
        $this->stored[$id] = ['folder' => $folder, 'bytes' => $bytes];
        $format = str_contains((string) mime_content_type($localPath), 'pdf') ? 'pdf' : 'png';

        return ['public_id' => $id, 'resource_type' => 'image', 'format' => $format, 'bytes' => $bytes];
    }

    public function delete(string $publicId, string $resourceType): void
    {
        unset($this->stored[$publicId]);
        $this->deleted[] = $publicId;
    }

    public function temporaryUrl(string $publicId, string $resourceType, string $format, int $ttlSeconds): array
    {
        return ['url' => "https://fake.local/{$publicId}.{$format}?ttl={$ttlSeconds}", 'expires_at' => time() + $ttlSeconds];
    }
}
