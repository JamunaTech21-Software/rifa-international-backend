<?php

declare(strict_types=1);

namespace App\Shared\Security;

use App\Core\Database;
use App\Shared\Clock;
use DateTimeImmutable;

/**
 * Fixed-window counters stored in the `rate_limits` table (works on shared
 * hosting without Redis). A key is e.g. "applications|203.0.113.7".
 */
final class RateLimiter
{
    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
    ) {
    }

    /** Count one attempt and return the attempts in the current window (including this one). */
    public function hit(string $key, int $windowSeconds): int
    {
        $now = $this->clock->now();
        $nowSql = $now->format('Y-m-d H:i:s');
        $cutoff = $now->modify("-{$windowSeconds} seconds")->format('Y-m-d H:i:s');

        // Both IF()s read the old window_start, so the order of the assignments is safe.
        $this->db->execute(
            'INSERT INTO rate_limits (rl_key, attempts, window_start) VALUES (?, 1, ?)
             ON DUPLICATE KEY UPDATE
                attempts = IF(window_start <= ?, 1, attempts + 1),
                window_start = IF(window_start <= ?, ?, window_start)',
            [$key, $nowSql, $cutoff, $cutoff, $nowSql],
        );

        return $this->attempts($key, $windowSeconds);
    }

    /** Attempts in the current window (0 when the window has expired). */
    public function attempts(string $key, int $windowSeconds): int
    {
        $row = $this->row($key);
        if ($row === null || $this->expired($row['window_start'], $windowSeconds)) {
            return 0;
        }

        return (int) $row['attempts'];
    }

    /** Seconds until the current window resets. */
    public function availableIn(string $key, int $windowSeconds): int
    {
        $row = $this->row($key);
        if ($row === null) {
            return 0;
        }
        $resetAt = $this->timestamp((string) $row['window_start']) + $windowSeconds;

        return max(0, $resetAt - $this->clock->now()->getTimestamp());
    }

    public function clear(string $key): void
    {
        $this->db->execute('DELETE FROM rate_limits WHERE rl_key = ?', [$key]);
    }

    /** Remove expired rows (run from a daily cron). */
    public function prune(int $olderThanSeconds = 86400): int
    {
        $cutoff = $this->clock->now()->modify("-{$olderThanSeconds} seconds")->format('Y-m-d H:i:s');

        return $this->db->execute('DELETE FROM rate_limits WHERE window_start < ?', [$cutoff]);
    }

    /** @return array{attempts: int, window_start: string}|null */
    private function row(string $key): ?array
    {
        /** @var array{attempts: int, window_start: string}|null */
        return $this->db->fetchOne('SELECT attempts, window_start FROM rate_limits WHERE rl_key = ?', [$key]);
    }

    private function expired(string $windowStart, int $windowSeconds): bool
    {
        return $this->timestamp($windowStart) + $windowSeconds <= $this->clock->now()->getTimestamp();
    }

    /** DB datetimes are stored in the app timezone. */
    private function timestamp(string $datetime): int
    {
        return (new DateTimeImmutable($datetime, $this->clock->timezone()))->getTimestamp();
    }
}
