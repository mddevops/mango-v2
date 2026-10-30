<?php

namespace App\Enums;

/**
 * Limited palette of status labels. The colour is visual only and carries no meaning.
 */
enum OrderStatusColor: string
{
    case Gray = 'gray';
    case Dark = 'dark';
    case Green = 'green';
    case Violet = 'violet';
    case Yellow = 'yellow';
    case Red = 'red';

    public function label(): string
    {
        return match ($this) {
            self::Gray => 'Серый',
            self::Dark => 'Тёмный',
            self::Green => 'Зелёный',
            self::Violet => 'Фиолетовый',
            self::Yellow => 'Жёлтый',
            self::Red => 'Красный',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $color) => ['value' => $color->value, 'label' => $color->label()], self::cases());
    }
}
