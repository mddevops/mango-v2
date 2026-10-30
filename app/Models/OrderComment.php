<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * A comment on an order. Comments are only added and read: the original text stays
 * as written, so there is no edit or delete.
 *
 * @property int $id
 * @property int $order_id
 * @property int|null $author_id
 * @property string $author_name
 * @property string $body
 * @property CarbonImmutable $created_at
 */
class OrderComment extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['order_id', 'author_id', 'author_name', 'body'];

    protected function casts(): array
    {
        return ['order_id' => 'integer', 'author_id' => 'integer', 'created_at' => 'datetime'];
    }
}
