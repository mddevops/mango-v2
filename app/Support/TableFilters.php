<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * The list conditions from `filter[i][field|operator|values][]`. Conditions on the
 * same field are combined with AND. Unknown fields, operators and values are dropped,
 * never trusted. A plain `?status=active` style link still works as an «равно» condition.
 */
final class TableFilters
{
    private const MAX_CONDITIONS = 20;

    /** Legacy single values that meant «no value» before operators existed. */
    private const LEGACY_EMPTY = ['none', 'shared'];

    /**
     * @param  array<string, TableFilter>  $fields
     * @param  list<array{field: string, operator: string, values: list<string>}>  $conditions
     */
    private function __construct(
        private readonly array $fields,
        public readonly array $conditions,
    ) {}

    /**
     * @param  array<string, TableFilter>  $fields
     */
    public static function fromRequest(Request $request, array $fields): self
    {
        $conditions = [];
        $raw = $request->query('filter');

        foreach (is_array($raw) ? array_values($raw) : [] as $item) {
            if (! is_array($item)) {
                continue;
            }

            $values = $item['values'] ?? [];
            $condition = self::condition($fields, $item['field'] ?? null, $item['operator'] ?? null, is_array($values) ? $values : [$values]);

            if ($condition !== null) {
                $conditions[] = $condition;
            }
        }

        foreach ($fields as $key => $field) {
            $value = $request->query($key);

            if (! is_string($value) || trim($value) === '' || $value === 'all') {
                continue;
            }

            $operator = match (true) {
                $field->operators === TableFilter::TEXT => 'contains',
                in_array($value, self::LEGACY_EMPTY, true) && in_array('empty', $field->operators, true) => 'empty',
                default => 'is',
            };
            $condition = self::condition($fields, $key, $operator, [$value]);

            if ($condition !== null) {
                $conditions[] = $condition;
            }
        }

        return new self($fields, array_slice($conditions, 0, self::MAX_CONDITIONS));
    }

    /**
     * @template TBuilder of Builder<*>
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    public function apply(Builder $query): Builder
    {
        foreach ($this->conditions as $condition) {
            $field = $this->fields[$condition['field']];
            $query->where(fn (Builder $inner) => $field->apply($inner, $condition['operator'], $condition['values']));
        }

        return $query;
    }

    /**
     * @param  array<string, TableFilter>  $fields
     * @param  array<mixed>  $values
     * @return array{field: string, operator: string, values: list<string>}|null
     */
    private static function condition(array $fields, mixed $key, mixed $operator, array $values): ?array
    {
        if (! is_string($key) || ! isset($fields[$key]) || ! is_string($operator)) {
            return null;
        }

        $field = $fields[$key];

        if (! in_array($operator, $field->operators, true)) {
            return null;
        }

        $accepted = $field->accept($operator, $values);

        return $accepted === null ? null : ['field' => $key, 'operator' => $operator, 'values' => $accepted];
    }
}
