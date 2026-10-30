<?php

namespace App\Enums;

enum DepartmentKind: string
{
    case CallCenter = 'call_center';
    case Showroom = 'showroom';
    case Credit = 'credit';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::CallCenter => 'Колл-центр',
            self::Showroom => 'Отдел салона',
            self::Credit => 'Кредитный отдел',
            self::Other => 'Другое',
        };
    }

    /**
     * Showroom departments always belong to a specific showroom; the other kinds may be shared.
     */
    public function requiresShowroom(): bool
    {
        return $this === self::Showroom;
    }

    /**
     * @return list<array{value: string, label: string, requiresShowroom: bool}>
     */
    public static function options(): array
    {
        return array_map(fn (self $kind) => [
            'value' => $kind->value,
            'label' => $kind->label(),
            'requiresShowroom' => $kind->requiresShowroom(),
        ], self::cases());
    }
}
