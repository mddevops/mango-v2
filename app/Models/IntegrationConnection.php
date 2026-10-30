<?php

namespace App\Models;

use App\Models\Concerns\HasLockVersion;
use App\Support\IntegrationProvider;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Saved settings of one external account. Secrets are encrypted at rest, hidden from
 * serialization and exposed to the interface only as presence flags.
 *
 * @property int $id
 * @property IntegrationProvider $provider
 * @property string $name
 * @property bool $active
 * @property string $verification_status
 * @property array<string, string>|null $secrets
 * @property string|null $webhook_key random part of the event address; finds the connection, never proves the sender
 * @property int $lock_version
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Collection<int, Showroom> $showrooms
 * @property-read Collection<int, IntegrationConnectionSource> $bindings
 * @property-read Collection<int, TelephonyExtension> $extensions
 */
class IntegrationConnection extends Model
{
    use HasLockVersion;

    public const UNVERIFIED = 'unverified';

    protected $fillable = ['provider', 'name'];

    protected $hidden = ['secrets', 'webhook_key'];

    protected static function booted(): void
    {
        static::creating(function (IntegrationConnection $connection) {
            $connection->webhook_key ??= Str::random(40);
        });
    }

    /**
     * The base address given to Mango; Mango appends `events/call`, `events/summary`, …
     */
    public function webhookUrl(): ?string
    {
        return $this->webhook_key === null ? null : url('api/mango/'.$this->webhook_key);
    }

    protected $attributes = [
        'active' => true,
        'verification_status' => self::UNVERIFIED,
        'lock_version' => 0,
    ];

    protected function casts(): array
    {
        return [
            'provider' => IntegrationProvider::class,
            'active' => 'boolean',
            'secrets' => 'encrypted:array',
            'lock_version' => 'integer',
        ];
    }

    public function hasSecret(string $key): bool
    {
        return ($this->secrets[$key] ?? '') !== '';
    }

    /**
     * @return array<string, bool>
     */
    public function secretPresence(): array
    {
        return collect($this->provider->secretFields())
            ->mapWithKeys(fn (string $label, string $key) => [$key => $this->hasSecret($key)])
            ->all();
    }

    /**
     * @return BelongsToMany<Showroom, $this>
     */
    public function showrooms(): BelongsToMany
    {
        return $this->belongsToMany(Showroom::class, 'integration_connection_showroom');
    }

    /**
     * @return HasMany<IntegrationConnectionSource, $this>
     */
    public function bindings(): HasMany
    {
        return $this->hasMany(IntegrationConnectionSource::class);
    }

    /**
     * @return HasMany<TelephonyExtension, $this>
     */
    public function extensions(): HasMany
    {
        return $this->hasMany(TelephonyExtension::class);
    }
}
