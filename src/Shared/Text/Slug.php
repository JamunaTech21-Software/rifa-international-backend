<?php

declare(strict_types=1);

namespace App\Shared\Text;

/** URL slugs for public content (job listings, news). */
final class Slug
{
    /**
     * "Kitchen Helper (KL)" → "kitchen-helper-kl"; adds -2, -3 … while $exists() says it is taken.
     *
     * @param callable(string): bool $exists
     */
    public static function unique(string $text, callable $exists, string $fallback = 'item', int $maxLength = 70): string
    {
        $base = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($text)), '-');
        $base = rtrim(substr($base === '' ? $fallback : $base, 0, $maxLength), '-');
        $slug = $base;
        for ($n = 2; $exists($slug); $n++) {
            $slug = "{$base}-{$n}";
        }

        return $slug;
    }
}
