<?php

declare(strict_types=1);

// Usage: php bin/check-config.php — run on the server after every .env change.
// On staging/production: exit code 1 when there are errors (the API answers 503 until they are fixed).

use App\Core\Config;
use App\Core\ConfigCheck;
use App\Core\Database;

/** @var App\Core\App $app */
$app = require dirname(__DIR__) . '/bootstrap.php';
$config = $app->container()->get(Config::class);
$result = ConfigCheck::run($config);

try {
    $app->container()->get(Database::class)->fetchValue('SELECT 1');
} catch (Throwable $e) {
    $result['errors'][] = 'Database connection failed: ' . $e->getMessage();
}
$writable = ['storage/logs', 'storage/sessions'];
foreach ($writable as $dir) {
    if (!is_writable(dirname(__DIR__) . '/' . $dir)) {
        $result['errors'][] = "{$dir} is not writable by PHP.";
    }
}

$env = (string) $config->get('app.env');
echo "Environment: {$env}" . (ConfigCheck::isStrict($config) ? '' : ' (checks are only enforced on staging/production)') . PHP_EOL;
foreach ($result['errors'] as $line) {
    echo "  ERROR    {$line}" . PHP_EOL;
}
foreach ($result['warnings'] as $line) {
    echo "  WARNING  {$line}" . PHP_EOL;
}
if ($result['errors'] === [] && $result['warnings'] === []) {
    echo "  OK — configuration looks production-ready." . PHP_EOL;
}
exit(ConfigCheck::isStrict($config) && $result['errors'] !== [] ? 1 : 0);
