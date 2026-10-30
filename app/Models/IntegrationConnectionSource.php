<?php

namespace App\Models;

use App\Support\IntegrationProvider;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Binding of a source to a connection account. A source has at most one binding per
 * provider; the lines say which calls of the account belong to the source.
 *
 * @property int $id
 * @property int $integration_connection_id
 * @property IntegrationProvider $provider
 * @property int $source_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read IntegrationConnection $connection
 * @property-read Source $source
 * @property-read Collection<int, IntegrationLine> $lines
 */
class IntegrationConnectionSource extends Model
{
    protected $fillable = ['integration_connection_id', 'provider', 'source_id'];

    protected function casts(): array
    {
        return [
            'integration_connection_id' => 'integer',
            'provider' => IntegrationProvider::class,
            'source_id' => 'integer',
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
     * @return BelongsTo<Source, $this>
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    /**
     * @return HasMany<IntegrationLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(IntegrationLine::class)->orderBy('id');
    }
}
