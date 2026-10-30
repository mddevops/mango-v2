<?php

namespace App\Support;

final class Timezones
{
    private const RUSSIAN = [
        'Europe/Kaliningrad' => 'Калининград (UTC+2)',
        'Europe/Moscow' => 'Москва, Санкт-Петербург, Казань (UTC+3)',
        'Europe/Samara' => 'Самара, Ижевск (UTC+4)',
        'Asia/Yekaterinburg' => 'Екатеринбург, Челябинск, Уфа, Пермь (UTC+5)',
        'Asia/Omsk' => 'Омск (UTC+6)',
        'Asia/Novosibirsk' => 'Новосибирск (UTC+7)',
        'Asia/Krasnoyarsk' => 'Красноярск (UTC+7)',
        'Asia/Irkutsk' => 'Иркутск (UTC+8)',
        'Asia/Yakutsk' => 'Якутск (UTC+9)',
        'Asia/Vladivostok' => 'Владивосток, Хабаровск (UTC+10)',
        'Asia/Magadan' => 'Магадан (UTC+11)',
        'Asia/Kamchatka' => 'Петропавловск-Камчатский (UTC+12)',
    ];

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::RUSSIAN as $value => $label) {
            $options[] = ['value' => $value, 'label' => $label];
        }

        return $options;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_keys(self::RUSSIAN);
    }
}
