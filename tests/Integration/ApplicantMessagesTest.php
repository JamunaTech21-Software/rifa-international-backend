<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Config;
use App\Core\Logger;
use App\Shared\Mail\Mailer;
use App\Shared\Security\CsrfTokenManager;

/** P5-01: admin → applicant emails from the application page (email only). */
final class ApplicantMessagesTest extends IntegrationTestCase
{
    private const BASE = '/api/v1/admin/applications';

    private int $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock()->freeze('2026-10-20 12:00:00');
        $this->admin = $this->createAdmin('staff');
        $this->db->execute('UPDATE admins SET name = ? WHERE id = ?', ['Nusrat Jahan', $this->admin]);
        $this->loginAs($this->admin);
        $this->app->container()->get(CsrfTokenManager::class)->token();
    }

    /** @return array<string, string> */
    private function csrf(): array
    {
        return ['X-CSRF-Token' => (string) $this->session()->get('csrf_token')];
    }

    private function application(?string $email = 'karim@example.com', string $reference = 'RIFA-20261015-00042'): int
    {
        return $this->db->insert('applications', [
            'reference_no' => $reference,
            'full_name' => 'Md. Karim',
            'father_name' => 'Father',
            'date_of_birth' => '1995-01-01',
            'age_at_submission' => 31,
            'gender' => 'male',
            'mobile' => '+8801712345678',
            'email' => $email,
            'present_address' => 'Dhaka',
            'job_category_id' => (int) $this->db->fetchValue('SELECT id FROM job_categories WHERE slug = ?', ['construction']),
            'passport_status' => 'have_passport',
            'declaration_accepted_at' => '2026-10-15 09:30:00',
            'ip_address' => '127.0.0.1',
            'created_at' => '2026-10-15 09:30:00',
        ]);
    }

    public function test_templates_are_filled_with_the_applicants_details(): void
    {
        $id = $this->application();

        $data = $this->request('GET', self::BASE . "/{$id}/message-templates")->decodedJson()['data'];

        $this->assertTrue($data['can_email']);
        $this->assertSame(['shortlisted', 'interview', 'medical', 'documents_required', 'custom'], array_column($data['templates'], 'key'));
        $shortlisted = $data['templates'][0];
        $this->assertSame('Your application RIFA-20261015-00042 has been shortlisted', $shortlisted['subject']);
        $this->assertStringStartsWith('Dear Md. Karim,', $shortlisted['body']);
        $this->assertStringContainsString('for a Construction job in Malaysia', $shortlisted['body']);
        $this->assertStringContainsString('Date: [DATE]', $data['templates'][1]['body']);

        $this->assertFalse($this->request('GET', self::BASE . '/' . $this->application(null, 'RIFA-20261015-00043') . '/message-templates')->decodedJson()['data']['can_email']);
    }

    public function test_send_emails_the_applicant_and_logs_it(): void
    {
        $id = $this->application();

        $response = $this->request('POST', self::BASE . "/{$id}/messages", [
            'template' => 'interview',
            'subject' => "Interview invitation\r\nBcc: evil@example.com",
            'body' => "Dear Md. Karim,\r\n\r\nDate: 25 October 2026\nTime: 10:00 AM\nPlace: <b>Banani office</b>",
        ], $this->csrf());

        $this->assertSame(201, $response->status(), $response->body());
        $message = $response->decodedJson()['data'];
        $this->assertSame('sent', $message['delivery_status']);
        $this->assertSame('Interview invitation Bcc: evil@example.com', $message['subject'], 'newlines removed from the subject');
        $this->assertSame(['id' => $this->admin, 'name' => 'Nusrat Jahan'], $message['admin']);
        $this->assertSame('2026-10-20T12:00:00+06:00', $message['sent_at']);

        $outbox = $this->mailer()->outbox();
        $this->assertCount(1, $outbox);
        $this->assertSame(['karim@example.com'], $outbox[0]['to']);
        $this->assertSame('info@rifaint.com', $outbox[0]['reply_to']);
        $this->assertSame('Interview invitation Bcc: evil@example.com — Rifa International', $outbox[0]['subject']);
        $this->assertStringContainsString('Date: 25 October 2026<br />', $outbox[0]['html']);
        $this->assertStringContainsString('&lt;b&gt;Banani office&lt;/b&gt;', $outbox[0]['html']);
        $this->assertStringContainsString('Nusrat Jahan', $outbox[0]['html']);
        $this->assertStringContainsString('RIFA-20261015-00042', $outbox[0]['html']);

        $log = $this->request('GET', self::BASE . "/{$id}/messages")->decodedJson()['data'];
        $this->assertSame([$message['id']], array_column($log, 'id'));
        $this->assertSame(1, (int) $this->db->fetchValue("SELECT COUNT(*) FROM audit_logs WHERE action = 'applications.message_sent'"));
    }

    public function test_unfilled_blanks_and_missing_fields_are_refused(): void
    {
        $id = $this->application();

        $blank = $this->request('POST', self::BASE . "/{$id}/messages", [
            'template' => 'medical',
            'subject' => 'Medical check-up — RIFA-20261015-00042',
            'body' => "Dear Md. Karim,\n\nDate: 1 November\nMedical centre: [MEDICAL CENTRE]",
        ], $this->csrf());
        $this->assertSame(['body' => 'Replace [MEDICAL CENTRE] with the real details before sending.'], $blank->decodedJson()['error']['fields']);

        $empty = $this->request('POST', self::BASE . "/{$id}/messages", ['template' => 'sms'], $this->csrf());
        $this->assertSame([
            'template' => 'Please choose a valid template.',
            'subject' => 'Please enter a subject.',
            'body' => 'Please write the message.',
        ], $empty->decodedJson()['error']['fields']);

        $this->assertSame([], $this->mailer()->outbox());
        $this->assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM application_messages'));
    }

    public function test_applicant_without_email_cannot_be_emailed(): void
    {
        $id = $this->application(null);

        $response = $this->request('POST', self::BASE . "/{$id}/messages", ['template' => 'custom', 'subject' => 'Hello there', 'body' => 'Dear Karim, hello.'], $this->csrf());

        $this->assertSame(['email' => 'This applicant did not give an email address. Please call them instead.'], $response->decodedJson()['error']['fields']);
    }

    public function test_failed_email_is_logged_as_failed(): void
    {
        $id = $this->application();
        $config = $this->app->container()->get(Config::class);
        $this->app->container()->instance(Mailer::class, new Mailer(
            ['driver' => 'smtp', 'host' => ''] + $config->get('mail'),
            dirname(__DIR__, 2) . '/templates/emails',
            $this->app->container()->get(Logger::class),
        ));

        $message = $this->request('POST', self::BASE . "/{$id}/messages", ['template' => 'custom', 'subject' => 'Hello there', 'body' => 'Dear Karim, hello.'], $this->csrf())->decodedJson()['data'];

        $this->assertSame('failed', $message['delivery_status']);
        $this->assertNull($message['sent_at']);
        $this->assertSame('Email could not be sent. Check the mail settings and try again.', $message['error']);
    }

    public function test_deleted_or_unknown_application_is_404_and_login_is_required(): void
    {
        $id = $this->application();
        $this->db->execute('UPDATE applications SET deleted_at = ? WHERE id = ?', ['2026-10-19 10:00:00', $id]);
        $this->assertSame(404, $this->request('GET', self::BASE . "/{$id}/message-templates")->status());
        $this->assertSame(404, $this->request('GET', self::BASE . '/999999/messages')->status());

        $this->session()->forget('admin_id');
        $this->assertSame(401, $this->request('GET', self::BASE . "/{$id}/messages")->status());
    }
}
