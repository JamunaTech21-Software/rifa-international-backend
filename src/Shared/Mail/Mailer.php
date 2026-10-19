<?php

declare(strict_types=1);

namespace App\Shared\Mail;

use App\Core\Logger;
use PHPMailer\PHPMailer\Exception as MailException;
use PHPMailer\PHPMailer\PHPMailer;
use RuntimeException;
use Throwable;

/**
 * Sends HTML emails rendered from templates/emails/{template}.html.php inside layout.html.php.
 *
 *   $sent = $mailer->send('rahim@example.com', 'Application received', 'application-confirmation', [...]);
 *
 * - Never throws: failures are logged and send() returns false, so a mail problem
 *   never breaks the request that triggered it.
 * - Driver "log" (local/testing) writes each email to storage/logs/mail/*.html instead of sending.
 * - Templates get $data keys as variables, $e() for HTML escaping and $site (contact details).
 */
final class Mailer
{
    /** @var list<array{to: list<string>, subject: string, template: string, html: string, reply_to: ?string}> */
    private array $outbox = [];

    /**
     * @param array{driver: string, host: string, port: int, username: string, password: string, encryption: string,
     *              from_address: string, from_name: string, office_to: string, site?: array<string, string>} $config
     */
    public function __construct(
        private readonly array $config,
        private readonly string $templateDirectory,
        private readonly Logger $logger,
        private readonly ?string $logDirectory = null, // log driver output; default storage/logs/mail
    ) {
    }

    /**
     * @param string|list<string>  $to
     * @param array<string, mixed> $data
     */
    public function send(string|array $to, string $subject, string $template, array $data = [], ?string $replyTo = null): bool
    {
        $recipients = array_values(array_filter((array) $to));

        try {
            $html = $this->render($template, $data + ['subject' => $subject]);

            if (($this->config['driver'] ?? 'smtp') === 'log') {
                $this->writeToLog($recipients, $subject, $template, $html, $replyTo);

                return true;
            }

            $this->sendSmtp($recipients, $subject, $html, $replyTo);
            $this->logger->info('Email sent', ['template' => $template]);

            return true;
        } catch (Throwable $e) {
            // Do not log recipient addresses or content (personal data).
            $this->logger->error('Email failed', ['template' => $template, 'error' => $e->getMessage()]);

            return false;
        }
    }

    /** Office inbox that receives notifications (MAIL_OFFICE_TO). */
    public function officeAddress(): string
    {
        return (string) $this->config['office_to'];
    }

    /** @param array<string, mixed> $data */
    public function render(string $template, array $data = []): string
    {
        $content = $this->renderFile($template, $data);

        return $this->renderFile('layout', $data + ['content' => $content]);
    }

    /**
     * Emails "sent" with the log driver during this request (used by tests).
     *
     * @return list<array{to: list<string>, subject: string, template: string, html: string, reply_to: ?string}>
     */
    public function outbox(): array
    {
        return $this->outbox;
    }

    /** @param list<string> $to */
    private function sendSmtp(array $to, string $subject, string $html, ?string $replyTo): void
    {
        if (($this->config['host'] ?? '') === '') {
            throw new RuntimeException('SMTP is not configured (MAIL_HOST is empty).');
        }

        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $this->config['host'];
        $mail->Port = (int) $this->config['port'];
        $mail->SMTPAuth = ($this->config['username'] ?? '') !== '';
        $mail->Username = (string) $this->config['username'];
        $mail->Password = (string) $this->config['password'];
        $mail->SMTPSecure = $this->config['encryption'] === 'tls' ? PHPMailer::ENCRYPTION_STARTTLS : PHPMailer::ENCRYPTION_SMTPS;
        $mail->Timeout = 15;
        $mail->CharSet = PHPMailer::CHARSET_UTF8;

        $mail->setFrom($this->config['from_address'], $this->config['from_name']);
        foreach ($to as $address) {
            $mail->addAddress($address);
        }
        if ($replyTo !== null) {
            $mail->addReplyTo($replyTo);
        }

        $mail->Subject = $subject;
        $mail->msgHTML($html); // also builds the plain-text alternative

        try {
            $mail->send();
        } catch (MailException $e) {
            throw new RuntimeException($mail->ErrorInfo !== '' ? $mail->ErrorInfo : $e->getMessage(), 0, $e);
        }
    }

    /** @param list<string> $to */
    private function writeToLog(array $to, string $subject, string $template, string $html, ?string $replyTo): void
    {
        $this->outbox[] = ['to' => $to, 'subject' => $subject, 'template' => $template, 'html' => $html, 'reply_to' => $replyTo];

        $directory = $this->logDirectory ?? dirname($this->templateDirectory, 2) . '/storage/logs/mail';
        if (!is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }
        $file = $directory . '/' . date('Ymd-His') . '-' . $template . '-' . bin2hex(random_bytes(3)) . '.html';
        $header = '<!-- To: ' . htmlspecialchars(implode(', ', $to)) . ' | Subject: ' . htmlspecialchars($subject)
            . ($replyTo !== null ? ' | Reply-To: ' . htmlspecialchars($replyTo) : '') . " -->\n";
        @file_put_contents($file, $header . $html);

        $this->logger->info('Email written to log (MAIL_DRIVER=log)', ['template' => $template, 'file' => basename($file)]);
    }

    /** @param array<string, mixed> $data */
    private function renderFile(string $template, array $data): string
    {
        if (!preg_match('/^[a-z0-9-]+$/', $template)) {
            throw new RuntimeException("Invalid email template name [{$template}].");
        }
        $path = $this->templateDirectory . '/' . $template . '.html.php';
        if (!is_file($path)) {
            throw new RuntimeException("Email template not found: {$template}");
        }

        $e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $site = $this->config['site'] ?? [];

        return (static function (string $__path, array $__data, callable $e, array $site): string {
            extract($__data, EXTR_SKIP);
            ob_start();
            try {
                require $__path;

                return (string) ob_get_clean();
            } catch (Throwable $t) {
                ob_end_clean();
                throw $t;
            }
        })($path, $data, $e, $site);
    }
}
