<?php

namespace App\Support;

use App\Models\OrderDropReason;
use App\Models\OrderTrashReason;

/**
 * The two separate reason lists of orders. They share the shape but are never merged,
 * even when names coincide.
 */
enum OrderReasonType: string
{
    case Trash = 'trash';
    case Drop = 'drop';

    /**
     * @return class-string<OrderTrashReason|OrderDropReason>
     */
    public function model(): string
    {
        return match ($this) {
            self::Trash => OrderTrashReason::class,
            self::Drop => OrderDropReason::class,
        };
    }

    public function table(): string
    {
        return match ($this) {
            self::Trash => 'order_trash_reasons',
            self::Drop => 'order_drop_reasons',
        };
    }

    public function auditSubject(): string
    {
        return match ($this) {
            self::Trash => 'order_trash_reason',
            self::Drop => 'order_drop_reason',
        };
    }

    public function title(): string
    {
        return match ($this) {
            self::Trash => 'Причины корзины',
            self::Drop => 'Причины отказа / срыва',
        };
    }

    public function singular(): string
    {
        return match ($this) {
            self::Trash => 'Причина корзины',
            self::Drop => 'Причина отказа / срыва',
        };
    }

    public function nameTaken(): string
    {
        return $this->singular().' с таким названием уже существует.';
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $type) => ['value' => $type->value, 'label' => $type->title()], self::cases());
    }
}
