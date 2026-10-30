<?php

namespace App\Enums;

/**
 * The minimal state an unassigned order needs for a safe retry of callback distribution.
 * Orders that were never handed to the process, or got a responsible, have no state.
 */
enum DistributionState: string
{
    /** Waiting for a candidate; retried by `orders:distribute`. */
    case Pending = 'pending';

    /** Its business day ended without a candidate: left for a manual decision. */
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Ожидает распределения',
            self::Expired => 'Нужно ручное решение',
        };
    }
}
