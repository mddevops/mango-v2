<?php

namespace App\Models;

use App\Enums\IconColor;
use App\Models\Concerns\IsSourceReference;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * How a client contacts the dealership through a source (callback, direct call, chat).
 * A site form with the client's phone is a callback. Transport details such as HTTP,
 * webhooks, API polling or Excel import are not channels.
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
 */
class ContactChannel extends Model
{
    use IsSourceReference;

    /** The callback channel; its code is fixed and survives renaming. */
    public const CALLBACK = 'callback-request';

    /** The direct call channel of orders created by incoming calls. */
    public const DIRECT_CALL = 'direct-call';

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
     * @return BelongsToMany<Source, $this>
     */
    public function sources(): BelongsToMany
    {
        return $this->belongsToMany(Source::class, 'source_channel');
    }
}
