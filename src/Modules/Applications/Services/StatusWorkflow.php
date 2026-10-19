<?php

declare(strict_types=1);

namespace App\Modules\Applications\Services;

/**
 * Allowed application status changes (plan.md §4.2).
 *
 *   received → under_review → shortlisted → interview → medical → visa_processing → departed
 *
 * - Forward moves may skip steps; backward moves are not allowed.
 * - on_hold and rejected can be set from any open status.
 * - on_hold can go back to any open status (or rejected).
 * - departed and rejected are final; only a super admin can reopen them to an open status.
 */
final class StatusWorkflow
{
    public const PIPELINE = ['received', 'under_review', 'shortlisted', 'interview', 'medical', 'visa_processing', 'departed'];

    public const ON_HOLD = 'on_hold';

    public const REJECTED = 'rejected';

    public const FINAL = ['departed', self::REJECTED];

    /** Open = not final and not on hold. */
    private const OPEN = ['received', 'under_review', 'shortlisted', 'interview', 'medical', 'visa_processing'];

    /** @return list<string> */
    public function allowedNext(string $from, bool $isSuperAdmin): array
    {
        if (in_array($from, self::FINAL, true)) {
            return $isSuperAdmin ? self::OPEN : [];
        }

        if ($from === self::ON_HOLD) {
            return [...self::OPEN, self::REJECTED];
        }

        $position = array_search($from, self::PIPELINE, true);
        if ($position === false) {
            return [];
        }

        return [...array_slice(self::PIPELINE, $position + 1), self::ON_HOLD, self::REJECTED];
    }

    public function canMove(string $from, string $to, bool $isSuperAdmin): bool
    {
        return in_array($to, $this->allowedNext($from, $isSuperAdmin), true);
    }
}
