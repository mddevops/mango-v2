<?php

namespace App\Models\Concerns;

use App\Models\Showroom;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Shared shape of order reasons. A reason applies to every showroom or to the linked
 * ones; a disabled reason stays for history but is not offered for new choices.
 */
trait IsOrderReason
{
    use HasLockVersion;

    public function initializeIsOrderReason(): void
    {
        $this->mergeFillable(['code', 'name', 'description', 'sort_order', 'comment_required', 'all_showrooms']);

        $this->mergeCasts([
            'sort_order' => 'integer',
            'active' => 'boolean',
            'comment_required' => 'boolean',
            'all_showrooms' => 'boolean',
            'lock_version' => 'integer',
        ]);

        $this->attributes += [
            'sort_order' => 0,
            'active' => true,
            'comment_required' => false,
            'all_showrooms' => true,
            'lock_version' => 0,
        ];
    }

    /**
     * @return BelongsToMany<Showroom, $this>
     */
    public function showrooms(): BelongsToMany
    {
        return $this->belongsToMany(Showroom::class, $this->showroomPivot());
    }

    abstract protected function showroomPivot(): string;

    public function hasScope(): bool
    {
        return $this->all_showrooms || $this->showrooms()->exists();
    }
}
