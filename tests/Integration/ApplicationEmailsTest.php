<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Http\UploadedFile;
use App\Core\Request;
use App\Core\Response;
use Tests\Support\Fixtures;

final class ApplicationEmailsTest extends IntegrationTestCase
{
    private ?string $tmp = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock()->freeze('2026-10-15 10:32:00');
    }

    protected function tearDown(): void
    {
        if ($this->tmp !== null && is_file($this->tmp)) {
            unlink($this->tmp);
        }
        parent::tearDown();
    }

    /** @param array<string, string> $overrides */
    private function submit(array $overrides = []): Response
    {
        $this->tmp = Fixtures::tempFile(Fixtures::pdf());

        return $this->app->handle(new Request('POST', '/api/v1/applications', [], $overrides + [
            'full_name' => 'Md. Rahim Uddin',
            'father_name' => 'Md. Karim Uddin',
            'date_of_birth' => '1975-05-14',
            'gender' => 'male',
            'mobile' => '01712345678',
            'email' => 'rahim@example.com',
            'present_address' => 'Village X, Cumilla',
            'job_category' => 'hospitality',
            'passport_status' => 'have_passport',
            'declaration' => '1',
        ], [
            'passport_copy' => new UploadedFile('passport.pdf', $this->tmp, (int) filesize($this->tmp), UPLOAD_ERR_OK, true),
        ], [], ['REMOTE_ADDR' => '203.0.113.20']));
    }

    public function test_applicant_confirmation_and_office_notification(): void
    {
        $response = $this->submit(['full_name' => 'Md. Rahim Uddin']);
        $this->assertSame(201, $response->status(), $response->body());
        $this->assertTrue($response->decodedJson()['data']['confirmation_email_sent']);

        $outbox = $this->mailer()->outbox();
        $this->assertCount(2, $outbox);

        [$confirmation, $office] = $outbox;
        $this->assertSame(['rahim@example.com'], $confirmation['to']);
        $this->assertSame('Application received — RIFA-20261015-00001', $confirmation['subject']);
        $this->assertStringContainsString('RIFA-20261015-00001', $confirmation['html']);
        $this->assertStringContainsString('15 October 2026, 10:32 AM (GMT+6)', $confirmation['html']);
        $this->assertStringContainsString('Hospitality', $confirmation['html']);
        $this->assertStringContainsString('Document Review', $confirmation['html']);

        $this->assertSame(['info@rifaint.com'], $office['to']);
        $this->assertSame('rahim@example.com', $office['reply_to']);
        $this->assertSame('New application RIFA-20261015-00001 — Hospitality', $office['subject']);
        $this->assertStringContainsString('+8801712345678', $office['html']);
        $this->assertStringContainsString('Applicant is older than 45', $office['html']);
        $id = $this->db->fetchValue('SELECT id FROM applications');
        $this->assertStringContainsString("/admin/applications/{$id}", $office['html']);
    }

    public function test_without_email_only_the_office_is_notified(): void
    {
        $response = $this->submit(['email' => '']);

        $this->assertFalse($response->decodedJson()['data']['confirmation_email_sent']);
        $outbox = $this->mailer()->outbox();
        $this->assertCount(1, $outbox);
        $this->assertSame(['info@rifaint.com'], $outbox[0]['to']);
        $this->assertNull($outbox[0]['reply_to']);
    }

    public function test_applicant_data_is_html_escaped_in_emails(): void
    {
        $this->submit(['full_name' => "Md. Rahim O'Neil"]);

        foreach ($this->mailer()->outbox() as $mail) {
            $this->assertStringContainsString('Md. Rahim O&#039;Neil', $mail['html']);
        }
    }
}
