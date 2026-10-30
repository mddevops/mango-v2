<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The Idempotency-Key was already used by this source for a request with other data.
 */
class IdempotencyConflict extends RuntimeException {}
