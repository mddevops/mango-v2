<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * One filterable field of a list: the operators it accepts and how a condition
 * narrows the query. Select values are checked against the allowed list, so the
 * access scope and dictionaries hold for every operator, including the negative ones.
 */
final class TableFilter
{
    public const TEXT = ['contains', 'not_contains'];

    public const EQUALITY = ['is', 'is_not'];

    public const SETS = ['is_any_of', 'is_none_of'];

    public const EMPTINESS = ['empty', 'not_empty'];

    public const DATES = ['is', 'on_or_after', 'on_or_before', 'between'];

    /** Operators that take two values, a start and an end. */
    private const RANGES = ['between'];

    private const MAX_TEXT = 100;

    private const MAX_VALUES = 100;

    /**
     * @param  list<string>  $operators
     * @param  list<string>|null  $allowed  null for free text
     * @param  Closure(Builder<*>, string, list<string>): void  $apply
     * @param  (Closure(string): ?string)|null  $normalize  turns free text into the value to apply, or null to drop it
     */
    private function __construct(
        public readonly array $operators,
        private readonly ?array $allowed,
        private readonly Closure $apply,
        private readonly ?Closure $normalize = null,
    ) {}

    /**
     * Free-text search with its own matching, for example an exact phone or a name.
     *
     * @param  Closure(Builder<*>, string): void  $match
     */
    public static function search(Closure $match): self
    {
        return new self(self::TEXT, null, function (Builder $query, string $operator, array $values) use ($match): void {
            $operator === 'not_contains'
                ? $query->whereNot(fn (Builder $inner) => $match($inner, $values[0]))
                : $match($query, $values[0]);
        });
    }

    /** A calendar date column; accepts 2026-10-22 and 22.10.2026. */
    public static function date(string $column): self
    {
        return new self(self::DATES, null, function (Builder $query, string $operator, array $values) use ($column): void {
            match ($operator) {
                'between' => $query->whereBetween($column, [$values[0], $values[1]]),
                'on_or_after' => $query->where($column, '>=', $values[0]),
                'on_or_before' => $query->where($column, '<=', $values[0]),
                default => $query->where($column, $values[0]),
            };
        }, self::normalizeDate(...));
    }

    /**
     * A timestamp column filtered by calendar days of the given timezone: «равно» is the
     * whole day, «по» includes the last day, and the bounds become UTC instants.
     */
    public static function moment(string $column, string $timezone): self
    {
        $start = fn (string $day) => CarbonImmutable::parse($day, $timezone)->startOfDay()->utc();

        return new self(self::DATES, null, function (Builder $query, string $operator, array $values) use ($column, $start): void {
            $from = $start($values[0]);
            $until = $start($values[1] ?? $values[0])->addDay();

            match ($operator) {
                'on_or_after' => $query->where($column, '>=', $from),
                'on_or_before' => $query->where($column, '<', $until),
                default => $query->where($column, '>=', $from)->where($column, '<', $until),
            };
        }, self::normalizeDate(...));
    }

    private static function normalizeDate(string $value): ?string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $match) === 1) {
            [, $year, $month, $day] = $match;
        } elseif (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $value, $match) === 1) {
            [, $day, $month, $year] = $match;
        } else {
            return null;
        }

        return checkdate((int) $month, (int) $day, (int) $year) ? "{$year}-{$month}-{$day}" : null;
    }

    /**
     * Free text over several columns. «не содержит» also keeps rows whose column is empty.
     *
     * @param  list<string>  $columns
     */
    public static function text(array $columns): self
    {
        return new self(self::TEXT, null, function (Builder $query, string $operator, array $values) use ($columns): void {
            $pattern = '%'.addcslashes($values[0], '%_\\').'%';

            if ($operator === 'not_contains') {
                foreach ($columns as $column) {
                    $query->where(fn (Builder $inner) => $inner->whereNull($column)->orWhere($column, 'not like', $pattern));
                }

                return;
            }

            $query->where(function (Builder $inner) use ($columns, $pattern): void {
                foreach ($columns as $column) {
                    $inner->orWhere($column, 'like', $pattern);
                }
            });
        });
    }

    /** The `active` flag: «Активные» or «Отключённые». */
    public static function status(string $column = 'active'): self
    {
        return new self(self::EQUALITY, ['active', 'inactive'], function (Builder $query, string $operator, array $values) use ($column): void {
            $active = $values[0] === 'active';
            $query->where($column, $operator === 'is' ? $active : ! $active);
        });
    }

    /**
     * A single-valued column such as a foreign key or an enum. A nullable column also
     * offers «пусто» and «не пусто», and its negative operators keep the empty rows.
     *
     * @param  iterable<int|string>  $allowed
     */
    public static function column(string $column, iterable $allowed, bool $nullable = false): self
    {
        return new self(
            [...self::EQUALITY, ...self::SETS, ...($nullable ? self::EMPTINESS : [])],
            self::strings($allowed),
            function (Builder $query, string $operator, array $values) use ($column, $nullable): void {
                match ($operator) {
                    'is', 'is_any_of' => $query->whereIn($column, $values),
                    'is_not', 'is_none_of' => $query->where(fn (Builder $inner) => $inner
                        ->whereNotIn($column, $values)
                        ->when($nullable, fn (Builder $empty) => $empty->orWhereNull($column))),
                    'empty' => $query->whereNull($column),
                    'not_empty' => $query->whereNotNull($column),
                };
            },
        );
    }

    /**
     * A fixed set of named conditions, such as «Не назначены»; «не равно» negates one.
     *
     * @param  array<string, Closure(Builder<*>): void>  $choices
     */
    public static function choice(array $choices): self
    {
        return new self(self::EQUALITY, array_keys($choices), function (Builder $query, string $operator, array $values) use ($choices): void {
            $apply = $choices[$values[0]];

            $operator === 'is_not'
                ? $query->whereNot(fn (Builder $inner) => $apply($inner))
                : $apply($query);
        });
    }

    /**
     * A many-to-many relation: «равно X» means the row has X among its links.
     *
     * @param  iterable<int|string>  $allowed
     */
    public static function relation(string $relation, string $key, iterable $allowed, bool $emptiable = true): self
    {
        return new self(
            [...self::EQUALITY, ...self::SETS, ...($emptiable ? self::EMPTINESS : [])],
            self::strings($allowed),
            function (Builder $query, string $operator, array $values) use ($relation, $key): void {
                match ($operator) {
                    'is', 'is_any_of' => $query->whereHas($relation, fn (Builder $inner) => $inner->whereIn($key, $values)),
                    'is_not', 'is_none_of' => $query->whereDoesntHave($relation, fn (Builder $inner) => $inner->whereIn($key, $values)),
                    'empty' => $query->doesntHave($relation),
                    'not_empty' => $query->has($relation),
                };
            },
        );
    }

    /**
     * The values a condition may use, or null when the condition is incomplete or
     * names nothing the viewer may see.
     *
     * @param  array<mixed>  $raw
     * @return list<string>|null
     */
    public function accept(string $operator, array $raw): ?array
    {
        if (in_array($operator, self::EMPTINESS, true)) {
            return [];
        }

        $strings = array_values(array_filter($raw, 'is_string'));

        if (in_array($operator, self::RANGES, true)) {
            $bounds = array_map(fn (string $value) => $this->normalize === null ? null : ($this->normalize)(trim($value)), array_slice($strings, 0, 2));

            if (count($bounds) !== 2 || in_array(null, $bounds, true)) {
                return null;
            }

            sort($bounds);

            return $bounds;
        }

        if ($this->allowed === null) {
            $text = mb_substr(trim($strings[0] ?? ''), 0, self::MAX_TEXT);

            if ($text !== '' && $this->normalize !== null) {
                $text = ($this->normalize)($text) ?? '';
            }

            return $text === '' ? null : [$text];
        }

        $values = array_values(array_unique(array_intersect($strings, $this->allowed)));

        if (in_array($operator, self::EQUALITY, true)) {
            $values = array_slice($values, 0, 1);
        }

        return $values === [] ? null : array_slice($values, 0, self::MAX_VALUES);
    }

    /**
     * @param  Builder<*>  $query
     * @param  list<string>  $values
     */
    public function apply(Builder $query, string $operator, array $values): void
    {
        ($this->apply)($query, $operator, $values);
    }

    /**
     * @param  iterable<int|string>  $values
     * @return list<string>
     */
    private static function strings(iterable $values): array
    {
        $strings = [];

        foreach ($values as $value) {
            $strings[] = (string) $value;
        }

        return $strings;
    }
}
