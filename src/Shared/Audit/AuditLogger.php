<?php

declare(strict_types=1);

namespace App\Shared\Audit;

use App\Core\Database;
use App\Core\Logger;
use App\Shared\Clock;
use Throwable;

/**
 * Records admin actions in audit_logs (login, status change, document view, export, delete, user changes).
 * Never throws — an audit failure is logged but does not break the admin action.
 * Do not put personal data (NID, passport, phone) in $meta.
 */
final class AuditLogger
{
    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
        private readonly Logger $logger,
    ) {
    }

    /** @param array<string, mixed> $meta */
    public function log(?int $adminId, string $action, string $entityType, ?int $entityId, string $ip, array $meta = []): void
    {
        try {
            $this->db->insert('audit_logs', [
                'admin_id' => $adminId,
                'action' => $action,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'meta' => $meta === [] ? null : json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'ip_address' => mb_substr($ip, 0, 45),
                'created_at' => $this->clock->now()->format('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $e) {
            $this->logger->error('Audit log write failed', ['action' => $action, 'error' => $e->getMessage()]);
        }
    }
}
