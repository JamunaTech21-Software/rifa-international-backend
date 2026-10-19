<?php

declare(strict_types=1);

/*
 * Entry point shared by every runtime:
 *   - public/index.php (local dev) and public_html/api/index.php (cPanel)
 *   - database/*.php CLI scripts
 * Returns the configured App\Core\App.
 */

require __DIR__ . '/vendor/autoload.php';

return App\Core\Kernel::create(__DIR__);
