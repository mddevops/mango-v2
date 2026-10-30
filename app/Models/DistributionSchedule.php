<?php

namespace App\Models;

use App\Models\Concerns\HasLockVersion;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Callback distribution settings of one employee in one showroom: participation, the
 * weekly schedule, date exceptions and the time of his last automatic assignment there.
 * Other showrooms of the same employee have their own independent settings. A schedule
 * grants no access: the showroom must already be in the employee's scope.
 *
 * @property int $id
 * @property int $user_id
 * @property int $showroom_id
 * @property bool $participates
 * @property bool $monday
 * @property bool $tuesday
 * @property bool $wednesday
 * @property bool $thursday
 * @property bool $friday
 * @property bool $saturday
 * @property bool $sunday
 * @property CarbonImmutable|null $last_assigned_at
 * @property int $lock_version
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User $user
 * @property-read Showroom $showroom
 * @property-read \Illuminate\Database\Eloquent\Collection<int, DistributionScheduleException> $exceptions
 */
class DistributionSchedule extends Model
{
    use HasLockVersion;

    /** ISO weekday (1 = Monday) to its column. */
    public const WEEKDAYS = [
        1 => 'monday',
        2 => 'tuesday',
        3 => 'wednesday',
        4 => 'thursday',
        5 => 'friday',
        6 => 'saturday',
        7 => 'sunday',
    ];

    protected $fillable = ['user_id', 'showroom_id'];

    protected $attributes = [
        'participates' => false,
        'monday' => false,
        'tuesday' => false,
        'wednesday' => false,
        'thursday' => false,
        'friday' => false,
        'saturday' => false,
        'sunday' => false,
        'lock_version' => 0,
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'showroom_id' => 'integer',
            'participates' => 'boolean',
            'monday' => 'boolean',
            'tuesday' => 'boolean',
            'wednesday' => 'boolean',
            'thursday' => 'boolean',
            'friday' => 'boolean',
            'saturday' => 'boolean',
            'sunday' => 'boolean',
            'last_assigned_at' => 'datetime',
            'lock_version' => 'integer',
        ];
    }

    /**
     * @return list<bool> Monday first
     */
    public function weekdays(): array
    {
        return array_values(array_map(fn (string $column) => (bool) $this->getAttribute($column), self::WEEKDAYS));
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Showroom, $this>
     */
    public function showroom(): BelongsTo
    {
        return $this->belongsTo(Showroom::class);
    }

    /**
     * @return HasMany<DistributionScheduleException, $this>
     */
    public function exceptions(): HasMany
    {
        return $this->hasMany(DistributionScheduleException::class)->orderBy('date');
    }
}
