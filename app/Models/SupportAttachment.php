<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A file attached to a support message. Stored on the private disk and served only
 * to those who can see the request.
 *
 * @property int $id
 * @property int $support_message_id
 * @property string $path
 * @property string $original_name
 * @property string $mime_type
 * @property int $size
 * @property CarbonImmutable $created_at
 */
class SupportAttachment extends Model
{
    public const UPDATED_AT = null;

    public const DISK = 'local';

    protected $fillable = ['support_message_id', 'path', 'original_name', 'mime_type', 'size'];

    protected function casts(): array
    {
        return ['support_message_id' => 'integer', 'size' => 'integer', 'created_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<SupportMessage, $this>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(SupportMessage::class, 'support_message_id');
    }
}
