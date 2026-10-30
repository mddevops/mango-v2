<?php

namespace App\Exceptions;

use Illuminate\Database\QueryException;
use RuntimeException;
use Throwable;

/**
 * A known reason why a saved Mango event cannot be applied. Only the code is stored and
 * shown; it never carries personal data. A non-retryable failure stops at once: another
 * attempt would read the same stored data.
 *
 * Any other error of the call handling is replaced by this exception before it reaches
 * a log, the worker output or failed_jobs: the message of a database error holds the SQL
 * bindings (phones, the raw JSON), so only the exception class and SQLSTATE are kept and
 * the original is not chained.
 */
class MangoEventFailure extends RuntimeException
{
    /**
     * @param  array{exception?: string, sqlstate?: string, http_status?: int, mango_result?: int}  $diagnostics
     */
    public function __construct(
        public readonly string $errorCode,
        public readonly bool $retryable = true,
        public readonly array $diagnostics = [],
    ) {
        parent::__construct(trim("Mango event processing failed: {$errorCode} ".implode(' ', $diagnostics)));
    }

    public static function from(Throwable $exception): self
    {
        if ($exception instanceof self) {
            return $exception;
        }

        $state = $exception instanceof QueryException ? ($exception->errorInfo[0] ?? null) : null;

        return new self('processing_error', true, array_filter([
            'exception' => $exception::class,
            'sqlstate' => is_string($state) && preg_match('/^[0-9A-Z]{5}$/', $state) === 1 ? $state : null,
        ]));
    }
}
