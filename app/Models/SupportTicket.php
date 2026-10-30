<?php

namespace App\Models;

use App\Support\PermissionCatalog as P;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A support request. The author writes from the "author" side; everyone with the support
 * permission answers from the "staff" side. Deleting only hides the request (soft delete).
 *
 * @property int $id
 * @property int|null $author_id
 * @property string $author_name
 * @property string $subject
 * @property CarbonImmutable $last_message_at
 * @property int|null $last_author_message_id
 * @property int|null $last_staff_message_id
 * @property int|null $author_read_message_id
 * @property int|null $staff_read_message_id
 * @property CarbonImmutable $created_at
 */
class SupportTicket extends Model
{
    use SoftDeletes;

    public const STATE_SENT = 'sent';

    public const STATE_READ = 'read';

    public const STATE_UNREAD = 'unread';

    protected $fillable = ['author_id', 'author_name', 'subject', 'last_message_at'];

    protected function casts(): array
    {
        return [
            'author_id' => 'integer',
            'last_message_at' => 'datetime',
            'last_author_message_id' => 'integer',
            'last_staff_message_id' => 'integer',
            'author_read_message_id' => 'integer',
            'staff_read_message_id' => 'integer',
        ];
    }

    /**
     * @return HasMany<SupportMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(SupportMessage::class);
    }

    /**
     * @param  Builder<SupportTicket>  $query
     * @return Builder<SupportTicket>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $user->can(P::SUPPORT_MANAGE) ? $query : $query->where('author_id', $user->id);
    }

    public function isAuthor(User $user): bool
    {
        return $this->author_id === $user->id;
    }

    /**
     * How the request looks to this user: a new answer to read, their last message read
     * by the other side, or their last message still waiting to be read.
     */
    public function stateFor(User $user): string
    {
        $author = $this->isAuthor($user);
        $own = $author ? $this->last_author_message_id : $this->last_staff_message_id;
        $other = $author ? $this->last_staff_message_id : $this->last_author_message_id;
        $ownRead = ($author ? $this->author_read_message_id : $this->staff_read_message_id) ?? 0;
        $otherRead = ($author ? $this->staff_read_message_id : $this->author_read_message_id) ?? 0;

        if ($other !== null && $other > $ownRead) {
            return self::STATE_UNREAD;
        }

        if ($own !== null && $own > ($other ?? 0)) {
            return $own <= $otherRead ? self::STATE_READ : self::STATE_SENT;
        }

        return self::STATE_READ;
    }
}
