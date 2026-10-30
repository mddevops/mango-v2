import type { DragEndEvent } from '@dnd-kit/core';
import { arrayMove } from '@dnd-kit/sortable';
import type {
    ColumnDef,
    ColumnPinningState,
    PaginationState,
    SortingState,
    Updater,
    ColumnVisibilityState,
} from '@tanstack/react-table';
import { useTable } from '@tanstack/react-table';
import type { LucideIcon } from 'lucide-react';
import {
    CircleDot,
    Filter,
    FilterX,
    RotateCw,
    Search,
    Settings2,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { Fragment, useMemo, useState } from 'react';
import {
    DateRuleEditor,
    dateRuleDays,
    dateRuleText,
    dateRuleValue,
    isDateRange,
} from '@/components/registry-date-editor';
import type { DateRuleValue } from '@/components/registry-date-editor';
import {
    Alert,
    AlertAction,
    AlertDescription,
    AlertTitle,
} from '@/components/reui/alert';
import type { DataGridFeatures } from '@/components/reui/data-grid';
import {
    DataGrid,
    dataGridFeatures,
    useDataGrid,
} from '@/components/reui/data-grid';
import { DataGridColumnVisibility } from '@/components/reui/data-grid-column-visibility';
import { DataGridPagination } from '@/components/reui/data-grid-pagination';
import { DataGridScrollArea } from '@/components/reui/data-grid-scroll-area';
import { DataGridTable } from '@/components/reui/data-grid-table';
import {
    DataGridTableDndRowHandle,
    DataGridTableDndRows,
} from '@/components/reui/data-grid-table-dnd-rows';
import { Filters } from '@/components/reui/filters/filters';
import {
    createFilterQuery,
    createFilterRule,
    flattenFilterRules,
} from '@/components/reui/filters/filters-query';
import type {
    FilterField,
    FilterLabels,
    FilterOperator,
    FilterQuery,
} from '@/components/reui/filters/filters-types';
import {
    Frame,
    FrameDescription,
    FrameFooter,
    FrameHeader,
    FramePanel,
    FrameTitle,
} from '@/components/reui/frame';
import { Button } from '@/components/ui/button';
import type {
    TableCondition,
    TableSortDirection,
} from '@/hooks/use-table-query';
import { cn, plural } from '@/lib/utils';
import type { Option, Paginated } from '@/types';

const PAGE_SIZES = [10, 25, 50, 100];

const gridLabels = {
    loading: 'Загрузка…',
    empty: 'Нет данных',
    rowsPerPage: 'Строк на странице',
    paginationInfo: ({
        from,
        to,
        count,
    }: {
        from: number;
        to: number;
        count: number;
    }) => `${from}–${to} из ${count}`,
    previousPage: 'Предыдущая страница',
    nextPage: 'Следующая страница',
    goToPage: (page: number) => `Страница ${page}`,
    sortAscending: 'По возрастанию',
    sortDescending: 'По убыванию',
    pinColumnStart: 'Закрепить слева',
    pinColumnEnd: 'Закрепить справа',
    moveColumnStart: 'Сдвинуть влево',
    moveColumnEnd: 'Сдвинуть вправо',
    unpinColumn: (title: string) => `Открепить столбец «${title}»`,
    columnsMenu: 'Столбцы',
    toggleColumns: 'Столбцы таблицы',
    dragToReorderRow: 'Перетащите, чтобы изменить порядок',
    reorderingUnavailable: 'Порядок сейчас изменить нельзя',
};

const filterLabels: FilterLabels = {
    addFilter: 'Фильтры',
    advancedFilter: 'Расширенный фильтр',
    showRecords: 'Показывать записи',
    builderEmpty: 'Фильтров пока нет',
    builderEmptyHint: 'Добавьте фильтр, чтобы сузить список.',
    addCondition: 'Добавить фильтр',
    addConditionGroup: 'Добавить группу',
    addToGroup: 'Добавить фильтр в группу',
    removeGroup: 'Удалить группу',
    wrapInGroup: 'Объединить в группу',
    ungroup: 'Разгруппировать',
    moveToTopLevel: 'Переместить наверх',
    moveToGroup: (position) => `Переместить в группу ${position}`,
    reorder: 'Изменить порядок',
    reorderHint: 'Alt со стрелкой вверх или вниз меняет порядок',
    groupAll: 'Выполняются все условия…',
    groupAny: 'Выполняется любое условие…',
    groupPlaceholder: 'Перетащите фильтры сюда',
    rowLabel: (condition, depth) => `${condition}, уровень ${depth}`,
    groupLabel: (description, depth) => `${description}, уровень ${depth}`,
    groupAnnouncement: (added) =>
        added ? 'Группа добавлена' : 'Группа удалена',
    reorderAnnouncement: (label, position, total) =>
        `${label}: позиция ${position} из ${total}`,
    moveAnnouncement: (label, destination, position, total) =>
        `${label} перемещён в ${destination}, позиция ${position} из ${total}`,
    clearAll: 'Сбросить все',
    groupMenu: 'Действия с группой',
    searchFields: 'Найти поле…',
    searchOperators: 'Найти условие…',
    searchOptions: 'Поиск…',
    back: 'Назад',
    clear: 'Очистить',
    apply: 'Применить',
    discard: 'Отменить изменения',
    empty: 'Ничего не найдено',
    loading: 'Загрузка…',
    loadingMore: 'Загрузка…',
    loadMore: 'Показать ещё',
    error: 'Не удалось загрузить',
    retry: 'Повторить',
    where: 'Где',
    and: 'И',
    or: 'Или',
    combinator: 'Изменить связку',
    combinatorLabel: (word) => `${word}, изменить связку`,
    duplicate: 'Дублировать',
    negate: 'Инвертировать',
    convertToAdvanced: 'Расширенный редактор',
    remove: 'Удалить',
    chipMenu: (fieldLabel) => `Фильтр «${fieldLabel}»: действия`,
    filtersLabel: 'Фильтры',
    filterLabel: (condition) => condition,
    readOnly: 'Только просмотр: фильтры нельзя изменить.',
    pathSeparator: ' › ',
    valuePlaceholder: 'введите текст…',
    selectPlaceholder: 'Выберите…',
    noValue: 'не задано',
    selectCondition: 'Выберите условие',
    incomplete: 'незавершённый фильтр',
    branchAffordance: 'открывает список',
    exclusiveHint: 'нельзя сочетать с другими вариантами',
    exclusiveAnnouncement: (label, cleared) =>
        `${label}: выбрано. Снято других вариантов: ${cleared}.`,
    itemCount: (count) => `Элементов: ${count}`,
    fieldsLabel: 'Поля',
    resultsAnnouncement: (count) => `Найдено: ${count}`,
    actionsLabel: 'Действия',
    stepAnnouncement: (step, label) => {
        if (step === 'field') return `Выберите поле. ${label}`;
        if (step === 'operator') return `Выберите условие для «${label}»`;
        return `Укажите значение для «${label}»`;
    },
    countAnnouncement: (count) => `Применено фильтров: ${count}`,
    valueCount: (count) => `Выбрано: ${count}`,
    valueDetail: (summary, values) => `${summary}: ${values.join(', ')}`,
    valueRange: (from, to) => `от ${from} до ${to}`,
    rangeFrom: (fieldLabel) => `${fieldLabel}: от`,
    rangeTo: (fieldLabel) => `${fieldLabel}: до`,
    rangeSeparator: 'до',
    negated: (operatorLabel) => `не ${operatorLabel}`,
    issueOperator: 'Выберите условие',
    issueValue: 'Укажите значение',
    issueRange: 'Укажите обе границы',
    issueRangeOrder: 'Конец диапазона раньше начала',
    issueEmptyGroup: 'В группе пока нет условий',
    issueSummary: (count) => `Требуют внимания строк: ${count}`,
};

const TEXT_OPERATORS: FilterOperator[] = [
    { value: 'contains', label: 'содержит', inverse: 'not_contains' },
    { value: 'not_contains', label: 'не содержит', inverse: 'contains' },
];

const EQUALITY_OPERATORS: FilterOperator[] = [
    { value: 'is', label: 'равно', inverse: 'is_not' },
    { value: 'is_not', label: 'не равно', inverse: 'is' },
];

const SET_OPERATORS: FilterOperator[] = [
    {
        value: 'is_any_of',
        label: 'любое из',
        arity: 'many',
        inverse: 'is_none_of',
    },
    {
        value: 'is_none_of',
        label: 'ни одно из',
        arity: 'many',
        inverse: 'is_any_of',
    },
];

const DATE_OPERATORS: FilterOperator[] = [
    { value: 'is', label: 'равно' },
    { value: 'on_or_after', label: 'с' },
    { value: 'on_or_before', label: 'по' },
    { value: 'between', label: 'между', arity: 'range' },
];

const EMPTINESS_OPERATORS: FilterOperator[] = [
    { value: 'empty', label: 'пусто', arity: 'none', inverse: 'not_empty' },
    { value: 'not_empty', label: 'не пусто', arity: 'none', inverse: 'empty' },
];

/**
 * One list field. The operators offered here must match the server's
 * `TableFilter` for the same key: the server drops anything else.
 */
export type RegistryFilter = {
    key: string;
    label: string;
    icon?: LucideIcon;
    placeholder?: string;
    /** Always shown in the bar, even empty; removing it only clears it. */
    pinned?: boolean;
} & (
    | { type: 'text' }
    /** Calendar days, sent as 2026-10-22; «между» sends both ends. */
    | { type: 'date' }
    | {
          type: 'select';
          options: Option[];
          /** «любое из» and «ни одно из». On by default. */
          multiple?: boolean;
          /** «пусто» and «не пусто», for a field a record may leave unset. */
          emptiable?: boolean;
      }
);

export const statusOptions: Option[] = [
    { value: 'active', label: 'Активные' },
    { value: 'inactive', label: 'Отключённые' },
];

/** The pinned free-text search every list starts with. */
export function searchFilter(placeholder: string): RegistryFilter {
    return {
        key: 'search',
        label: 'Поиск',
        icon: Search,
        type: 'text',
        placeholder,
        pinned: true,
    };
}

export const statusFilter: RegistryFilter = {
    key: 'status',
    label: 'Статус',
    icon: CircleDot,
    type: 'select',
    options: statusOptions,
    multiple: false,
};

/** «12 салонов», or «Найдено: 3» while filters are applied. */
export function recordCount(
    total: number,
    isFiltered: boolean,
    forms: [string, string, string],
): string {
    return isFiltered
        ? `Найдено: ${total.toLocaleString('ru-RU')}`
        : plural(total, forms);
}

type RuleValue = string | string[] | DateRuleValue;

function operatorsOf(filter: RegistryFilter): FilterOperator[] {
    if (filter.type === 'text') {
        return TEXT_OPERATORS;
    }

    if (filter.type === 'date') {
        return DATE_OPERATORS;
    }

    return [
        ...EQUALITY_OPERATORS,
        ...(filter.multiple === false ? [] : SET_OPERATORS),
        ...(filter.emptiable ? EMPTINESS_OPERATORS : []),
    ];
}

function withPinned(
    filters: RegistryFilter[],
    query: FilterQuery<RuleValue>,
): FilterQuery<RuleValue> {
    const present = new Set(
        flattenFilterRules(query).map((rule) => rule.path[0]),
    );
    const missing = filters
        .filter((filter) => filter.pinned && !present.has(filter.key))
        .map((filter) =>
            createFilterRule<RuleValue>({
                id: `pinned-${filter.key}`,
                path: [filter.key],
                operator: operatorsOf(filter)[0].value,
            }),
        );

    return missing.length > 0
        ? { ...query, rules: [...missing, ...query.rules] }
        : query;
}

function toQuery(
    filters: RegistryFilter[],
    conditions: TableCondition[],
): FilterQuery<RuleValue> {
    const rules = conditions.flatMap((condition, index) => {
        const filter = filters.find((item) => item.key === condition.field);
        const operator = filter
            ? operatorsOf(filter).find(
                  (item) => item.value === condition.operator,
              )
            : undefined;

        if (!filter || !operator) {
            return [];
        }

        const arity = operator.arity ?? 'one';

        return [
            createFilterRule<RuleValue>({
                id: `filter-${index}`,
                path: [filter.key],
                operator: operator.value,
                value:
                    arity === 'none'
                        ? undefined
                        : filter.type === 'date'
                          ? dateRuleValue(condition.values, arity === 'range')
                          : arity === 'many'
                            ? condition.values
                            : condition.values[0],
            }),
        ];
    });

    return withPinned(filters, createFilterQuery(rules));
}

/** The complete rules only: one still waiting for its value sends nothing. */
function toConditions(
    filters: RegistryFilter[],
    query: FilterQuery<RuleValue>,
): TableCondition[] {
    return flattenFilterRules(query).flatMap((rule) => {
        const filter = filters.find((item) => item.key === rule.path[0]);

        if (!filter) {
            return [];
        }

        const operators = operatorsOf(filter);
        let operator = operators.find((item) => item.value === rule.operator);

        if (operator && rule.negated) {
            operator = operators.find((item) => item.value === operator?.inverse);
        }

        if (!operator) {
            return [];
        }

        const arity = operator.arity ?? 'one';

        if (arity === 'none') {
            return [{ field: filter.key, operator: operator.value, values: [] }];
        }

        if (filter.type === 'date') {
            const days = dateRuleDays(rule.value);
            const expected = arity === 'range' ? 2 : 1;

            return days.length === expected &&
                (arity === 'range') === isDateRange(rule.value)
                ? [{ field: filter.key, operator: operator.value, values: days }]
                : [];
        }

        const raw = Array.isArray(rule.value) ? rule.value : [rule.value];
        const values = raw
            .filter((value): value is string => typeof value === 'string')
            .map((value) => value.trim())
            .filter((value) => value !== '');

        if (values.length === 0) {
            return [];
        }

        return [
            {
                field: filter.key,
                operator: operator.value,
                values: arity === 'many' ? values : values.slice(0, 1),
            },
        ];
    });
}

function RegistryFilterBar({
    filters,
    conditions,
    onChange,
    actions,
}: {
    filters: RegistryFilter[];
    conditions: TableCondition[];
    onChange: (conditions: TableCondition[]) => void;
    actions?: ReactNode;
}) {
    const [query, setQuery] = useState(() => toQuery(filters, conditions));

    const pinnedCount = filters.filter((filter) => filter.pinned).length;
    const canReset =
        toConditions(filters, query).length > 0 ||
        flattenFilterRules(query).length > pinnedCount;

    const fields: FilterField<RuleValue>[] = filters.map((filter) => {
        const Icon = filter.icon;

        return {
            id: filter.key,
            label: filter.label,
            icon: Icon ? <Icon className="size-3.5" /> : undefined,
            placeholder: filter.placeholder,
            operators: operatorsOf(filter),
            ...(filter.type === 'date'
                ? {
                      editor: DateRuleEditor,
                      renderValue: ({ value }) => dateRuleText(value),
                      valueText: ({ value }) => dateRuleText(value),
                  }
                : filter.type === 'text'
                ? { type: 'text' as const }
                : {
                      type: 'select' as const,
                      options: filter.options,
                      searchable: filter.options.length > 7,
                  }),
        };
    });

    const change = (next: FilterQuery<RuleValue>) => {
        const pinnedNext = withPinned(filters, next);
        setQuery(pinnedNext);
        onChange(toConditions(filters, pinnedNext));
    };

    return (
        <div className="flex flex-wrap items-center justify-between gap-2 border-b px-(--frame-panel-header-px) py-(--frame-panel-header-py)">
            <Filters<RuleValue>
                fields={fields}
                query={query}
                onQueryChange={change}
                labels={filterLabels}
                size="sm"
                trigger={
                    <Button type="button" variant="outline" size="sm">
                        <Filter />
                        Фильтры
                    </Button>
                }
            />
            {(canReset || actions) && (
                <div className="flex shrink-0 items-center gap-2">
                    {canReset && (
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            className="shrink-0"
                            onClick={() => change(createFilterQuery())}
                        >
                            <FilterX className="size-3.5" />
                            Сбросить
                        </Button>
                    )}
                    {actions}
                </div>
            )}
        </div>
    );
}

/** «Показать»: the hideable columns of the grid it is rendered in. */
export function RegistryColumnsButton() {
    const { table } = useDataGrid();

    return (
        <DataGridColumnVisibility
            table={table}
            trigger={
                <Button
                    type="button"
                    variant="outline"
                    size="icon-sm"
                    aria-label="Показать столбцы"
                    title="Показать столбцы"
                >
                    <Settings2 />
                </Button>
            }
        />
    );
}

export type RegistrySorting = {
    sort: string;
    direction: TableSortDirection;
    /** null when the user clears the sort: the list returns to its default order. */
    onChange: (sort: string | null, direction: TableSortDirection) => void;
};

type Props<TData extends { id: number }> = {
    title: string;
    /** Short facts under the title, joined by dots: «12 салонов · 3 отключены». */
    summary?: ReactNode[];
    /** The primary action, usually «Добавить». */
    action?: ReactNode;
    filters?: {
        fields: RegistryFilter[];
        conditions: TableCondition[];
        onChange: (conditions: TableCondition[]) => void;
    };
    rows: Paginated<TData>;
    columns: ColumnDef<DataGridFeatures, TData>[];
    isLoading: boolean;
    failed: boolean;
    onPageChange: (page: number, perPage: number) => void;
    onRetry: () => void;
    emptyMessage: ReactNode;
    paginate?: boolean;
    /** Lets rows be dragged; receives the ids of the shown rows in their new order. */
    onReorder?: (ids: number[]) => void;
    onRowClick?: (row: TData) => void;
    /** The first row of the panel, above the filter bar, e.g. quick tabs. */
    tabs?: ReactNode;
    /** A flush panel without outer padding; the header takes the panel's colour. */
    dense?: boolean;
    /** Tighter table rows. */
    compact?: boolean;
    /** Hides the filter bar while keeping the applied conditions. */
    showFilters?: boolean;
    /** Buttons at the right end of the filter bar, after «Сбросить». */
    filterActions?: ReactNode;
    /** Server-side sorting; columns without an accessor never sort. */
    sorting?: RegistrySorting;
    columnVisibility?: {
        state: ColumnVisibilityState;
        onChange: (state: ColumnVisibilityState) => void;
    };
    /** Column order and pinning from the header menus; a header shows the column list when given `visibility`. */
    columnLayout?: {
        order: string[];
        pinning: ColumnPinningState;
        onOrderChange: (order: string[]) => void;
        onPinningChange: (pinning: ColumnPinningState) => void;
    };
    /** Rows as cards instead of the table; the pagination stays. */
    cards?: (row: TData) => ReactNode;
};

/**
 * The list screen layout: a header with the title, counts and the main action,
 * then one panel holding the filter bar, the table and the pagination. Rows,
 * paging and filtering stay on the server; this only renders and reports intent.
 */
export function RegistryTable<TData extends { id: number }>({
    title,
    summary,
    action,
    filters,
    rows,
    columns,
    isLoading,
    failed,
    onPageChange,
    onRetry,
    emptyMessage,
    paginate = true,
    onReorder,
    onRowClick,
    tabs,
    dense = false,
    compact = false,
    showFilters = true,
    filterActions,
    sorting,
    columnVisibility,
    columnLayout,
    cards,
}: Props<TData>) {
    const [moved, setMoved] = useState<{
        source: TData[];
        rows: TData[];
    } | null>(null);
    const data = moved?.source === rows.data ? moved.rows : rows.data;

    const gridColumns = useMemo<ColumnDef<DataGridFeatures, TData>[]>(
        () =>
            onReorder
                ? [
                      {
                          id: 'drag',
                          header: () => (
                              <span className="sr-only">Порядок</span>
                          ),
                          cell: () => <DataGridTableDndRowHandle />,
                          size: 40,
                      },
                      ...columns,
                  ]
                : columns,
        [columns, onReorder],
    );

    const handleDragEnd = ({ active, over }: DragEndEvent) => {
        if (!onReorder || !over || active.id === over.id) {
            return;
        }

        const from = data.findIndex((row) => String(row.id) === active.id);
        const to = data.findIndex((row) => String(row.id) === over.id);

        if (from < 0 || to < 0) {
            return;
        }

        const next = arrayMove(data, from, to);
        setMoved({ source: rows.data, rows: next });
        onReorder(next.map((row) => row.id));
    };

    const pagination = useMemo<PaginationState>(
        () => ({
            pageIndex: rows.meta.current_page - 1,
            pageSize: rows.meta.per_page,
        }),
        [rows.meta.current_page, rows.meta.per_page],
    );

    const sortingState = useMemo<SortingState>(
        () =>
            sorting
                ? [{ id: sorting.sort, desc: sorting.direction === 'desc' }]
                : [],
        [sorting],
    );

    const table = useTable({
        features: dataGridFeatures,
        data,
        columns: gridColumns,
        manualPagination: true,
        manualSorting: true,
        enableSorting: Boolean(sorting),
        enableMultiSort: false,
        rowCount: rows.meta.total,
        state: {
            pagination,
            sorting: sortingState,
            ...(columnVisibility
                ? { columnVisibility: columnVisibility.state }
                : {}),
            ...(columnLayout
                ? {
                      columnOrder: columnLayout.order,
                      columnPinning: columnLayout.pinning,
                  }
                : {}),
        },
        onColumnOrderChange: (updater: Updater<string[]>) => {
            if (columnLayout) {
                columnLayout.onOrderChange(
                    typeof updater === 'function'
                        ? updater(columnLayout.order)
                        : updater,
                );
            }
        },
        onColumnPinningChange: (updater: Updater<ColumnPinningState>) => {
            if (columnLayout) {
                columnLayout.onPinningChange(
                    typeof updater === 'function'
                        ? updater(columnLayout.pinning)
                        : updater,
                );
            }
        },
        onPaginationChange: (updater: Updater<PaginationState>) => {
            const next =
                typeof updater === 'function' ? updater(pagination) : updater;
            onPageChange(next.pageIndex + 1, next.pageSize);
        },
        onSortingChange: (updater: Updater<SortingState>) => {
            const next =
                typeof updater === 'function' ? updater(sortingState) : updater;
            const first = next[0];
            sorting?.onChange(
                first ? first.id : null,
                first?.desc === false ? 'asc' : 'desc',
            );
        },
        onColumnVisibilityChange: (updater: Updater<ColumnVisibilityState>) => {
            if (!columnVisibility) {
                return;
            }

            columnVisibility.onChange(
                typeof updater === 'function'
                    ? updater(columnVisibility.state)
                    : updater,
            );
        },
        getRowId: (row) => String(row.id),
    });

    const facts = summary?.filter(Boolean) ?? [];

    return (
        <DataGrid
            table={table}
            recordCount={rows.meta.total}
            isLoading={false}
            emptyMessage={emptyMessage}
            i18n={{ labels: gridLabels }}
            tableLayout={{
                dense: compact,
                rowsDraggable: Boolean(onReorder),
                columnsPinnable: Boolean(columnLayout),
                columnsMovable: Boolean(columnLayout),
                columnsVisibility: Boolean(columnLayout && columnVisibility),
            }}
            onRowClick={onRowClick}
        >
            <Frame spacing="sm" dense={dense} className="w-full">
                <FrameHeader className="flex-row flex-wrap items-center justify-between gap-3">
                    <div className="flex flex-col gap-0.5">
                        <FrameTitle className="text-balance">
                            <h1>{title}</h1>
                        </FrameTitle>
                        {facts.length > 0 && (
                            <FrameDescription className="flex flex-wrap items-center gap-1.5 text-xs text-pretty">
                                {facts.map((fact, index) => (
                                    <Fragment key={index}>
                                        {index > 0 && (
                                            <span
                                                className="size-1 shrink-0 rounded-full bg-muted-foreground/40"
                                                aria-hidden="true"
                                            />
                                        )}
                                        <span>{fact}</span>
                                    </Fragment>
                                ))}
                            </FrameDescription>
                        )}
                    </div>
                    {action}
                </FrameHeader>
                <FramePanel className="p-0">
                    {tabs}
                    {showFilters && filters && filters.fields.length > 0 && (
                        <RegistryFilterBar
                            filters={filters.fields}
                            conditions={filters.conditions}
                            onChange={filters.onChange}
                            actions={filterActions}
                        />
                    )}
                    {failed ? (
                        <div className="p-(--frame-panel-px)">
                            <Alert variant="destructive">
                                <AlertTitle>
                                    Не удалось загрузить данные
                                </AlertTitle>
                                <AlertDescription>
                                    Проверьте соединение и повторите попытку.
                                </AlertDescription>
                                <AlertAction>
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={onRetry}
                                    >
                                        <RotateCw />
                                        Повторить
                                    </Button>
                                </AlertAction>
                            </Alert>
                        </div>
                    ) : (
                        <div
                            aria-busy={isLoading}
                            className={cn(
                                'transition-opacity duration-200',
                                isLoading && 'pointer-events-none opacity-60',
                            )}
                        >
                            {cards ? (
                                data.length > 0 ? (
                                    <div className="grid gap-3 p-(--frame-panel-px) sm:grid-cols-2 xl:grid-cols-3">
                                        {data.map((row) => (
                                            <Fragment key={row.id}>
                                                {cards(row)}
                                            </Fragment>
                                        ))}
                                    </div>
                                ) : (
                                    <div className="p-(--frame-panel-px) py-10 text-center text-sm text-muted-foreground">
                                        {emptyMessage}
                                    </div>
                                )
                            ) : (
                                <DataGridScrollArea orientation="horizontal">
                                    {onReorder ? (
                                        <DataGridTableDndRows
                                            handleDragEnd={handleDragEnd}
                                            dataIds={data.map((row) =>
                                                String(row.id),
                                            )}
                                        />
                                    ) : (
                                        <DataGridTable />
                                    )}
                                </DataGridScrollArea>
                            )}
                        </div>
                    )}
                </FramePanel>
                {paginate && !failed && (
                    <FrameFooter>
                        <DataGridPagination sizes={PAGE_SIZES} />
                    </FrameFooter>
                )}
            </Frame>
        </DataGrid>
    );
}
