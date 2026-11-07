<?php

namespace App\Models;

use App\Enums\OrderStatusColor;
use App\Enums\OrderStatusPurpose;
use App\Models\Concerns\HasLockVersion;
use App\Support\OrderReasonType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Editable order status. Its requirements (callback date, arrival date, visit result, reason list) are
 * enforced by OrderManager when an order gets this status or changes the related values.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property OrderStatusColor $color
 * @property int $sort_order
 * @property bool $show_as_tab Shown as a quick tab above the orders list.
 * @property bool $active
 * @property bool $is_initial
 * @property OrderStatusPurpose|null $purpose
 * @property bool $requires_callback_at
 * @property bool $requires_arrival_at
 * @property bool $requires_visit_result
 * @property OrderReasonType|null $required_reason
 * @property int $lock_version
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class OrderStatus extends Model
{
    use HasLockVersion;

    public const NAME_TAKEN = 'Статус с таким названием уже существует.';

    protected $fillable = [
        'code', 'name', 'description', 'color', 'sort_order', 'show_as_tab',
        'requires_callback_at', 'requires_arrival_at', 'requires_visit_result', 'required_reason',
    ];

    protected $attributes = [
        'color' => 'gray',
        'sort_order' => 0,
        'show_as_tab' => false,
        'active' => true,
        'is_initial' => false,
        'requires_callback_at' => false,
        'requires_arrival_at' => false,
        'requires_visit_result' => false,
        'lock_version' => 0,
    ];

    protected function casts(): array
    {
        return [
            'color' => OrderStatusColor::class,
            'sort_order' => 'integer',
            'show_as_tab' => 'boolean',
            'active' => 'boolean',
            'is_initial' => 'boolean',
            'purpose' => OrderStatusPurpose::class,
            'requires_callback_at' => 'boolean',
            'requires_arrival_at' => 'boolean',
            'requires_visit_result' => 'boolean',
            'required_reason' => OrderReasonType::class,
            'lock_version' => 'integer',
        ];
    }

    public function hasRequirements(): bool
    {
        return $this->requires_callback_at || $this->requires_arrival_at || $this->requires_visit_result || $this->required_reason !== null;
    }
}
