<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An extension of a Mango account and the employee it belongs to. The same number may
 * exist in different accounts; inside one account it names exactly one employee.
 *
 * @property int $id
 * @property int $integration_connection_id
 * @property string $extension
 * @property int $user_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read IntegrationConnection $connection
 * @property-read User $user
 */
class TelephonyExtension extends Model
{
    public const PATTERN = '/^[0-9A-Za-z._-]{1,32}$/';

    protected $fillable = ['integration_connection_id', 'extension', 'user_id'];

    protected function casts(): array
    {
        return [
            'integration_connection_id' => 'integer',
            'user_id' => 'integer',
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
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
