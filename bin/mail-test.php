<?php

declare(strict_types=1);

// Usage: php bin/mail-test.php --to=you@example.com
// Sends the "test" template with the current .env mail settings and reports the result.

use App\Core\Config;
use App\Shared\Clock;
use App\Shared\Mail\Mailer;

/** @var App\Core\App $app */
$app = require dirname(__DIR__) . '/bootstrap.php';
$container = $app->container();

$to = (string) (getopt('', ['to:'])['to'] ?? '');
if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Usage: php bin/mail-test.php --to=you@example.com\n");
    exit(1);
}

$config = $container->get(Config::class);
$ok = $container->get(Mailer::class)->send($to, 'RIFA email delivery test', 'test', [
    'environment' => (string) $config->get('app.env'),
    'sentAt' => $container->get(Clock::class)->now()->format('j F Y, g:i A (\G\M\TP)'),
]);

$driver = (string) $config->get('mail.driver');
echo $ok
    ? ($driver === 'log' ? "OK — MAIL_DRIVER=log, email written to storage/logs/mail/\n" : "OK — sent via SMTP to {$to}\n")
    : "FAILED — see storage/logs/app-" . date('Y-m-d') . ".log\n";
exit($ok ? 0 : 1);
