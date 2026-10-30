<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A working day or a day off on one date in one showroom; it overrides the weekly
 * schedule of that showroom only.
 *
 * @property int $id
 * @property int $distribution_schedule_id
 * @property string $date
 * @property bool $works
 */
class DistributionScheduleException extends Model
{
    public $timestamps = false;

    protected $fillable = ['distribution_schedule_id', 'date', 'works'];

    protected function casts(): array
    {
        return [
            'distribution_schedule_id' => 'integer',
            'works' => 'boolean',
        ];
    }
}
