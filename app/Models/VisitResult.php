<?php

namespace App\Models;

use App\Models\Concerns\HasLockVersion;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Visit result of an order (consultation type), required by statuses with
 * `requires_visit_result`. Disabled values stay on the orders that already have them.
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
class VisitResult extends Model
{
    use HasLockVersion;

    public const AUDIT_SUBJECT = 'visit_result';

    public const TITLE = 'Итоги визита';

    public const SINGULAR = 'Итог визита';

    public const NAME_TAKEN = 'Итог визита с таким названием уже существует.';

    protected $fillable = ['code', 'name', 'sort_order'];

    protected $attributes = [
        'sort_order' => 0,
        'active' => true,
        'lock_version' => 0,
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'active' => 'boolean',
            'lock_version' => 'integer',
        ];
    }

    /**
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
