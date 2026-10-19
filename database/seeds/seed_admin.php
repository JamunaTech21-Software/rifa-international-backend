<?php

declare(strict_types=1);

/*
 * Create (or reset) an admin account.
 *
 *   php database/seeds/seed_admin.php --name="Md. Samin Israk" --email=you@example.com [--role=super_admin] [--reset]
 *
 * The password is read from the SEED_ADMIN_PASSWORD environment variable or,
 * if that is empty, prompted for (hidden). It is never printed.
 * --reset updates the password/role/name of an existing admin with that email.
 */

use App\Core\Config;
use App\Core\Database;
use App\Shared\Security\Password;

/** @var App\Core\App $app */
$app = require dirname(__DIR__, 2) . '/bootstrap.php';
$db = $app->container()->get(Database::class);
$minLength = (int) $app->container()->get(Config::class)->get('security.password_min_length', 10);

$options = getopt('', ['name:', 'email:', 'role::', 'reset']);
$name = trim((string) ($options['name'] ?? ''));
$email = strtolower(trim((string) ($options['email'] ?? '')));
$role = (string) ($options['role'] ?? 'super_admin');
$reset = array_key_exists('reset', $options);

$fail = static function (string $message): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
};

if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $fail('Usage: php database/seeds/seed_admin.php --name="Full Name" --email=admin@example.com [--role=super_admin|staff] [--reset]');
}
if (!in_array($role, ['super_admin', 'staff'], true)) {
    $fail('Role must be super_admin or staff.');
}

$password = (string) getenv('SEED_ADMIN_PASSWORD');
if ($password === '') {
    echo 'Password: ';
    system('stty -echo');
    $password = trim((string) fgets(STDIN));
    system('stty echo');
    echo PHP_EOL;
}
if (mb_strlen($password) < $minLength) {
    $fail("Password must be at least {$minLength} characters.");
}

$existing = $db->fetchOne('SELECT id FROM admins WHERE email = ?', [$email]);

if ($existing !== null && !$reset) {
    $fail("An admin with {$email} already exists. Use --reset to change the password.");
}

if ($existing !== null) {
    $db->execute(
        'UPDATE admins SET name = ?, role = ?, password_hash = ?, is_active = 1, failed_logins = 0, locked_until = NULL WHERE id = ?',
        [$name, $role, Password::hash($password), $existing['id']],
    );
    echo "Updated admin {$email} ({$role})." . PHP_EOL;
} else {
    $db->insert('admins', [
        'name' => $name,
        'email' => $email,
        'password_hash' => Password::hash($password),
        'role' => $role,
    ]);
    echo "Created admin {$email} ({$role})." . PHP_EOL;
}
