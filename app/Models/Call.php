<?php

namespace App\Models;

use App\Enums\CallDirection;
use App\Enums\CallMatchState;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One Mango call: every event with the same connection and entry_id. The row is the lock
 * all events of the call take, so realtime and summary never decide in parallel.
 *
 * Times are Mango's event times. `talk_seconds` is end_time − talk_time of the summary
 * and stays null without it. `answered_by_id` is the earliest connected eligible employee;
 * every answerer stays on the legs. `assignment_decided_at` is set once the call has
 * offered its answerer to its order; `assigned_user_id` and `assigned_connected_at` say
 * whom it actually assigned (only an order without a responsible takes one).
 *
 * @property int $id
 * @property int $integration_connection_id
 * @property string $entry_id
 * @property CallDirection|null $direction
 * @property bool $direction_final
 * @property bool $technical
 * @property string|null $line_number
 * @property int|null $integration_line_id
 * @property int|null $source_id
 * @property int|null $showroom_id
 * @property string|null $client_number
 * @property string|null $client_phone
 * @property int|null $order_id
 * @property bool $order_created
 * @property CallMatchState $match_state
 * @property bool $needs_review
 * @property bool $answerer_unresolved
 * @property bool $answer_ambiguous
 * @property string|null $result
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $answered_at
 * @property CarbonImmutable|null $ended_at
 * @property int|null $talk_seconds
 * @property int|null $disconnect_reason
 * @property string|null $summary_extension
 * @property CarbonImmutable|null $summary_received_at
 * @property string|null $answered_extension
 * @property int|null $answered_by_id
 * @property CarbonImmutable|null $assigned_connected_at
 * @property int|null $assigned_user_id
 * @property CarbonImmutable|null $assignment_decided_at
 * @property CarbonImmutable|null $last_event_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read IntegrationConnection $connection
 * @property-read Collection<int, CallLeg> $legs
 * @property-read Collection<int, CallRecording> $recordings
 * @property-read Order|null $order
 * @property-read Source|null $source
 * @property-read Showroom|null $showroom
 * @property-read IntegrationLine|null $line
 * @property-read User|null $answeredBy
 */
class Call extends Model
{
    public const ANSWERED = 'answered';

    public const MISSED = 'missed';

    protected $guarded = ['id'];

    protected $attributes = [
        'match_state' => 'pending',
        'direction_final' => false,
        'technical' => false,
        'order_created' => false,
        'needs_review' => false,
        'answerer_unresolved' => false,
        'answer_ambiguous' => false,
    ];

    protected function casts(): array
    {
        return [
            'integration_connection_id' => 'integer',
            'direction' => CallDirection::class,
            'direction_final' => 'boolean',
            'technical' => 'boolean',
            'integration_line_id' => 'integer',
            'source_id' => 'integer',
            'showroom_id' => 'integer',
            'order_id' => 'integer',
            'order_created' => 'boolean',
            'match_state' => CallMatchState::class,
            'needs_review' => 'boolean',
            'answerer_unresolved' => 'boolean',
            'answer_ambiguous' => 'boolean',
            'started_at' => 'datetime',
            'answered_at' => 'datetime',
            'ended_at' => 'datetime',
            'talk_seconds' => 'integer',
            'disconnect_reason' => 'integer',
            'summary_received_at' => 'datetime',
            'answered_by_id' => 'integer',
            'assigned_connected_at' => 'datetime',
            'assigned_user_id' => 'integer',
            'assignment_decided_at' => 'datetime',
            'last_event_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<IntegrationConnection, $this>
     */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(IntegrationConnection::class, 'integration_connection_id');
    }

    /**
     * @return HasMany<CallLeg, $this>
     */
    public function legs(): HasMany
    {
        return $this->hasMany(CallLeg::class)->orderBy('appeared_at')->orderBy('id');
    }

    /**
     * @return HasMany<CallRecording, $this>
     */
    public function recordings(): HasMany
    {
        return $this->hasMany(CallRecording::class)->orderBy('received_at')->orderBy('id');
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<Source, $this>
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    /**
     * @return BelongsTo<Showroom, $this>
     */
    public function showroom(): BelongsTo
    {
        return $this->belongsTo(Showroom::class);
    }

    /**
     * @return BelongsTo<IntegrationLine, $this>
     */
    public function line(): BelongsTo
    {
        return $this->belongsTo(IntegrationLine::class, 'integration_line_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function answeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'answered_by_id');
    }
}
