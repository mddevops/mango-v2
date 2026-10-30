<?php

namespace App\Models;

use App\Models\Concerns\HasLockVersion;
use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property CarbonImmutable|null $email_verified_at
 * @property string $password
 * @property bool $active
 * @property bool $has_global_access
 * @property int $lock_version
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property CarbonImmutable|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Collection<int, Role> $roles
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasLockVersion, HasRoles, Notifiable, TwoFactorAuthenticatable;

    protected $attributes = [
        'active' => true,
        'has_global_access' => false,
        'lock_version' => 0,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'active' => 'boolean',
            'has_global_access' => 'boolean',
            'lock_version' => 'integer',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsToMany<Showroom, $this>
     */
    public function showrooms(): BelongsToMany
    {
        return $this->belongsToMany(Showroom::class, 'user_showrooms')->withTimestamps();
    }

    /**
     * @return BelongsToMany<Department, $this>
     */
    public function departments(): BelongsToMany
    {
        return $this->belongsToMany(Department::class, 'department_members')->withTimestamps();
    }

    /**
     * @return HasMany<DistributionSchedule, $this>
     */
    public function distributionSchedules(): HasMany
    {
        return $this->hasMany(DistributionSchedule::class);
    }
}
