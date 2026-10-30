<?php

namespace App\Models;

use App\Enums\MangoEventState;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One accepted Mango event: the raw `json` string exactly as signed, saved before the
 * 200 answer. Neither the signature nor the key is stored. `dedup_key` is unique per
 * connection, so a repeated delivery is not saved twice.
 *
 * @property int $id
 * @property int $integration_connection_id
 * @property string $kind
 * @property string $dedup_key
 * @property string $entry_id
 * @property string|null $call_id
 * @property int|null $seq
 * @property string $payload
 * @property CarbonImmutable|null $occurred_at
 * @property CarbonImmutable $received_at
 * @property MangoEventState $state
 * @property int $attempts
 * @property CarbonImmutable|null $last_attempt_at
 * @property string|null $error_code
 * @property CarbonImmutable|null $processed_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read IntegrationConnection $connection
 */
class MangoEvent extends Model
{
    public const CALL = 'call';

    public const SUMMARY = 'summary';

    /** `/events/recording` (3.1.4): the recording process of one recording_id. */
    public const RECORDING = 'recording';

    /** `/events/record/added` (3.1.8): the recording is in the cloud storage. */
    public const RECORD_ADDED = 'record_added';

    public const RECORDING_KINDS = [self::RECORDING, self::RECORD_ADDED];

    /** Safe error codes shown to administrators; never exception messages. */
    public const ERRORS = [
        'invalid_payload' => 'Событие не соответствует контракту Mango',
        'initial_status_missing' => 'Не выбран начальный статус заявок',
        'processing_error' => 'Внутренняя ошибка обработки',
        'not_queued' => 'Не удалось поставить в очередь',
    ];

    protected $guarded = ['id'];

    protected $hidden = ['payload'];

    protected function casts(): array
    {
        return [
            'integration_connection_id' => 'integer',
            'seq' => 'integer',
            'occurred_at' => 'datetime',
            'received_at' => 'datetime',
            'state' => MangoEventState::class,
            'attempts' => 'integer',
            'last_attempt_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    public function errorLabel(): ?string
    {
        return $this->error_code === null ? null : (self::ERRORS[$this->error_code] ?? self::ERRORS['processing_error']);
    }

    /**
     * @return BelongsTo<IntegrationConnection, $this>
     */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(IntegrationConnection::class, 'integration_connection_id');
    }
}
