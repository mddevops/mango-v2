<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A screen's view settings for one employee, stored as given after validation.
 *
 * @property int $id
 * @property int $user_id
 * @property string $key
 * @property array<string, mixed> $value
 */
class UserPreference extends Model
{
    public const ORDERS_LIST = 'orders_list';

    protected $fillable = ['user_id', 'key', 'value'];

    protected function casts(): array
    {
        return ['user_id' => 'integer', 'value' => 'array'];
    }
}
