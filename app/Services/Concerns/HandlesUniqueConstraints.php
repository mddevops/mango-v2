<?php

namespace App\Services\Concerns;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

trait HandlesUniqueConstraints
{
    /**
     * Codes are picked from committed rows, so two concurrent creations can choose the
     * same one; the unique index rejects the loser, which then retries with a fresh read.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function withGeneratedCode(string $codeIndex, callable $callback, int $attempts = 5): mixed
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return $callback();
            } catch (UniqueConstraintViolationException $exception) {
                if (! str_contains($exception->getMessage(), $codeIndex)) {
                    throw $exception;
                }

                if ($attempt >= $attempts) {
                    throw ValidationException::withMessages([
                        'name' => 'Не удалось подобрать уникальный технический код. Повторите сохранение.',
                    ]);
                }
            }
        }
    }

    /**
     * Turns a violation of the given unique index into a field error, so a duplicate
     * that slipped past validation in a concurrent request gets the same message.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function withUniqueField(string $index, string $field, string $message, callable $callback): mixed
    {
        try {
            return $callback();
        } catch (UniqueConstraintViolationException $exception) {
            if (! str_contains($exception->getMessage(), $index)) {
                throw $exception;
            }

            throw ValidationException::withMessages([$field => $message]);
        }
    }
}
