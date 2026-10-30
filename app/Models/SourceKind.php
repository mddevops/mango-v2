<?php

namespace App\Models;

use App\Enums\IconColor;
use App\Models\Concerns\IsSourceReference;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Editable kind of a source (Сайт, Квиз, Бот, …) with the classifications and contact
 * channels a source of this kind may use. It never selects an integration protocol.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string|null $icon
 * @property bool $show_icon
 * @property IconColor $icon_color
 * @property int $sort_order
 * @property bool $active
 * @property int $lock_version
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Collection<int, SourceClassification> $classifications
 * @property-read Collection<int, ContactChannel> $channels
 */
class SourceKind extends Model
{
    use IsSourceReference;

    protected $fillable = ['code', 'name', 'icon', 'show_icon', 'icon_color', 'sort_order'];

    protected $attributes = ['icon_color' => 'gray'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['show_icon' => 'boolean', 'icon_color' => IconColor::class];
    }

    /**
     * @return HasMany<Source, $this>
     */
    public function sources(): HasMany
    {
        return $this->hasMany(Source::class);
    }

    /**
     * @return BelongsToMany<SourceClassification, $this>
     */
    public function classifications(): BelongsToMany
    {
        return $this->belongsToMany(SourceClassification::class, 'source_kind_classification');
    }

    /**
     * @return BelongsToMany<ContactChannel, $this>
     */
    public function channels(): BelongsToMany
    {
        return $this->belongsToMany(ContactChannel::class, 'source_kind_channel');
    }
}
