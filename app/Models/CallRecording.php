<?php

namespace App\Models;

use App\Enums\CallRecordingState;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Metadata of one Mango call recording of a connection (`recording_id` is unique within
 * one VPBX, 3.1.4). The audio stays at Mango and is streamed on demand through the order
 * card (MangoRecordingPlayer); no link, key or audio is stored here. `call_id` links the
 * call with the same entry_id once it is known; access always goes through that call's
 * order.
 *
 * Unused since playback from Mango: available_at, attempts, claim_token, claimed_at,
 * mango_result, file_path, mime_type, size_bytes, ready_at (columns of the removed fetch,
 * kept without a destructive migration).
 *
 * @property int $id
 * @property int $integration_connection_id
 * @property string $recording_id
 * @property string $entry_id
 * @property string|null $mango_call_id
 * @property int $last_seq
 * @property string|null $extension
 * @property int|null $call_id
 * @property string|null $recording_state
 * @property int|null $completion_code
 * @property string|null $recipient
 * @property CarbonImmutable|null $added_at
 * @property CallRecordingState $state
 * @property CarbonImmutable|null $available_at
 * @property int $attempts
 * @property string|null $claim_token
 * @property CarbonImmutable|null $claimed_at
 * @property string|null $error_code
 * @property int|null $mango_result
 * @property string|null $file_path
 * @property string|null $mime_type
 * @property int|null $size_bytes
 * @property CarbonImmutable $received_at
 * @property CarbonImmutable|null $ready_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read IntegrationConnection $connection
 * @property-read Call|null $call
 */
class CallRecording extends Model
{
    /** Why Mango keeps no recording (3.1.4); safe codes, never Mango answers. */
    public const ERRORS = [
        'not_saved_by_mango' => 'Mango сообщила, что запись не сохранена',
        'mail_only' => 'Запись отправлена только на e-mail, в облачном хранилище её нет',
    ];

    protected $guarded = ['id'];

    protected $hidden = ['file_path', 'claim_token'];

    protected $attributes = [
        'last_seq' => 0,
        'attempts' => 0,
    ];

    protected function casts(): array
    {
        return [
            'integration_connection_id' => 'integer',
            'last_seq' => 'integer',
            'call_id' => 'integer',
            'completion_code' => 'integer',
            'added_at' => 'datetime',
            'state' => CallRecordingState::class,
            'available_at' => 'datetime',
            'attempts' => 'integer',
            'claimed_at' => 'datetime',
            'mango_result' => 'integer',
            'size_bytes' => 'integer',
            'received_at' => 'datetime',
            'ready_at' => 'datetime',
        ];
    }

    public function errorLabel(): ?string
    {
        return $this->error_code === null ? null : (self::ERRORS[$this->error_code] ?? null);
    }

    /**
     * @return BelongsTo<IntegrationConnection, $this>
     */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(IntegrationConnection::class, 'integration_connection_id');
    }

    /**
     * @return BelongsTo<Call, $this>
     */
    public function call(): BelongsTo
    {
        return $this->belongsTo(Call::class);
    }
}
