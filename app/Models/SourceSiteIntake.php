<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Binding of a source to the common site API. The API key belongs to this one source;
 * only its SHA-256 hash and last characters are kept, so a lost key is replaced, never
 * shown again. Its access scope is the source and therefore the source's showroom.
 *
 * @property int $id
 * @property int $source_id
 * @property bool $active
 * @property string $verification_status
 * @property string|null $key_hash
 * @property string|null $key_hint
 * @property CarbonImmutable|null $key_issued_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class SourceSiteIntake extends Model
{
    protected $fillable = ['source_id'];

    protected $hidden = ['key_hash'];

    protected $attributes = [
        'active' => true,
        'verification_status' => IntegrationConnection::UNVERIFIED,
    ];

    protected function casts(): array
    {
        return [
            'source_id' => 'integer',
            'active' => 'boolean',
            'key_issued_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Source, $this>
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }
}
