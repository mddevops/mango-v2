<?php

namespace App\Models;

use App\Models\Concerns\IsOrderReason;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Reason for moving an order to the trash (legacy `order_trashes`).
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property int $sort_order
 * @property bool $active
 * @property bool $comment_required
 * @property bool $all_showrooms
 * @property int $lock_version
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Collection<int, Showroom> $showrooms
 */
class OrderTrashReason extends Model
{
    use IsOrderReason;

    protected function showroomPivot(): string
    {
        return 'order_trash_reason_showroom';
    }
}
