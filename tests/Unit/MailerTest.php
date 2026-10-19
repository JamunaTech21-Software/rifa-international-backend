<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Logger;
use App\Shared\Mail\Mailer;
use PHPUnit\Framework\TestCase;

final class MailerTest extends TestCase
{
    private string $logDir;

    protected function setUp(): void
    {
        $this->logDir = sys_get_temp_dir() . '/rifa-mail-test-' . uniqid();
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->logDir . '/*') ?: []);
        @rmdir($this->logDir);
    }

    /** @param array<string, mixed> $overrides */
    private function mailer(array $overrides = []): Mailer
    {
        return new Mailer($overrides + [
            'driver' => 'log', 'host' => '', 'port' => 465, 'username' => '', 'password' => '', 'encryption' => 'ssl',
            'from_address' => 'info@rifaint.com', 'from_name' => 'Rifa International', 'office_to' => 'office@example.com',
            'site' => ['name' => 'Rifa International', 'licence' => 'RL-693', 'phone' => '+880 1990-359053'],
        ], dirname(__DIR__, 2) . '/templates/emails', new Logger($this->logDir), $this->logDir);
    }

    public function test_renders_template_inside_layout_and_escapes_values(): void
    {
        $html = $this->mailer()->render('test', [
            'subject' => 'Hi',
            'environment' => '<script>alert(1)</script>',
            'sentAt' => 'now',
        ]);

        $this->assertStringContainsString('Email delivery test', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('<script>alert(1)', $html);
        $this->assertStringContainsString('+880 1990-359053', $html); // layout footer
    }

    public function test_log_driver_records_email_and_returns_true(): void
    {
        $mailer = $this->mailer();

        $ok = $mailer->send('rahim@example.com', 'Test', 'test', ['environment' => 'testing', 'sentAt' => 'now'], 'reply@example.com');

        $this->assertTrue($ok);
        $this->assertCount(1, $mailer->outbox());
        $this->assertSame(['rahim@example.com'], $mailer->outbox()[0]['to']);
        $this->assertSame('reply@example.com', $mailer->outbox()[0]['reply_to']);
        $this->assertSame('office@example.com', $mailer->officeAddress());
    }

    public function test_failures_return_false_and_log_without_recipient(): void
    {
        $mailer = $this->mailer(['driver' => 'smtp', 'host' => '']);

        $this->assertFalse($mailer->send('secret-person@example.com', 'Test', 'test', ['environment' => 'x', 'sentAt' => 'y']));
        $this->assertFalse($mailer->send('a@example.com', 'Test', 'missing-template'));

        $log = (string) file_get_contents(glob($this->logDir . '/*.log')[0]);
        $this->assertStringContainsString('SMTP is not configured', $log);
        $this->assertStringContainsString('Email template not found', $log);
        $this->assertStringNotContainsString('secret-person@example.com', $log);
    }

    public function test_rejects_path_traversal_in_template_name(): void
    {
        $this->assertFalse($this->mailer()->send('a@example.com', 'x', '../layout'));
    }
}
