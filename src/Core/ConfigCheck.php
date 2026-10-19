<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Settings that must be right before the API runs on staging/production.
 *
 * errors   → the API refuses to serve requests (503 server_misconfigured) until fixed
 * warnings → the API runs, but something is not production-ready (shown by bin/check-config.php)
 *
 * Run `php bin/check-config.php` on the server after every .env change.
 */
final class ConfigCheck
{
    private const STRICT_ENVIRONMENTS = ['staging', 'production'];

    public static function isStrict(Config $config): bool
    {
        return in_array($config->get('app.env'), self::STRICT_ENVIRONMENTS, true);
    }

    /** @return array{errors: list<string>, warnings: list<string>} */
    public static function run(Config $config): array
    {
        $errors = [];
        $warnings = [];

        if ($config->get('app.debug') === true) {
            $errors[] = 'APP_DEBUG must be false (it would show error details to visitors).';
        }
        if ($config->get('security.session.secure') !== true) {
            $errors[] = 'SESSION_SECURE must be true (admin cookies only over HTTPS).';
        }
        if (strlen((string) $config->get('security.proxy.secret', '')) < 32) {
            $errors[] = 'TRUSTED_PROXY_SECRET must be set to a random string of at least 32 characters (see docs/vercel-proxy.md).';
        }
        foreach (['app.url' => 'APP_URL', 'app.admin_url' => 'ADMIN_URL'] as $key => $name) {
            if (!str_starts_with((string) $config->get($key, ''), 'https://')) {
                $errors[] = "{$name} must start with https://.";
            }
        }
        foreach (['cloud_name', 'api_key', 'api_secret'] as $key) {
            if ((string) $config->get("cloudinary.{$key}", '') === '') {
                $errors[] = 'CLOUDINARY_' . strtoupper($key) . ' is empty (document uploads would fail).';
            }
        }
        if ((string) $config->get('database.password', '') === '') {
            $errors[] = 'DB_PASSWORD is empty.';
        }

        if ($config->get('mail.driver') !== 'smtp') {
            $warnings[] = 'MAIL_DRIVER is not "smtp" — emails are only written to storage/logs/mail.';
        } elseif ((string) $config->get('mail.host', '') === '') {
            $warnings[] = 'MAIL_HOST is empty — emails will fail (submissions still work).';
        }
        if ($config->get('app.log_level') === 'debug') {
            $warnings[] = 'LOG_LEVEL=debug writes a lot of logs; use info or warning.';
        }

        return ['errors' => $errors, 'warnings' => $warnings];
    }
}
