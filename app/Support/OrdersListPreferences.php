<?php

namespace App\Support;

/**
 * The orders list view an employee chose: cards or table, the filter bar, column
 * visibility, order and pinning, and the sort used when the URL has none. Stored
 * values are re-checked on read, so a stale or hand-edited record falls back to defaults.
 */
final class OrdersListPreferences
{
    /** Columns the list can be sorted by on the server. */
    public const SORTS = [
        'id', 'received_at', 'contact_name', 'phone', 'showroom', 'source', 'channel',
        'repeat_number', 'status', 'responsible', 'arrival_at', 'callback_at', 'updated_at',
    ];

    public const DENSITIES = ['default', 'compact'];

    public const MAX_COLUMNS = 30;

    public const MAX_KEY_LENGTH = 50;

    /**
     * @return array{view: 'table'|'cards', filters: bool, columns: array<string, bool>|null, order: list<string>|null, pinning: array{start: list<string>, end: list<string>}, sort: string|null, direction: 'asc'|'desc'|null}
     */
    public static function normalize(mixed $stored): array
    {
        $stored = is_array($stored) ? $stored : [];

        $columns = $stored['columns'] ?? null;
        $columns = is_array($columns) && count($columns) <= self::MAX_COLUMNS
            ? array_filter($columns, fn ($visible, $key) => self::isKey($key) && is_bool($visible), ARRAY_FILTER_USE_BOTH)
            : [];
        $order = self::keys($stored['order'] ?? null);
        $pinning = is_array($stored['pinning'] ?? null) ? $stored['pinning'] : [];
        $sort = $stored['sort'] ?? null;
        $sort = is_string($sort) && in_array($sort, self::SORTS, true) ? $sort : null;
        $direction = $stored['direction'] ?? null;

        return [
            'view' => ($stored['view'] ?? null) === 'cards' ? 'cards' : 'table',
            'filters' => is_bool($stored['filters'] ?? null) ? $stored['filters'] : true,
            'columns' => $columns === [] ? null : $columns,
            'order' => $order === [] ? null : $order,
            'pinning' => ['start' => self::keys($pinning['start'] ?? null), 'end' => self::keys($pinning['end'] ?? null)],
            'sort' => $sort,
            'direction' => $sort !== null && in_array($direction, ['asc', 'desc'], true) ? $direction : null,
        ];
    }

    /**
     * @return list<string>
     */
    private static function keys(mixed $value): array
    {
        if (! is_array($value) || count($value) > self::MAX_COLUMNS) {
            return [];
        }

        return array_values(array_unique(array_filter($value, self::isKey(...))));
    }

    private static function isKey(mixed $key): bool
    {
        return is_string($key) && $key !== '' && strlen($key) <= self::MAX_KEY_LENGTH;
    }
}
