<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * A normalized phone of a client. One number belongs to one client at most, so a
 * match is never ambiguous; differing clients are never merged automatically.
 *
 * @property int $id
 * @property int $client_id
 * @property string $phone
 * @property CarbonImmutable $created_at
 */
class ClientPhone extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['client_id', 'phone'];

    protected function casts(): array
    {
        return ['client_id' => 'integer'];
    }
}
