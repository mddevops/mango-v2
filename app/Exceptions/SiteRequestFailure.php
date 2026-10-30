<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A known reason why an accepted site request cannot be processed yet. Only the code is
 * stored and shown; it never carries personal data.
 */
class SiteRequestFailure extends RuntimeException
{
    public function __construct(public readonly string $errorCode)
    {
        parent::__construct("Site request processing failed: {$errorCode}");
    }
}
