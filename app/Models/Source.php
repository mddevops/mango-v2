<?php

namespace App\Models;

use App\Models\Concerns\HasLockVersion;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A concrete place inquiries come from (website, quiz, bot, partner, …) that belongs
 * to exactly one showroom. The kind is an editable reference value, not a protocol:
 * how inquiries actually arrive is decided by connections, not by the kind or URL.
 *
 * The business day always follows the showroom. The `business_day_mode` and
 * `business_day_boundary` columns are unused and kept only so earlier saved values
 * are not destroyed.
 *
 * @property int $id
 * @property string $code
 * @property int $showroom_id
 * @property int|null $transfer_from_showroom_id the sending showroom of an internal transfer source
 * @property int $source_kind_id
 * @property int|null $source_classification_id
 * @property int|null $agency_id
 * @property string $name
 * @property string|null $url
 * @property string|null $note
 * @property int|null $legacy_classification_id
 * @property bool $active
 * @property int $lock_version
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Showroom $showroom
 * @property-read SourceKind $kind
 * @property-read SourceClassification|null $classification
 * @property-read SourceClassification|null $legacyClassification
 * @property-read Agency|null $agency
 * @property-read Collection<int, ContactChannel> $channels
 * @property-read SourceSiteIntake|null $siteIntake
 * @property-read Collection<int, IntegrationConnectionSource> $connectionBindings
 */
class Source extends Model
{
    use HasLockVersion;

    protected $fillable = ['showroom_id', 'source_kind_id', 'source_classification_id', 'agency_id', 'name', 'url', 'note'];

    protected $attributes = [
        'active' => true,
        'lock_version' => 0,
    ];

    protected function casts(): array
    {
        return [
            'showroom_id' => 'integer',
            'transfer_from_showroom_id' => 'integer',
            'source_kind_id' => 'integer',
            'source_classification_id' => 'integer',
            'agency_id' => 'integer',
            'legacy_classification_id' => 'integer',
            'active' => 'boolean',
            'lock_version' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Showroom, $this>
     */
    public function showroom(): BelongsTo
    {
        return $this->belongsTo(Showroom::class);
    }

    /**
     * @return BelongsTo<SourceKind, $this>
     */
    public function kind(): BelongsTo
    {
        return $this->belongsTo(SourceKind::class, 'source_kind_id');
    }

    /**
     * @return BelongsTo<SourceClassification, $this>
     */
    public function classification(): BelongsTo
    {
        return $this->belongsTo(SourceClassification::class, 'source_classification_id');
    }

    /**
     * @return BelongsTo<SourceClassification, $this>
     */
    public function legacyClassification(): BelongsTo
    {
        return $this->belongsTo(SourceClassification::class, 'legacy_classification_id');
    }

    /**
     * @return BelongsTo<Agency, $this>
     */
    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    /**
     * @return BelongsToMany<ContactChannel, $this>
     */
    public function channels(): BelongsToMany
    {
        return $this->belongsToMany(ContactChannel::class, 'source_channel');
    }

    /**
     * @return HasOne<SourceSiteIntake, $this>
     */
    public function siteIntake(): HasOne
    {
        return $this->hasOne(SourceSiteIntake::class);
    }

    /**
     * @return HasMany<IntegrationConnectionSource, $this>
     */
    public function connectionBindings(): HasMany
    {
        return $this->hasMany(IntegrationConnectionSource::class);
    }
}
