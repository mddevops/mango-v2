<?php

namespace App\Http\Requests\Organization\Concerns;

/**
 * Typed accessors over validated input, so services receive exact shapes
 * instead of loosely typed request arrays.
 */
trait ReadsValidatedValues
{
    protected function text(string $key): string
    {
        $value = $this->validated($key);

        return is_string($value) ? $value : '';
    }

    protected function nullableText(string $key): ?string
    {
        $value = $this->validated($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    protected function number(string $key): int
    {
        $value = $this->validated($key);

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @return list<int>
     */
    protected function idList(string $key): array
    {
        $values = $this->validated($key);

        if (! is_array($values)) {
            return [];
        }

        return array_values(array_map(fn (mixed $value): int => is_numeric($value) ? (int) $value : 0, $values));
    }

    /**
     * @return list<string>
     */
    protected function textList(string $key): array
    {
        $values = $this->validated($key);

        return is_array($values) ? array_values(array_filter($values, 'is_string')) : [];
    }
}
