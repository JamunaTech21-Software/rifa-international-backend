<?php

declare(strict_types=1);

namespace App\Shared\Storage;

/**
 * Public website images (job photos, news covers). Unlike applicant documents these are
 * delivered by plain Cloudinary URLs. Production: CloudinaryService. Tests: tests/Support/FakeImageStorage.
 */
interface ImageStorage
{
    /**
     * Upload a public image, resized on upload to fit 1920×1920.
     *
     * @throws InvalidDocumentException when Cloudinary rejects the file contents
     * @return array{public_id: string, width: int, height: int, bytes: int}
     */
    public function uploadPublicImage(string $localPath, string $folder, string $publicId): array;

    public function deletePublicImage(string $publicId): void;
}
