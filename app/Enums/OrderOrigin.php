<?php

namespace App\Enums;

/**
 * How an order got into the system. It is not the contact channel: a manually created
 * or transferred order still records whether the client called or asked for a callback.
 */
enum OrderOrigin: string
{
    case Manual = 'manual';

    case Transfer = 'transfer';

    case SiteApi = 'site_api';

    case MangoVpbx = 'mango_vpbx';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Создана вручную',
            self::Transfer => 'Передана из другого салона',
            self::SiteApi => 'API сайта',
            self::MangoVpbx => 'Mango VPBX',
        };
    }
}
