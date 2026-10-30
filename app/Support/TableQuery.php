<?php

namespace App\Support;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Inertia\Inertia;

final class TableQuery
{
    public const PER_PAGE_OPTIONS = [10, 25, 50, 100];

    public static function perPage(Request $request): int
    {
        $perPage = (int) $request->query('per_page', 10);

        return in_array($perPage, self::PER_PAGE_OPTIONS, true) ? $perPage : 10;
    }

    /**
     * The requested sort column and direction; an unknown column or direction falls back
     * to the defaults, so a crafted URL never reaches the query builder.
     *
     * @param  list<string>  $columns
     * @return array{0: string, 1: 'asc'|'desc'}
     */
    public static function sort(Request $request, array $columns, string $default, string $defaultDirection = 'desc'): array
    {
        $sort = $request->query('sort');
        $direction = $request->query('direction');

        if (! is_string($sort) || ! in_array($sort, $columns, true)) {
            return [$default, $defaultDirection === 'asc' ? 'asc' : 'desc'];
        }

        return [$sort, $direction === 'asc' ? 'asc' : 'desc'];
    }

    /**
     * @template TItem
     *
     * @param  LengthAwarePaginator<int, covariant TItem>  $paginator
     * @param  Closure(TItem): array<string, mixed>  $map
     * @return array{data: list<array<string, mixed>>, meta: array{current_page: int, per_page: int, total: int, last_page: int}}
     */
    public static function paginated(LengthAwarePaginator $paginator, Closure $map): array
    {
        return [
            'data' => array_values(array_map($map, $paginator->items())),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ];
    }

    public static function flashSuccess(string $message): void
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);
    }
}
