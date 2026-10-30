import { Head, Link, router } from '@inertiajs/react';
import type {
    ColumnDef,
    ColumnPinningState,
    ColumnVisibilityState,
} from '@tanstack/react-table';
import {
    Building2,
    CalendarClock,
    CalendarDays,
    CircleDot,
    Globe,
    Hand,
    MessageSquareText,
    Phone,
    PhoneCall,
    Plus,
    Send,
    Shuffle,
    UserRound,
    UserX,
} from 'lucide-react';
import type { KeyboardEvent, MouseEvent } from 'react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { toast } from 'sonner';
import { OrderCommentsPopover } from '@/components/orders/order-comments-popover';
import { OrderCreateDialog } from '@/components/orders/order-create-dialog';
import { OrderMoment } from '@/components/orders/order-moment';
import { OrderPreviewDialog } from '@/components/orders/order-preview-dialog';
import { OrderRepeatBadge } from '@/components/orders/order-repeat-badge';
import { OrderRepeatsPopover } from '@/components/orders/order-repeats-popover';
import { OrderStatusBadge } from '@/components/orders/order-status-badge';
import { TransferDialog } from '@/components/orders/transfer-dialog';
import { PageBody } from '@/components/organization/page-header';
import { RowActions } from '@/components/organization/row-actions';
import { ReferenceIcon } from '@/components/reference-icon';
import { RegistrySettings } from '@/components/registry-settings';
import type {
    RegistryDensity,
    RegistryView,
} from '@/components/registry-settings';
import {
    recordCount,
    RegistryTable,
    searchFilter,
} from '@/components/registry-table';
import type { RegistryFilter } from '@/components/registry-table';
import { Badge } from '@/components/reui/badge';
import type { DataGridFeatures } from '@/components/reui/data-grid';
import { DataGridColumnHeader } from '@/components/reui/data-grid-column-header';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardFooter,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useCoalesced, useRealtimeSignal } from '@/hooks/use-realtime-signal';
import { useSavedPreferences } from '@/hooks/use-saved-preferences';
import { useTableQuery } from '@/hooks/use-table-query';
import type {
    TableQueryState,
    TableSortDirection,
} from '@/hooks/use-table-query';
import { postOrderAction } from '@/lib/order-actions';
import {
    formatBusinessDate,
    formatLocal,
    formatMoment,
} from '@/lib/order-format';
import { cn } from '@/lib/utils';
import {
    distribute,
    index as ordersIndex,
    listPreferences as saveListPreferences,
    show as ordersShow,
    take,
} from '@/routes/orders';
import type {
    OrderCreateForm,
    OrderListRow,
    OrderRow,
    OrderStatusColor,
    Paginated,
    ReferenceOption,
} from '@/types';

type OrderTab = {
    key: string;
    label: string;
    color: OrderStatusColor | null;
    count: number;
};

type Props = {
    orders: Paginated<OrderListRow>;
    tabs: OrderTab[];
    transferShowrooms: ReferenceOption[];
    filters: TableQueryState;
    showrooms: ReferenceOption[];
    sources: ReferenceOption[];
    channels: ReferenceOption[];
    statuses: ReferenceOption[];
    responsibles: ReferenceOption[];
    createForm: OrderCreateForm | null;
    can: { create: boolean };
    /** Saved for the account; columns and order are null until the employee changes them. */
    listPreferences: {
        view: RegistryView;
        density: RegistryDensity;
        filters: boolean;
        columns: ColumnVisibilityState | null;
        order: string[] | null;
        pinning: ColumnPinningState;
        sort: string | null;
        direction: TableSortDirection | null;
    };
};

type OrdersListPreferences = {
    view: RegistryView;
    density: RegistryDensity;
    filters: boolean;
    columns: ColumnVisibilityState;
    order: string[];
    pinning: ColumnPinningState;
    sort: string | null;
    direction: TableSortDirection | null;
};

const ONLY = ['orders', 'tabs'];
const DOUBLE_CLICK_MS = 250;
const DEFAULT_SORT = 'received_at';

const CALL_BUTTON =
    'bg-success/10 text-success-foreground hover:bg-success/20 hover:text-success-foreground dark:bg-success/15 dark:text-success dark:hover:bg-success/25 dark:hover:text-success';

/** Shown on request through «Показать»; the rest of the columns are on by default. */
const HIDDEN_BY_DEFAULT: ColumnVisibilityState = {
    id: false,
    showroom: false,
    transfer: false,
};

const ACTIONS_COLUMN = 'actions';

/** Row actions stay the last column, pinned to the end, whatever else is pinned. */
function withActionsLast(pinning: ColumnPinningState): ColumnPinningState {
    return {
        start: pinning.start.filter((id) => id !== ACTIONS_COLUMN),
        end: [
            ...pinning.end.filter((id) => id !== ACTIONS_COLUMN),
            ACTIONS_COLUMN,
        ],
    };
}

const isVisibility = (value: unknown): value is ColumnVisibilityState =>
    typeof value === 'object' &&
    value !== null &&
    !Array.isArray(value) &&
    Object.values(value).every((flag) => typeof flag === 'boolean');

function toOptions(items: ReferenceOption[]) {
    return items.map((item) => ({
        value: String(item.id),
        label: item.active ? item.name : `${item.name} (отключено)`,
    }));
}

function stopRowClick(event: MouseEvent) {
    event.stopPropagation();
}

export default function OrdersIndex({
    orders,
    tabs,
    transferShowrooms,
    filters,
    showrooms,
    sources,
    channels,
    statuses,
    responsibles,
    createForm,
    can,
    listPreferences,
}: Props) {
    const table = useTableQuery(ordersIndex.url(), filters, ONLY);
    const [preferences, setPreferences] =
        useSavedPreferences<OrdersListPreferences>(saveListPreferences(), {
            view: listPreferences.view,
            density: listPreferences.density,
            filters: listPreferences.filters,
            columns: isVisibility(listPreferences.columns)
                ? listPreferences.columns
                : HIDDEN_BY_DEFAULT,
            order: listPreferences.order ?? [],
            pinning: withActionsLast(listPreferences.pinning),
            sort: listPreferences.sort,
            direction: listPreferences.direction,
        });
    const view = preferences.view;
    const columnVisibility = preferences.columns;
    const setView = (next: RegistryView) =>
        setPreferences({ ...preferences, view: next });
    const setColumnVisibility = (next: ColumnVisibilityState) =>
        setPreferences({ ...preferences, columns: next });
    const setSort = (sort: string, direction: TableSortDirection) => {
        table.setSort(sort, direction);
        setPreferences({ ...preferences, sort, direction });
    };
    const [creating, setCreating] = useState(false);
    const [transferring, setTransferring] = useState<OrderListRow | null>(
        null,
    );
    const [transferOpen, setTransferOpen] = useState(false);
    const [preview, setPreview] = useState<OrderRow | null>(null);
    const startTransfer = (order: OrderListRow) => {
        setTransferring(order);
        setTransferOpen(true);
    };
    const [previewOpen, setPreviewOpen] = useState(false);
    const refresh = useCoalesced(() =>
        router.reload({ only: ONLY, preserveUrl: true }),
    );

    useRealtimeSignal((signal) => {
        if (
            signal.type === 'order.updated' ||
            signal.type === 'order.access-check' ||
            signal.type === 'resync'
        ) {
            refresh();
        }
    });

    const pendingClick = useRef<{
        id: number;
        timer: ReturnType<typeof setTimeout>;
    } | null>(null);

    useEffect(
        () => () => {
            if (pendingClick.current) {
                clearTimeout(pendingClick.current.timer);
            }
        },
        [],
    );

    const rowClick = (row: OrderRow) => {
        const pending = pendingClick.current;

        if (pending) {
            clearTimeout(pending.timer);
            pendingClick.current = null;

            if (pending.id === row.id) {
                router.visit(ordersShow(row.id));

                return;
            }
        }

        pendingClick.current = {
            id: row.id,
            timer: setTimeout(() => {
                pendingClick.current = null;
                setPreview(row);
                setPreviewOpen(true);
            }, DOUBLE_CLICK_MS),
        };
    };

    const filterFields = useMemo<RegistryFilter[]>(
        () => [
            searchFilter('номер телефона или имя…'),
            {
                key: 'showroom',
                label: 'Салон',
                icon: Building2,
                type: 'select',
                options: toOptions(showrooms),
            },
            {
                key: 'source',
                label: 'Источник',
                icon: Globe,
                type: 'select',
                options: toOptions(sources),
            },
            {
                key: 'channel',
                label: 'Тип заявки',
                icon: PhoneCall,
                type: 'select',
                options: toOptions(channels),
            },
            {
                key: 'order_status',
                label: 'Статус',
                icon: CircleDot,
                type: 'select',
                options: toOptions(statuses),
            },
            {
                key: 'responsible',
                label: 'Оператор',
                icon: UserRound,
                type: 'select',
                options: toOptions(responsibles),
                emptiable: true,
            },
            {
                key: 'assignment',
                label: 'Назначение',
                icon: UserX,
                type: 'select',
                options: [{ value: 'unassigned', label: 'Не назначены' }],
            },
            {
                key: 'received_at',
                label: 'Дата создания',
                icon: CalendarClock,
                type: 'date',
            },
            {
                key: 'business_date',
                label: 'Бизнес-дата',
                icon: CalendarDays,
                type: 'date',
                placeholder: 'ДД.ММ.ГГГГ',
            },
        ],
        [showrooms, sources, channels, statuses, responsibles],
    );

    const actionsOf = (order: OrderListRow) => [
        ...(order.can.take
            ? [
                  {
                      label: 'Взять в работу',
                      icon: Hand,
                      onSelect: () =>
                          postOrderAction(take.url(order.id), { only: ONLY }),
                  },
              ]
            : []),
        ...(order.can.assign
            ? [
                  {
                      label: 'Назначить ответственного',
                      icon: UserRound,
                      href: ordersShow.url(order.id),
                  },
              ]
            : []),
        ...(order.can.distribute
            ? [
                  {
                      label: 'Отправить в распределение',
                      icon: Shuffle,
                      onSelect: () =>
                          postOrderAction(distribute.url(order.id), {
                              only: ONLY,
                          }),
                  },
              ]
            : []),
        ...(order.can.transfer
            ? [
                  {
                      label: 'Передать в другой салон',
                      icon: Send,
                      onSelect: () => startTransfer(order),
                  },
              ]
            : []),
    ];

    const renderActions = (order: OrderListRow) => (
        <div onClick={stopRowClick} onDoubleClick={stopRowClick}>
            <RowActions
                label={`Действия: заявка №${order.id}`}
                actions={actionsOf(order)}
            />
        </div>
    );

    const columns = useMemo<ColumnDef<DataGridFeatures, OrderListRow>[]>(
        () => [
            {
                id: 'source',
                accessorKey: 'source',
                header: ({ column }) => (
                    <DataGridColumnHeader
                        column={column}
                        title="Источник" />
                ),
                meta: { headerTitle: 'Источник' },
                size: 170,
                cell: ({ row }) => (
                    <div className="flex min-w-0 items-center gap-2.5">
                        <ReferenceIcon
                            name={row.original.source_icon}
                            color={row.original.source_icon_color}
                        />
                        <div className="min-w-0">
                            <Link
                                href={ordersShow(row.original.id)}
                                onClick={stopRowClick}
                                onDoubleClick={stopRowClick}
                                aria-label={`Открыть заявку №${row.original.id}`}
                                className="block max-w-full truncate text-left text-sm font-medium underline-offset-2 transition-colors hover:text-primary hover:underline"
                            >
                                {row.original.source}
                            </Link>
                            <div
                                className="truncate text-xs text-muted-foreground"
                                title={row.original.source_kind}
                            >
                                {row.original.source_kind}
                            </div>
                        </div>
                    </div>
                ),
            },
            {
                id: 'channel',
                accessorKey: 'channel',
                header: ({ column }) => (
                    <DataGridColumnHeader
                        column={column}
                        title="Тип заявки" />
                ),
                meta: { headerTitle: 'Тип заявки' },
                size: 170,
                cell: ({ row }) => (
                    <div className="flex min-w-0 items-center gap-2.5">
                        <ReferenceIcon
                            name={row.original.channel_icon}
                            color={row.original.channel_icon_color}
                        />
                        <span className="truncate">
                            {row.original.channel}
                        </span>
                    </div>
                ),
            },
            {
                id: 'status',
                accessorFn: (row) => row.status.name,
                header: ({ column }) => (
                    <DataGridColumnHeader
                        column={column}
                        title="Состояние заявки" />
                ),
                meta: { headerTitle: 'Состояние заявки' },
                size: 170,
                cell: ({ row }) => (
                    <OrderStatusBadge color={row.original.status.color}>
                        {row.original.status.name}
                    </OrderStatusBadge>
                ),
            },
            {
                id: 'responsible',
                accessorKey: 'responsible',
                header: ({ column }) => (
                    <DataGridColumnHeader
                        column={column}
                        title="Оператор" />
                ),
                meta: { headerTitle: 'Оператор' },
                size: 170,
                cell: ({ row }) =>
                    row.original.responsible ?? (
                        <span className="text-muted-foreground">
                            не назначен
                        </span>
                    ),
            },
            {
                id: 'received_at',
                accessorKey: 'received_at',
                header: ({ column }) => (
                    <DataGridColumnHeader
                        column={column}
                        title="Дата создания"
                    />
                ),
                meta: { headerTitle: 'Дата создания' },
                size: 160,
                cell: ({ row }) => (
                    <OrderMoment value={row.original.received_at} />
                ),
            },
            {
                id: 'contact_name',
                accessorKey: 'contact_name',
                header: ({ column }) => (
                    <DataGridColumnHeader
                        column={column}
                        title="Клиент" />
                ),
                meta: { headerTitle: 'Клиент' },
                size: 200,
                cell: ({ row }) =>
                    row.original.contact_name ? (
                        <span className="font-medium">
                            {row.original.contact_name}
                        </span>
                    ) : (
                        <span className="text-muted-foreground">Без имени</span>
                    ),
            },
            {
                id: 'phone',
                accessorKey: 'phone',
                header: ({ column }) => (
                    <DataGridColumnHeader
                        column={column}
                        title="Телефон" />
                ),
                meta: { headerTitle: 'Телефон' },
                size: 170,
                cell: ({ row }) => (
                    <span className="tabular-nums">{row.original.phone}</span>
                ),
            },
            {
                id: 'repeat_number',
                accessorKey: 'repeat_number',
                header: ({ column }) => (
                    <DataGridColumnHeader
                        column={column}
                        title="Повторы" />
                ),
                meta: { headerTitle: 'Повторы' },
                size: 120,
                cell: ({ row }) =>
                    row.original.repeat_number === 0 ? (
                        <span className="text-muted-foreground">—</span>
                    ) : row.original.can.previous ? (
                        <OrderRepeatsPopover
                            orderId={row.original.id}
                            count={row.original.repeat_number}
                        />
                    ) : (
                        <OrderRepeatBadge count={row.original.repeat_number} />
                    ),
            },
            {
                id: 'arrival_at',
                accessorKey: 'arrival_at',
                header: ({ column }) => (
                    <DataGridColumnHeader
                        column={column}
                        title="Приедет" />
                ),
                meta: { headerTitle: 'Приедет' },
                size: 120,
                cell: ({ row }) => (
                    <span className="tabular-nums">
                        {row.original.arrival_at
                            ? formatBusinessDate(
                                  row.original.arrival_at.split('T')[0],
                              )
                            : '—'}
                    </span>
                ),
            },
            {
                id: 'callback_at',
                accessorKey: 'callback_at',
                header: ({ column }) => (
                    <DataGridColumnHeader
                        column={column}
                        title="Перезвонить" />
                ),
                meta: { headerTitle: 'Перезвонить' },
                size: 160,
                cell: ({ row }) => (
                    <span className="tabular-nums">
                        {formatLocal(row.original.callback_at)}
                    </span>
                ),
            },
            {
                id: 'updated_at',
                accessorKey: 'updated_at',
                header: ({ column }) => (
                    <DataGridColumnHeader
                        column={column}
                        title="Дата изменения"
                    />
                ),
                meta: { headerTitle: 'Дата изменения' },
                size: 160,
                cell: ({ row }) => (
                    <OrderMoment value={row.original.updated_at} />
                ),
            },
            {
                id: 'comment',
                header: ({ column }) => (
                    <DataGridColumnHeader
                        column={column}
                        title="Комментарий"
                    />
                ),
                meta: { headerTitle: 'Комментарий' },
                enableSorting: false,
                size: 200,
                cell: ({ row }) => (
                    <OrderCommentsPopover
                        orderId={row.original.id}
                        comments={row.original.comments}
                        total={row.original.comments_count}
                    />
                ),
            },
            {
                id: 'id',
                accessorKey: 'id',
                header: ({ column }) => (
                    <DataGridColumnHeader
                        column={column}
                        title="№" />
                ),
                meta: { headerTitle: '№' },
                size: 90,
                cell: ({ row }) => (
                    <Link
                        href={ordersShow(row.original.id)}
                        onClick={stopRowClick}
                        className="font-medium tabular-nums underline-offset-4 hover:underline"
                    >
                        {row.original.id}
                    </Link>
                ),
            },
            {
                id: 'showroom',
                accessorKey: 'showroom',
                header: ({ column }) => (
                    <DataGridColumnHeader
                        column={column}
                        title="Салон" />
                ),
                meta: { headerTitle: 'Салон' },
                size: 170,
            },
            {
                id: 'transfer',
                header: ({ column }) => (
                    <DataGridColumnHeader
                        column={column}
                        title="Передача"
                    />
                ),
                meta: { headerTitle: 'Передача' },
                enableSorting: false,
                size: 200,
                cell: ({ row }) =>
                    row.original.transferred_to ? (
                        <Badge variant="info-light">
                            Передано в {row.original.transferred_to}
                        </Badge>
                    ) : row.original.transferred_from ? (
                        <Badge variant="info-light">
                            Получено из {row.original.transferred_from}
                        </Badge>
                    ) : (
                        <span className="text-muted-foreground">—</span>
                    ),
            },
            {
                id: ACTIONS_COLUMN,
                header: () => <span className="sr-only">Действия</span>,
                enableSorting: false,
                enableHiding: false,
                enablePinning: false,
                size: 60,
                cell: ({ row }) => renderActions(row.original),
            },
        ],
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [],
    );

    const cardKeyDown = (event: KeyboardEvent, order: OrderListRow) => {
        if (event.target === event.currentTarget && event.key === 'Enter') {
            router.visit(ordersShow(order.id));
        }
    };

    const renderCallButtons = (order: OrderListRow) => (
        <div
            className="flex items-center gap-1"
            onClick={stopRowClick}
            onDoubleClick={stopRowClick}
        >
            <Button
                type="button"
                size="icon-sm"
                variant="ghost"
                aria-label={`Позвонить со своего номера: ${order.phone}`}
                title="Вызов со своего номера"
                className={CALL_BUTTON}
                onClick={() => toast.info('Звонки пока не подключены.')}
            >
                <Phone />
            </Button>
            <Button
                type="button"
                size="icon-sm"
                variant="ghost"
                aria-label={`Позвонить со случайного номера: ${order.phone}`}
                title="Вызов со случайного номера системы"
                className={CALL_BUTTON}
                onClick={() => toast.info('Звонки пока не подключены.')}
            >
                <span className="relative">
                    <Phone />
                    <Plus className="absolute -top-1.5 -right-2 size-3 stroke-3" />
                </span>
            </Button>
        </div>
    );

    const renderCard = (order: OrderListRow) => (
        <Card
            role="button"
            tabIndex={0}
            aria-label={`Заявка №${order.id}`}
            onClick={() => rowClick(order)}
            onKeyDown={(event) => cardKeyDown(event, order)}
            className="h-full min-w-0 cursor-pointer gap-0 rounded-(--frame-panel-radius) p-0 transition-colors outline-none hover:bg-muted/20 focus-visible:ring-[3px] focus-visible:ring-ring/50"
        >
            <CardHeader className="gap-2 border-b px-4 py-3.5 [.border-b]:pb-3.5">
                <div className="flex min-w-0 items-center justify-between gap-2">
                    <span
                        className={cn(
                            'min-w-0 truncate text-xs font-medium',
                            order.contact_name
                                ? 'text-muted-foreground'
                                : 'text-muted-foreground/70 italic',
                        )}
                    >
                        {order.contact_name ?? 'Без имени'}
                    </span>
                    <div className="flex shrink-0 items-center gap-1.5">
                        <OrderStatusBadge
                            color={order.status.color}
                            className="max-w-28 min-w-0 shrink justify-start truncate"
                        >
                            {order.status.name}
                        </OrderStatusBadge>
                        {renderCallButtons(order)}
                        {renderActions(order)}
                    </div>
                </div>
                <div className="min-w-0">
                    <CardTitle className="truncate text-base leading-5 font-semibold tabular-nums">
                        {order.phone}
                    </CardTitle>
                    <CardDescription className="mt-1 flex min-w-0 items-center gap-1.5 text-xs">
                        <Globe className="size-3.5 shrink-0" />
                        <span className="truncate">
                            {order.source} · {order.channel}
                        </span>
                    </CardDescription>
                </div>
            </CardHeader>
            <CardContent className="flex min-w-0 flex-1 items-center justify-between gap-3 px-4 py-3.5">
                <div className="min-w-0">
                    <div
                        className={cn(
                            'truncate text-sm font-medium',
                            !order.responsible && 'text-muted-foreground',
                        )}
                    >
                        {order.responsible ?? 'Не назначен'}
                    </div>
                    <div className="text-xs text-muted-foreground">
                        Оператор
                    </div>
                </div>
                {(order.repeat_number > 0 ||
                    order.transferred_to ||
                    order.transferred_from) && (
                    <div className="flex max-w-[60%] min-w-0 flex-wrap items-center justify-end gap-1.5">
                        <OrderRepeatBadge count={order.repeat_number} />
                        {order.transferred_to && (
                            <Badge
                                variant="info-light"
                                className="max-w-full truncate"
                                title={`Передано в ${order.transferred_to}`}
                            >
                                Передано в {order.transferred_to}
                            </Badge>
                        )}
                        {order.transferred_from && (
                            <Badge
                                variant="info-light"
                                className="max-w-full truncate"
                                title={`Получено из ${order.transferred_from}`}
                            >
                                Получено из {order.transferred_from}
                            </Badge>
                        )}
                    </div>
                )}
            </CardContent>
            <CardFooter className="grid grid-cols-2 items-stretch divide-x divide-border border-t p-0 [.border-t]:pt-0">
                <div className="flex min-w-0 items-start gap-2 px-4 py-3">
                    <CalendarClock className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                    <div className="flex min-w-0 flex-col gap-0.5">
                        <span className="text-xs text-muted-foreground">
                            Создана
                        </span>
                        <span className="truncate text-sm font-medium tabular-nums">
                            {formatMoment(order.received_at)}
                        </span>
                    </div>
                </div>
                <div className="flex min-w-0 items-start gap-2 px-4 py-3">
                    <MessageSquareText className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                    <div className="flex min-w-0 flex-col gap-0.5 text-sm">
                        <span className="text-xs text-muted-foreground">
                            Комментарий
                        </span>
                        <OrderCommentsPopover
                            orderId={order.id}
                            comments={order.comments}
                            total={order.comments_count}
                        />
                    </div>
                </div>
            </CardFooter>
        </Card>
    );

    const activeTab = table.query.tab ?? 'all';

    return (
        <>
            <Head title="Заявки" />
            <PageBody fluid>
                <RegistryTable
                    dense
                    compact={preferences.density === 'compact'}
                    title="Заявки"
                    summary={[
                        recordCount(
                            orders.meta.total,
                            table.isFiltered || activeTab !== 'all',
                            ['заявка', 'заявки', 'заявок'],
                        ),
                    ]}
                    action={
                        <div className="flex flex-wrap items-center gap-2">
                            {can.create && createForm && (
                                <Button
                                    size="sm"
                                    onClick={() => setCreating(true)}
                                >
                                    <Plus />
                                    Новая заявка
                                </Button>
                            )}
                        </div>
                    }
                    tabs={
                        <div className="overflow-x-auto border-b px-(--frame-panel-header-px) [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
                            <Tabs value={activeTab} onValueChange={table.setTab}>
                                <TabsList
                                    variant="line"
                                    className="h-auto w-max gap-6 p-0"
                                >
                                    {tabs.map((tab) => (
                                        <TabsTrigger
                                            key={tab.key}
                                            value={tab.key}
                                            className="px-0 pt-3 pb-3 text-sm group-data-[orientation=horizontal]/tabs:after:-bottom-px! focus-visible:border-transparent focus-visible:ring-0 focus-visible:outline-none focus-visible:after:opacity-50"
                                        >
                                            {tab.label}
                                            {tab.color ? (
                                                <OrderStatusBadge
                                                    color={tab.color}
                                                    radius="full"
                                                    className="tabular-nums"
                                                >
                                                    {tab.count.toLocaleString(
                                                        'ru-RU',
                                                    )}
                                                </OrderStatusBadge>
                                            ) : (
                                                <Badge
                                                    variant="secondary"
                                                    radius="full"
                                                    className="tabular-nums"
                                                >
                                                    {tab.count.toLocaleString(
                                                        'ru-RU',
                                                    )}
                                                </Badge>
                                            )}
                                        </TabsTrigger>
                                    ))}
                                </TabsList>
                            </Tabs>
                        </div>
                    }
                    filterActions={
                        <RegistrySettings
                            density={preferences.density}
                            onDensityChange={(density) =>
                                setPreferences({ ...preferences, density })
                            }
                            view={view}
                            onViewChange={setView}
                        />
                    }
                    filters={{
                        fields: filterFields,
                        conditions: table.query.filter,
                        onChange: table.setConditions,
                    }}
                    sorting={{
                        sort: table.query.sort ?? DEFAULT_SORT,
                        direction: table.query.direction ?? 'desc',
                        onChange: (sort, direction) => {
                            if (sort) {
                                setSort(sort, direction);
                            } else if (
                                (table.query.sort ?? DEFAULT_SORT) ===
                                DEFAULT_SORT
                            ) {
                                setSort(DEFAULT_SORT, 'asc');
                            } else {
                                setSort(DEFAULT_SORT, 'desc');
                            }
                        },
                    }}
                    columnVisibility={{
                        state: columnVisibility,
                        onChange: setColumnVisibility,
                    }}
                    columnLayout={{
                        order: preferences.order,
                        pinning: preferences.pinning,
                        onOrderChange: (order) =>
                            setPreferences({ ...preferences, order }),
                        onPinningChange: (pinning) =>
                            setPreferences({
                                ...preferences,
                                pinning: withActionsLast(pinning),
                            }),
                    }}
                    cards={view === 'cards' ? renderCard : undefined}
                    rows={orders}
                    columns={columns}
                    isLoading={table.isLoading}
                    failed={table.failed}
                    onPageChange={table.goToPage}
                    onRetry={table.reload}
                    onRowClick={rowClick}
                    emptyMessage={
                        table.isFiltered || activeTab !== 'all'
                            ? 'Заявки не найдены. Номер телефона нужно ввести полностью, в любом формате.'
                            : 'Заявок пока нет.'
                    }
                />
            </PageBody>

            <OrderPreviewDialog
                order={preview}
                open={previewOpen}
                onOpenChange={setPreviewOpen}
            />

            <TransferDialog
                open={transferOpen}
                order={transferring}
                showrooms={transferShowrooms.filter(
                    (showroom) => showroom.id !== transferring?.showroom_id,
                )}
                only={ONLY}
                onOpenChange={setTransferOpen}
            />

            {createForm && (
                <OrderCreateDialog
                    open={creating}
                    onOpenChange={setCreating}
                    options={createForm}
                />
            )}
        </>
    );
}

OrdersIndex.layout = {
    breadcrumbs: [{ title: 'Заявки', href: ordersIndex() }],
};
