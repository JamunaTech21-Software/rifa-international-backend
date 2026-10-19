<?php

declare(strict_types=1);

if (!function_exists('env')) {
    /**
     * Read an environment variable. The literals "true", "false" and "null"
     * (any case) are cast to their PHP values.
     */
    function env(string $key, mixed $default = null): mixed
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        if ($value === false || $value === null) {
            return $default;
        }

        return match (strtolower((string) $value)) {
            'true' => true,
            'false' => false,
            'null' => null,
            default => $value,
        };
    }
}
