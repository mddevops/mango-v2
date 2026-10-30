<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A message in a support request. Messages are only added: the text stays as written.
 *
 * @property int $id
 * @property int $support_ticket_id
 * @property int|null $author_id
 * @property string $author_name
 * @property bool $from_staff
 * @property string $body
 * @property CarbonImmutable $created_at
 */
class SupportMessage extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['support_ticket_id', 'author_id', 'author_name', 'from_staff', 'body'];

    protected function casts(): array
    {
        return [
            'support_ticket_id' => 'integer',
            'author_id' => 'integer',
            'from_staff' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<SupportTicket, $this>
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'support_ticket_id');
    }

    /**
     * @return HasMany<SupportAttachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(SupportAttachment::class);
    }
}
