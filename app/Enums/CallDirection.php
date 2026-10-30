<?php

namespace App\Enums;

/**
 * Direction of a whole call. `call_direction` of the summary is final (0 internal,
 * 1 inbound, 2 outbound); before it the direction is inferred from the realtime legs.
 */
enum CallDirection: string
{
    case Inbound = 'inbound';

    case Outbound = 'outbound';

    case Internal = 'internal';

    public static function fromSummary(int $value): ?self
    {
        return match ($value) {
            0 => self::Internal,
            1 => self::Inbound,
            2 => self::Outbound,
            default => null,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Inbound => 'Входящий',
            self::Outbound => 'Исходящий',
            self::Internal => 'Внутренний',
        };
    }
}
