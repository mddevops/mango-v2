<?php

namespace App\Models;

use App\Models\Concerns\HasLockVersion;
use App\Support\RoleCatalog;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * @property int $id
 * @property string $name
 * @property string|null $label
 * @property string $guard_name
 * @property bool $is_system
 * @property int $lock_version
 */
class Role extends SpatieRole
{
    use HasLockVersion;

    protected $attributes = [
        'is_system' => false,
        'lock_version' => 0,
    ];

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'lock_version' => 'integer',
        ];
    }

    public function isAdministrator(): bool
    {
        return $this->name === RoleCatalog::ADMINISTRATOR;
    }

    public function displayName(): string
    {
        return $this->label ?: $this->name;
    }

    /**
     * @return list<string>
     */
    public function permissionNames(): array
    {
        return array_values(array_filter($this->permissions->pluck('name')->all(), 'is_string'));
    }
}
