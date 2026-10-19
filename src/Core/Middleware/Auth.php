<?php

declare(strict_types=1);

namespace App\Core\Middleware;

use App\Core\Config;
use App\Core\Database;
use App\Core\Exceptions\UnauthenticatedException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Shared\Clock;

/**
 * Alias: auth. Requires a logged-in, active admin and enforces the idle timeout.
 * Sets request attribute 'admin' => ['id', 'name', 'email', 'role'].
 *
 * Session keys (written by the Auth module on login): admin_id, last_activity.
 */
final class Auth implements Middleware
{
    public function __construct(
        private readonly Session $session,
        private readonly Database $db,
        private readonly Clock $clock,
        private readonly Config $config,
    ) {
    }

    public function handle(Request $request, callable $next, string ...$params): Response
    {
        $adminId = $this->session->get('admin_id');
        if (!is_int($adminId)) {
            throw new UnauthenticatedException();
        }

        $now = $this->clock->now()->getTimestamp();
        $idleSeconds = (int) $this->config->get('security.session.idle_minutes', 60) * 60;
        $lastActivity = (int) $this->session->get('last_activity', 0);
        if ($now - $lastActivity > $idleSeconds) {
            $this->session->destroy();
            throw new UnauthenticatedException('Your session has expired. Please log in again.');
        }

        $admin = $this->db->fetchOne(
            'SELECT id, name, email, role FROM admins WHERE id = ? AND is_active = 1',
            [$adminId],
        );
        if ($admin === null) {
            $this->session->destroy();
            throw new UnauthenticatedException();
        }

        $this->session->set('last_activity', $now);
        $request->setAttribute('admin', $admin);

        return $next($request);
    }
}
