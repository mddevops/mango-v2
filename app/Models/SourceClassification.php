<?php

namespace App\Models;

use App\Models\Concerns\IsSourceReference;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Optional marketing classification of a source (Реклама, SEO, VA, …). Values carry no
 * behaviour of their own.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property int $sort_order
 * @property bool $active
 * @property int $lock_version
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class SourceClassification extends Model
{
    use IsSourceReference;

    protected $fillable = ['code', 'name', 'sort_order'];

    /**
     * @return HasMany<Source, $this>
     */
    public function sources(): HasMany
    {
        return $this->hasMany(Source::class);
    }

    /**
     * @return BelongsToMany<SourceKind, $this>
     */
    public function kinds(): BelongsToMany
    {
        return $this->belongsToMany(SourceKind::class, 'source_kind_classification');
    }
}
