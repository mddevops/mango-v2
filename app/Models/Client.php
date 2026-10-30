<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The shared identity of a person across showrooms, found by complete phone numbers.
 * Its name and phones are never shown to employees: each order keeps the contact data
 * its own showroom works with, so one showroom cannot read or change another's.
 *
 * @property int $id
 * @property string|null $name
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class Client extends Model
{
    protected $fillable = ['name'];

    /**
     * @return HasMany<ClientPhone, $this>
     */
    public function phones(): HasMany
    {
        return $this->hasMany(ClientPhone::class);
    }

    /**
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
