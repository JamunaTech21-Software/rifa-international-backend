<?php

declare(strict_types=1);

// Usage: php database/migrate.php            run pending migrations
//        php database/migrate.php --fresh    drop all tables, then migrate (local/testing only)

use App\Core\Config;
use App\Core\Database;
use App\Core\Migrator;

/** @var App\Core\App $app */
$app = require dirname(__DIR__) . '/bootstrap.php';
$container = $app->container();

$migrator = new Migrator(
    $container->get(Database::class),
    __DIR__ . '/migrations',
    static fn (string $line) => print($line . PHP_EOL),
);

try {
    in_array('--fresh', $argv, true)
        ? $migrator->fresh((string) $container->get(Config::class)->get('app.env'))
        : $migrator->migrate();
} catch (Throwable $e) {
    fwrite(STDERR, 'Migration failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
