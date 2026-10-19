<?php

declare(strict_types=1);

namespace App\Modules\Messages\Repositories;

use App\Core\Database;

final class MessageRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): int
    {
        return $this->db->insert('contact_messages', $data);
    }
}
