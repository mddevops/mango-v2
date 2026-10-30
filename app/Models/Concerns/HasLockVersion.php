<?php

namespace App\Models\Concerns;

use Illuminate\Validation\ValidationException;

/**
 * Optimistic locking: the form sends the lock_version it was rendered with and the
 * record is re-read with lockForUpdate() before comparing, so a stale form cannot
 * silently overwrite a concurrent change.
 */
trait HasLockVersion
{
    public function ensureLockVersion(mixed $expected): void
    {
        if (! is_numeric($expected) || (int) $expected !== (int) $this->lock_version) {
            throw ValidationException::withMessages([
                'lock_version' => 'Запись уже изменил другой пользователь. Обновите страницу и повторите изменение.',
            ]);
        }
    }

    public function bumpLockVersion(): static
    {
        $this->lock_version = (int) $this->getOriginal('lock_version') + 1;

        return $this;
    }
}
