<?php

declare(strict_types=1);

// Daily housekeeping (cPanel cron: 30 3 * * * php ~/rifa_backend/bin/cleanup.php):
//   - expired rate-limit counters
//   - used/expired password reset tokens older than 7 days
//   - log files older than 30 days, local mail previews older than 7 days

use App\Core\Database;
use App\Shared\Clock;
use App\Shared\Security\RateLimiter;

/** @var App\Core\App $app */
$app = require dirname(__DIR__) . '/bootstrap.php';
$c = $app->container();
$now = $c->get(Clock::class)->now();
$base = dirname(__DIR__);

$rate = $c->get(RateLimiter::class)->prune(86400);
$resets = $c->get(Database::class)->execute(
    'DELETE FROM password_resets WHERE (used_at IS NOT NULL OR expires_at < ?) AND created_at < ?',
    [$now->format('Y-m-d H:i:s'), $now->modify('-7 days')->format('Y-m-d H:i:s')],
);

$deleteOlderThan = static function (string $pattern, int $days) use ($now): int {
    $count = 0;
    foreach (glob($pattern) ?: [] as $file) {
        if (is_file($file) && filemtime($file) < $now->modify("-{$days} days")->getTimestamp()) {
            $count += (int) unlink($file);
        }
    }

    return $count;
};
$logs = $deleteOlderThan($base . '/storage/logs/*.log', 30);
$mails = $deleteOlderThan($base . '/storage/logs/mail/*.html', 7);

echo "Removed: {$rate} rate-limit rows, {$resets} reset tokens, {$logs} log files, {$mails} mail previews." . PHP_EOL;
