<?php

namespace App\Support;

/**
 * Providers whose connection settings can be saved. A provider here has a settings
 * form only; whether its protocol is implemented is a separate adapter task.
 */
enum IntegrationProvider: string
{
    case MangoVpbx = 'mango_vpbx';

    public function label(): string
    {
        return match ($this) {
            self::MangoVpbx => 'Mango VPBX',
        };
    }

    /**
     * @return array<string, string> secret key => label
     */
    public function secretFields(): array
    {
        return match ($this) {
            self::MangoVpbx => ['api_key' => 'API key', 'api_salt' => 'API salt'],
        };
    }
}
