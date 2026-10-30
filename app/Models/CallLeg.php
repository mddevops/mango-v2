<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One leg (Mango call_id) of a call. `last_seq` orders the events of this leg only;
 * the state fields come from the event with the highest seq. `connected_at` is the first
 * moment the leg connected the client with an employee, `answered_extension` that
 * employee's extension and `answered_by_id` the employee it is mapped to.
 *
 * @property int $id
 * @property int $call_id
 * @property string $mango_call_id
 * @property string|null $taken_from_call_id
 * @property int $last_seq
 * @property string|null $state
 * @property string|null $location
 * @property string|null $from_extension
 * @property string|null $from_number
 * @property string|null $to_extension
 * @property string|null $to_number
 * @property string|null $line_number
 * @property bool $technical
 * @property string|null $answered_extension
 * @property int|null $answered_by_id
 * @property CarbonImmutable|null $appeared_at
 * @property CarbonImmutable|null $connected_at
 * @property CarbonImmutable|null $ended_at
 * @property int|null $disconnect_reason
 * @property-read User|null $answeredBy
 */
class CallLeg extends Model
{
    protected $guarded = ['id'];

    protected $attributes = [
        'last_seq' => 0,
        'technical' => false,
    ];

    protected function casts(): array
    {
        return [
            'call_id' => 'integer',
            'last_seq' => 'integer',
            'technical' => 'boolean',
            'answered_by_id' => 'integer',
            'appeared_at' => 'datetime',
            'connected_at' => 'datetime',
            'ended_at' => 'datetime',
            'disconnect_reason' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function answeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'answered_by_id');
    }
}
