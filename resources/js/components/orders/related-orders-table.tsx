import { Link } from '@inertiajs/react';
import type { ColumnDef } from '@tanstack/react-table';
import { useTable } from '@tanstack/react-table';
import { useMemo } from 'react';
import { OrderStatusBadge } from '@/components/orders/order-status-badge';
import type { DataGridFeatures } from '@/components/reui/data-grid';
import { DataGrid, dataGridFeatures } from '@/components/reui/data-grid';
import { DataGridScrollArea } from '@/components/reui/data-grid-scroll-area';
import { DataGridTable } from '@/components/reui/data-grid-table';
import { formatBusinessDate, formatMoment } from '@/lib/order-format';
import { show as ordersShow } from '@/routes/orders';
import type { RelatedOrder } from '@/types';

/** A compact list of other orders of the client; a row links only where the viewer may open it. */
export function RelatedOrdersTable({
    rows,
    showShowroom = false,
    emptyMessage,
}: {
    rows: RelatedOrder[];
    showShowroom?: boolean;
    emptyMessage: string;
}) {
    const columns = useMemo<ColumnDef<DataGridFeatures, RelatedOrder>[]>(
        () => [
            {
                accessorKey: 'id',
                header: '№',
                size: 80,
                cell: ({ row }) =>
                    row.original.can_open ? (
                        <Link
                            href={ordersShow(row.original.id)}
                            className="font-medium tabular-nums underline-offset-4 hover:underline"
                        >
                            {row.original.id}
                        </Link>
                    ) : (
                        <span className="tabular-nums">{row.original.id}</span>
                    ),
            },
            {
                accessorKey: 'received_at',
                header: 'Дата',
                size: 150,
                cell: ({ row }) => (
                    <div className="flex flex-col">
                        <span className="tabular-nums">
                            {formatMoment(row.original.received_at)}
                        </span>
                        <span className="text-xs text-muted-foreground">
                            бизнес-дата{' '}
                            {formatBusinessDate(row.original.business_date)}
                        </span>
                    </div>
                ),
            },
            ...(showShowroom
                ? [
                      {
                          accessorKey: 'showroom',
                          header: 'Салон',
                          size: 160,
                      } satisfies ColumnDef<DataGridFeatures, RelatedOrder>,
                  ]
                : []),
            {
                id: 'source',
                header: 'Источник и тип заявки',
                size: 200,
                cell: ({ row }) => (
                    <div className="flex flex-col">
                        <span>{row.original.source}</span>
                        <span className="text-xs text-muted-foreground">
                            {row.original.channel}
                        </span>
                    </div>
                ),
            },
            {
                id: 'status',
                header: 'Статус',
                size: 140,
                cell: ({ row }) => (
                    <OrderStatusBadge color={row.original.status.color}>
                        {row.original.status.name}
                    </OrderStatusBadge>
                ),
            },
            {
                accessorKey: 'responsible',
                header: 'Ответственный',
                size: 160,
                cell: ({ row }) => row.original.responsible ?? '—',
            },
        ],
        [showShowroom],
    );

    const table = useTable({
        features: dataGridFeatures,
        data: rows,
        columns,
        getRowId: (row) => String(row.id),
    });

    return (
        <DataGrid
            table={table}
            recordCount={rows.length}
            isLoading={false}
            emptyMessage={emptyMessage}
        >
            <DataGridScrollArea orientation="horizontal">
                <DataGridTable />
            </DataGridScrollArea>
        </DataGrid>
    );
}
