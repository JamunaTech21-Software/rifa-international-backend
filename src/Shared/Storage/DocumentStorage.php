<?php

declare(strict_types=1);

namespace App\Shared\Storage;

/**
 * Private storage for applicant documents. Production: CloudinaryService.
 * Tests bind an in-memory fake (tests/Support/FakeDocumentStorage).
 */
interface DocumentStorage
{
    /**
     * Upload a local file privately.
     *
     * @throws InvalidDocumentException when the provider rejects the file contents
     * @return array{public_id: string, resource_type: string, format: string, bytes: int}
     */
    public function uploadPrivate(string $localPath, string $folder, string $publicId): array;

    public function delete(string $publicId, string $resourceType): void;

    /**
     * A time-limited link to view/download the file.
     *
     * @return array{url: string, expires_at: int} expires_at is a unix timestamp
     */
    public function temporaryUrl(string $publicId, string $resourceType, string $format, int $ttlSeconds): array;
}
