<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A known reason why a Mango recording cannot be played right now. Only the safe code
 * and numeric diagnostics are kept: never a link, a signature, a key or a Mango answer.
 * `unavailable` — Mango has no recording to give (not found, not saved yet, expired);
 * otherwise playback failed for another reason.
 */
class MangoPlaybackFailure extends RuntimeException
{
    /**
     * @param  array{http_status?: int, mango_result?: int}  $diagnostics
     */
    public function __construct(
        public readonly string $errorCode,
        public readonly bool $unavailable = false,
        public readonly array $diagnostics = [],
    ) {
        parent::__construct("Mango recording playback failed: {$errorCode}");
    }
}
