<?php

namespace App\Models;

use App\Enums\DepartmentKind;
use App\Models\Concerns\HasLockVersion;
use Carbon\CarbonImmutable;
use Database\Factories\DepartmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property int $id
 * @property int|null $showroom_id
 * @property string $code
 * @property string $name
 * @property DepartmentKind $kind
 * @property bool $active
 * @property int $lock_version
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Showroom|null $showroom
 */
class Department extends Model
{
    /** @use HasFactory<DepartmentFactory> */
    use HasFactory, HasLockVersion;

    protected $fillable = ['showroom_id', 'code', 'name', 'kind'];

    protected $attributes = [
        'active' => true,
        'lock_version' => 0,
    ];

    protected function casts(): array
    {
        return [
            'kind' => DepartmentKind::class,
            'active' => 'boolean',
            'lock_version' => 'integer',
        ];
    }

    public function isShared(): bool
    {
        return $this->showroom_id === null;
    }

    /**
     * @return BelongsTo<Showroom, $this>
     */
    public function showroom(): BelongsTo
    {
        return $this->belongsTo(Showroom::class);
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'department_members')->withTimestamps();
    }

    public function displayName(): string
    {
        return $this->showroom ? "{$this->name} ({$this->showroom->name})" : "{$this->name} (общий)";
    }
}
