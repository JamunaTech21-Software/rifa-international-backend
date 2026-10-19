<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Config;
use App\Shared\Clock;
use App\Shared\Storage\CloudinaryService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\Fixtures;

/**
 * Talks to the real Cloudinary account from .env (folder rifa/test). Skipped when
 * credentials are missing. Exclude with: composer test -- --exclude-group cloudinary
 */
#[Group('cloudinary')]
final class CloudinaryServiceTest extends TestCase
{
    private CloudinaryService $storage;

    private string $cloudName = '';

    /** @var list<array{0: string, 1: string}> */
    private array $uploaded = [];

    protected function setUp(): void
    {
        $app = \App\Core\Kernel::create(dirname(__DIR__, 2));
        $config = $app->container()->get(Config::class)->get('cloudinary');
        if (($config['api_secret'] ?? '') === '') {
            $this->markTestSkipped('Cloudinary credentials not configured.');
        }
        $this->cloudName = $config['cloud_name'];
        $this->storage = new CloudinaryService($config, new Clock());
    }

    protected function tearDown(): void
    {
        foreach ($this->uploaded as [$publicId, $type]) {
            $this->storage->delete($publicId, $type);
        }
    }


    public function test_png_and_pdf_upload_privately_and_open_via_expiring_link(): void
    {
        $cases = [
            ['png', Fixtures::png(), 'image/png'],
            ['pdf', Fixtures::pdf(), 'application/pdf'],
        ];

        foreach ($cases as [$ext, $contents, $mime]) {
            $path = Fixtures::tempFile($contents, '.' . $ext);
            $id = 'test-' . bin2hex(random_bytes(4));

            $result = $this->storage->uploadPrivate($path, 'rifa/test', $id);
            unlink($path);
            $this->uploaded[] = [$result['public_id'], $result['resource_type']];

            $this->assertSame("rifa/test/{$id}", $result['public_id']);
            $this->assertSame($ext, $result['format']);
            $this->assertSame(strlen($contents), $result['bytes']);

            // The plain delivery URL must not work for an authenticated asset.
            $public = "https://res.cloudinary.com/{$this->cloudName}/{$result['resource_type']}/upload/{$result['public_id']}.{$ext}";
            $this->assertNotSame(200, $this->httpStatus($public), "{$ext} should not be publicly reachable");

            $link = $this->storage->temporaryUrl($result['public_id'], $result['resource_type'], $result['format'], 300);
            $this->assertGreaterThan(time(), $link['expires_at']);
            [$status, $body, $type] = $this->fetch($link['url']);
            $this->assertSame(200, $status, "{$ext} temporary URL should download");
            $this->assertSame($contents, $body);
            $this->assertStringContainsString($mime, $type);
        }
    }

    private function httpStatus(string $url): int
    {
        return $this->fetch($url)[0];
    }

    /** @return array{0: int, 1: string, 2: string} */
    private function fetch(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 30]);
        $body = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);

        return [$status, $body, $type];
    }
}
