import { router } from '@inertiajs/react';
import { useCallback, useState } from 'react';

/** One list condition: «Салон — любое из — 1, 2». The server validates all three parts. */
export type TableCondition = {
    field: string;
    operator: string;
    values: string[];
};

export type TableSortDirection = 'asc' | 'desc';

export type TableQueryState = {
    filter: TableCondition[];
    per_page: number;
    /** Only lists sorted on the server send these; the server falls back on anything unknown. */
    sort?: string;
    direction?: TableSortDirection;
    tab?: string;
};

function sameConditions(a: TableCondition[], b: TableCondition[]): boolean {
    return JSON.stringify(a) === JSON.stringify(b);
}

/**
 * Keeps list conditions in the URL query and reloads the page props from the server.
 * The server owns pagination, filtering and access scope; the client only sends parameters.
 */
export function useTableQuery(
    url: string,
    initial: TableQueryState,
    only: string[],
) {
    const [query, setQuery] = useState<TableQueryState>(initial);
    const [isLoading, setIsLoading] = useState(false);
    const [failed, setFailed] = useState(false);

    const visit = useCallback(
        (next: TableQueryState, page = 1) => {
            // Flat keys with explicit indices: PHP reads them back as a list of conditions.
            const params: Record<string, string | number> = {};

            next.filter.forEach((condition, index) => {
                params[`filter[${index}][field]`] = condition.field;
                params[`filter[${index}][operator]`] = condition.operator;
                condition.values.forEach((value, position) => {
                    params[`filter[${index}][values][${position}]`] = value;
                });
            });

            if (next.per_page !== 10) {
                params.per_page = next.per_page;
            }

            if (next.sort) {
                params.sort = next.sort;
                params.direction = next.direction ?? 'desc';
            }

            if (next.tab && next.tab !== 'all') {
                params.tab = next.tab;
            }

            if (page > 1) {
                params.page = page;
            }

            router.get(url, params, {
                only: [...only, 'filters'],
                preserveState: true,
                preserveScroll: true,
                replace: true,
                onStart: () => {
                    setIsLoading(true);
                    setFailed(false);
                },
                onFinish: () => setIsLoading(false),
                onHttpException: () => {
                    setFailed(true);

                    return false;
                },
                onNetworkError: () => {
                    setFailed(true);

                    return false;
                },
            });
        },
        [url, only],
    );

    const setConditions = useCallback(
        (conditions: TableCondition[]) => {
            if (sameConditions(conditions, query.filter)) {
                return;
            }

            const next = { ...query, filter: conditions };
            setQuery(next);
            visit(next);
        },
        [query, visit],
    );

    const goToPage = useCallback(
        (page: number, perPage: number) => {
            const next = { ...query, per_page: perPage };
            setQuery(next);
            visit(next, perPage === query.per_page ? page : 1);
        },
        [query, visit],
    );

    const setSort = useCallback(
        (sort: string, direction: TableSortDirection) => {
            const next = { ...query, sort, direction };
            setQuery(next);
            visit(next);
        },
        [query, visit],
    );

    const setTab = useCallback(
        (tab: string) => {
            if (tab === query.tab) {
                return;
            }

            const next = { ...query, tab };
            setQuery(next);
            visit(next);
        },
        [query, visit],
    );

    const reload = useCallback(() => visit(query), [query, visit]);

    return {
        query,
        setConditions,
        goToPage,
        setSort,
        setTab,
        reload,
        isLoading,
        failed,
        isFiltered: query.filter.length > 0,
    };
}
