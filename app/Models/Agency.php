<?php

namespace App\Models;

use App\Models\Concerns\IsSourceReference;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property int $sort_order
 * @property bool $active
 * @property int $lock_version
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class Agency extends Model
{
    use IsSourceReference;

    protected $fillable = ['name', 'sort_order'];

    /**
     * @return HasMany<Source, $this>
     */
    public function sources(): HasMany
    {
        return $this->hasMany(Source::class);
    }
}
