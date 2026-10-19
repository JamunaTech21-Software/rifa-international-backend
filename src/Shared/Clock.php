<?php

declare(strict_types=1);

namespace App\Shared;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Current time in the app timezone (Asia/Dhaka). Use this instead of time()/date()
 * in services so tests can freeze the clock.
 */
final class Clock
{
    private ?DateTimeImmutable $frozen = null;

    private readonly DateTimeZone $timezone;

    public function __construct(string $timezone = 'Asia/Dhaka')
    {
        $this->timezone = new DateTimeZone($timezone);
    }

    public function now(): DateTimeImmutable
    {
        return $this->frozen ?? new DateTimeImmutable('now', $this->timezone);
    }

    public function today(): DateTimeImmutable
    {
        return $this->now()->setTime(0, 0);
    }

    public function timezone(): DateTimeZone
    {
        return $this->timezone;
    }

    public function freeze(DateTimeImmutable|string $at): void
    {
        $this->frozen = is_string($at) ? new DateTimeImmutable($at, $this->timezone) : $at->setTimezone($this->timezone);
    }

    public function unfreeze(): void
    {
        $this->frozen = null;
    }
}
