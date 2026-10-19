<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Daily JSON-lines log files in storage/logs (app-YYYY-MM-DD.log).
 * Never log personal data (NID, passport, full addresses) or secrets.
 */
final class Logger
{
    private const LEVELS = ['debug' => 0, 'info' => 1, 'warning' => 2, 'error' => 3];

    public function __construct(
        private readonly string $directory,
        private readonly string $minLevel = 'debug',
    ) {
    }

    /** @param array<string, mixed> $context */
    public function debug(string $message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function info(string $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function warning(string $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function error(string $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function log(string $level, string $message, array $context = []): void
    {
        if ((self::LEVELS[$level] ?? 3) < (self::LEVELS[$this->minLevel] ?? 0)) {
            return;
        }

        if (!is_dir($this->directory)) {
            @mkdir($this->directory, 0775, true);
        }

        $line = json_encode([
            'time' => date(DATE_ATOM),
            'level' => $level,
            'message' => $message,
            'context' => $context,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        @file_put_contents(
            $this->directory . '/app-' . date('Y-m-d') . '.log',
            $line . PHP_EOL,
            FILE_APPEND | LOCK_EX,
        );
    }
}
