<?php

namespace App\Enums;

/**
 * A status the system itself sets. It is found by this purpose, never by name or id,
 * stays active and cannot be the initial status.
 */
enum OrderStatusPurpose: string
{
    case Transferred = 'transferred';

    public function label(): string
    {
        return match ($this) {
            self::Transferred => 'Ставится при передаче в другой салон',
        };
    }
}
