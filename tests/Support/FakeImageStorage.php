<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Shared\Storage\ImageStorage;
use App\Shared\Storage\InvalidDocumentException;

/** In-memory ImageStorage for tests. */
final class FakeImageStorage implements ImageStorage
{
    /** @var array<string, int> public_id => bytes */
    public array $stored = [];

    /** @var list<string> */
    public array $deleted = [];

    public bool $rejectNext = false;

    public function uploadPublicImage(string $localPath, string $folder, string $publicId): array
    {
        if ($this->rejectNext) {
            $this->rejectNext = false;
            throw new InvalidDocumentException('Invalid image file');
        }
        $id = trim($folder, '/') . '/' . $publicId;
        $this->stored[$id] = (int) filesize($localPath);

        return ['public_id' => $id, 'width' => 800, 'height' => 600, 'bytes' => $this->stored[$id]];
    }

    public function deletePublicImage(string $publicId): void
    {
        unset($this->stored[$publicId]);
        $this->deleted[] = $publicId;
    }
}
