<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One transfer of an order to another showroom: the kept source order, the new order of
 * the receiving showroom, who did it, when, and the comment for the receiver. The source
 * order is transferred at most once; the operation id makes a repeated request harmless.
 *
 * @property int $id
 * @property string $operation_id
 * @property int $source_order_id
 * @property int $target_order_id
 * @property int $from_showroom_id
 * @property int $to_showroom_id
 * @property int|null $user_id
 * @property string $user_name
 * @property string|null $comment
 * @property CarbonImmutable $created_at
 * @property-read Order $sourceOrder
 * @property-read Order $targetOrder
 * @property-read Showroom $fromShowroom
 * @property-read Showroom $toShowroom
 */
class OrderTransfer extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'operation_id', 'source_order_id', 'target_order_id', 'from_showroom_id', 'to_showroom_id',
        'user_id', 'user_name', 'comment',
    ];

    protected function casts(): array
    {
        return [
            'source_order_id' => 'integer',
            'target_order_id' => 'integer',
            'from_showroom_id' => 'integer',
            'to_showroom_id' => 'integer',
            'user_id' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function sourceOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'source_order_id');
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function targetOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'target_order_id');
    }

    /**
     * @return BelongsTo<Showroom, $this>
     */
    public function fromShowroom(): BelongsTo
    {
        return $this->belongsTo(Showroom::class, 'from_showroom_id');
    }

    /**
     * @return BelongsTo<Showroom, $this>
     */
    public function toShowroom(): BelongsTo
    {
        return $this->belongsTo(Showroom::class, 'to_showroom_id');
    }
}
