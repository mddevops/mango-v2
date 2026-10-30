<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An additional contact phone of one order, in the order the showroom entered them. The
 * main number is `orders.phone` and never repeats here.
 *
 * @property int $id
 * @property int $order_id
 * @property string $phone
 * @property string|null $name whose number it is
 * @property int $position from 1
 */
class OrderPhone extends Model
{
    public $timestamps = false;

    protected $fillable = ['order_id', 'phone', 'name', 'position'];

    protected function casts(): array
    {
        return ['order_id' => 'integer', 'position' => 'integer'];
    }
}
