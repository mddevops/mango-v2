<?php

namespace App\Models\Concerns;

/**
 * Shared shape of editable source reference values: a name, a display order and an
 * active flag. Values are disabled, never deleted, because sources keep using them.
 */
trait IsSourceReference
{
    use HasLockVersion;

    public function initializeIsSourceReference(): void
    {
        $this->mergeCasts([
            'sort_order' => 'integer',
            'active' => 'boolean',
            'lock_version' => 'integer',
        ]);

        $this->attributes += [
            'sort_order' => 0,
            'active' => true,
            'lock_version' => 0,
        ];
    }
}
