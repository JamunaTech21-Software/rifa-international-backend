<?php

declare(strict_types=1);

// Usage: php database/seed.php — runs every database/seeds/*.sql (all are idempotent).
// The first admin is created separately: php database/seeds/seed_admin.php

use App\Core\Database;
use App\Core\Migrator;

/** @var App\Core\App $app */
$app = require dirname(__DIR__) . '/bootstrap.php';
$migrator = new Migrator($app->container()->get(Database::class), __DIR__ . '/migrations');

try {
    foreach (glob(__DIR__ . '/seeds/*.sql') ?: [] as $file) {
        $migrator->runFile($file);
        echo 'Seeded: ' . basename($file) . PHP_EOL;
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Seeding failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
