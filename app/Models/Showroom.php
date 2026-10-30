<?php

namespace App\Models;

use App\Models\Concerns\HasLockVersion;
use Carbon\CarbonImmutable;
use Database\Factories\ShowroomFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string|null $address
 * @property string|null $phone
 * @property string $timezone
 * @property bool $business_day_boundary_enabled
 * @property string $business_day_boundary
 * @property bool $active
 * @property int $lock_version
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class Showroom extends Model
{
    /** @use HasFactory<ShowroomFactory> */
    use HasFactory, HasLockVersion;

    public const DEFAULT_TIMEZONE = 'Europe/Moscow';

    public const DEFAULT_BUSINESS_DAY_BOUNDARY = '19:00';

    protected $fillable = ['code', 'name', 'address', 'phone', 'timezone', 'business_day_boundary_enabled', 'business_day_boundary'];

    protected $attributes = [
        'timezone' => self::DEFAULT_TIMEZONE,
        'business_day_boundary_enabled' => true,
        'business_day_boundary' => self::DEFAULT_BUSINESS_DAY_BOUNDARY,
        'active' => true,
        'lock_version' => 0,
    ];

    protected function casts(): array
    {
        return [
            'business_day_boundary_enabled' => 'boolean',
            'active' => 'boolean',
            'lock_version' => 'integer',
        ];
    }

    /**
     * @return HasMany<Department, $this>
     */
    public function departments(): HasMany
    {
        return $this->hasMany(Department::class);
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_showrooms')->withTimestamps();
    }
}
