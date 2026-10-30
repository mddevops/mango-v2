<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property int $id
 * @property int|null $actor_id
 * @property string $actor_name
 * @property string $actor_kind user or system
 * @property string|null $basis why the system acted (console, incoming call, …); null for employees
 * @property string $event
 * @property string $subject_type
 * @property int|null $subject_id
 * @property string $subject_label
 * @property string $description
 * @property array<string, mixed>|null $changes
 * @property CarbonImmutable $created_at
 */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    public const ACTOR_USER = 'user';

    public const ACTOR_SYSTEM = 'system';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'changes' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * @return BelongsToMany<Showroom, $this>
     */
    public function showrooms(): BelongsToMany
    {
        return $this->belongsToMany(Showroom::class, 'audit_log_showroom');
    }
}
