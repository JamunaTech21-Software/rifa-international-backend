<?php

declare(strict_types=1);

namespace App\Modules\Health\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Shared\Clock;
use Throwable;

/** GET /api/v1/health — used by deploy smoke tests and uptime checks. */
final class HealthController
{
    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
    ) {
    }

    public function show(Request $request): Response
    {
        try {
            $this->db->fetchValue('SELECT 1');
            $database = 'ok';
        } catch (Throwable) {
            $database = 'unavailable';
        }

        return Response::data([
            'status' => $database === 'ok' ? 'ok' : 'degraded',
            'database' => $database,
            'time' => $this->clock->now()->format(DATE_ATOM),
        ], $database === 'ok' ? 200 : 503);
    }
}
