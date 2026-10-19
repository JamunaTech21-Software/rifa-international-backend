<?php

declare(strict_types=1);

namespace Tests\Integration;

final class MessageSubmitTest extends IntegrationTestCase
{
    private const URL = '/api/v1/messages';

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock()->freeze('2026-10-15 10:32:00');
    }

    /** @return array<string, string> */
    private function valid(array $overrides = []): array
    {
        return $overrides + [
            'full_name' => '  Md.  Rahim Uddin ',
            'email' => 'Rahim@Example.com',
            'phone' => '+880 1712-345678',
            'subject' => 'employer_partnership',
            'message' => "Hello,\r\nWe need 20 workers <b>urgently</b>.",
            'website' => '',
        ];
    }

    public function test_stores_message_and_notifies_office(): void
    {
        $response = $this->request('POST', self::URL, $this->valid());

        $this->assertSame(201, $response->status(), $response->body());
        $data = $response->decodedJson()['data'];
        $this->assertSame('2026-10-15T10:32:00+06:00', $data['received_at']);

        $row = $this->db->fetchOne('SELECT * FROM contact_messages WHERE id = ?', [$data['id']]);
        $this->assertSame('Md. Rahim Uddin', $row['full_name']);
        $this->assertSame('rahim@example.com', $row['email']);
        $this->assertSame('+880 1712-345678', $row['phone']);
        $this->assertSame('employer_partnership', $row['subject']);
        $this->assertSame("Hello,\nWe need 20 workers <b>urgently</b>.", $row['message']);
        $this->assertSame('new', $row['status']);
        $this->assertNull($row['read_at']);
        $this->assertSame('2026-10-15 10:32:00', $row['created_at']);

        $outbox = $this->mailer()->outbox();
        $this->assertCount(1, $outbox);
        $this->assertSame(['info@rifaint.com'], $outbox[0]['to']);
        $this->assertSame('rahim@example.com', $outbox[0]['reply_to']);
        $this->assertSame('New message: Employer Partnership — Md. Rahim Uddin', $outbox[0]['subject']);
        $this->assertStringContainsString('Hello,<br />', $outbox[0]['html']);
        $this->assertStringContainsString('&lt;b&gt;urgently&lt;/b&gt;', $outbox[0]['html']);
        $this->assertStringContainsString("/admin/messages/{$data['id']}", $outbox[0]['html']);
    }

    public function test_empty_submission_returns_every_required_message(): void
    {
        $response = $this->request('POST', self::URL, ['website' => '']);

        $this->assertSame(422, $response->status());
        $this->assertSame([
            'full_name' => 'Please enter your name.',
            'email' => 'Please enter your email address.',
            'phone' => 'Please enter your phone number.',
            'subject' => 'Please select a subject.',
            'message' => 'Please write your message.',
        ], $response->decodedJson()['error']['fields']);
        $this->assertSame([], $this->mailer()->outbox());
    }

    public function test_format_errors(): void
    {
        $response = $this->request('POST', self::URL, $this->valid([
            'full_name' => 'R',
            'email' => 'rahim@',
            'phone' => 'call me',
            'subject' => 'jobs',
            'message' => 'Too short',
        ]));

        $this->assertSame([
            'full_name' => 'Name must be 2–100 characters.',
            'email' => 'Enter a valid email address.',
            'phone' => 'Enter a valid phone number.',
            'subject' => 'Please select a valid subject.',
            'message' => 'Message must be 10–5000 characters.',
        ], $response->decodedJson()['error']['fields']);
    }

    public function test_phone_formats(): void
    {
        foreach (['01712345678', '+8801712345678', '+60 12-345 6789'] as $i => $phone) {
            $this->assertSame(201, $this->request('POST', self::URL, $this->valid(['phone' => $phone]), [], [], "10.0.0.{$i}")->status(), $phone);
        }
        foreach (['12345', '01712345678901234567890', '++8801712345678'] as $phone) {
            $this->assertSame(422, $this->request('POST', self::URL, $this->valid(['phone' => $phone]))->status(), $phone);
        }
    }

    public function test_honeypot_pretends_success_and_stores_nothing(): void
    {
        $response = $this->request('POST', self::URL, $this->valid(['website' => 'spam']));

        $this->assertSame(201, $response->status());
        $this->assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM contact_messages'));
        $this->assertSame([], $this->mailer()->outbox());
    }

    public function test_rate_limit_after_five_successful_messages(): void
    {
        $this->request('POST', self::URL, []); // 422 does not count
        for ($i = 0; $i < 5; $i++) {
            $this->assertSame(201, $this->request('POST', self::URL, $this->valid())->status());
        }

        $blocked = $this->request('POST', self::URL, $this->valid());

        $this->assertSame(429, $blocked->status());
        $this->assertSame(201, $this->request('POST', self::URL, $this->valid(), [], [], '198.51.100.7')->status());
    }

    public function test_mail_failure_does_not_fail_the_request(): void
    {
        $config = $this->app->container()->get(\App\Core\Config::class);
        $this->app->container()->instance(\App\Shared\Mail\Mailer::class, new \App\Shared\Mail\Mailer(
            ['driver' => 'smtp', 'host' => ''] + $config->get('mail'),
            dirname(__DIR__, 2) . '/templates/emails',
            $this->app->container()->get(\App\Core\Logger::class),
        ));

        $response = $this->request('POST', self::URL, $this->valid());

        $this->assertSame(201, $response->status());
        $this->assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM contact_messages'));
    }
}
