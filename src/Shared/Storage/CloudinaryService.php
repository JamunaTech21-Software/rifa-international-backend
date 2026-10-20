<?php

declare(strict_types=1);

namespace App\Shared\Storage;

use App\Shared\Clock;
use Cloudinary\Api\Exception\BadRequest;
use Cloudinary\Cloudinary;
use RuntimeException;

/**
 * Applicant documents on Cloudinary with delivery type "authenticated":
 * they cannot be opened by URL guessing. Admins get a signed download link
 * from temporaryUrl() that expires after a few minutes.
 *
 * Website images (ImageStorage) use the normal public "upload" type.
 */
final class CloudinaryService implements DocumentStorage, ImageStorage
{
    private const TYPE = 'authenticated';

    private ?Cloudinary $client = null;

    /** @param array{cloud_name: string, api_key: string, api_secret: string} $config */
    public function __construct(
        private readonly array $config,
        private readonly Clock $clock,
    ) {
    }

    public function uploadPrivate(string $localPath, string $folder, string $publicId): array
    {
        try {
            $result = $this->client()->uploadApi()->upload($localPath, [
                'folder' => trim($folder, '/'),
                'public_id' => $publicId,
                'type' => self::TYPE,
                'resource_type' => 'auto',
                'overwrite' => false,
                'unique_filename' => false,
                'use_filename' => false,
            ]);
        } catch (BadRequest $e) {
            throw new InvalidDocumentException($e->getMessage(), 0, $e);
        }

        return [
            'public_id' => (string) $result['public_id'],
            'resource_type' => (string) $result['resource_type'],
            'format' => (string) ($result['format'] ?? ''),
            'bytes' => (int) $result['bytes'],
        ];
    }

    public function delete(string $publicId, string $resourceType): void
    {
        $this->client()->uploadApi()->destroy($publicId, [
            'type' => self::TYPE,
            'resource_type' => $resourceType,
            'invalidate' => true,
        ]);
    }

    public function temporaryUrl(string $publicId, string $resourceType, string $format, int $ttlSeconds): array
    {
        $expiresAt = $this->clock->now()->getTimestamp() + $ttlSeconds;

        $url = $this->client()->uploadApi()->privateDownloadUrl($publicId, $format, [
            'type' => self::TYPE,
            'resource_type' => $resourceType,
            'expires_at' => $expiresAt,
        ]);

        return ['url' => $url, 'expires_at' => $expiresAt];
    }

    public function uploadPublicImage(string $localPath, string $folder, string $publicId): array
    {
        try {
            $result = $this->client()->uploadApi()->upload($localPath, [
                'folder' => trim($folder, '/'),
                'public_id' => $publicId,
                'type' => 'upload',
                'resource_type' => 'image',
                'overwrite' => false,
                'unique_filename' => false,
                'use_filename' => false,
                'transformation' => [['width' => 1920, 'height' => 1920, 'crop' => 'limit']],
            ]);
        } catch (BadRequest $e) {
            throw new InvalidDocumentException($e->getMessage(), 0, $e);
        }

        return [
            'public_id' => (string) $result['public_id'],
            'width' => (int) $result['width'],
            'height' => (int) $result['height'],
            'bytes' => (int) $result['bytes'],
        ];
    }

    public function deletePublicImage(string $publicId): void
    {
        $this->client()->uploadApi()->destroy($publicId, [
            'type' => 'upload',
            'resource_type' => 'image',
            'invalidate' => true,
        ]);
    }

    private function client(): Cloudinary
    {
        if ($this->client === null) {
            foreach (['cloud_name', 'api_key', 'api_secret'] as $key) {
                if (($this->config[$key] ?? '') === '') {
                    throw new RuntimeException("Cloudinary is not configured: missing {$key}.");
                }
            }

            $this->client = new Cloudinary([
                'cloud' => [
                    'cloud_name' => $this->config['cloud_name'],
                    'api_key' => $this->config['api_key'],
                    'api_secret' => $this->config['api_secret'],
                ],
                'url' => ['secure' => true],
            ]);
        }

        return $this->client;
    }
}
