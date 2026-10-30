import { Head } from '@inertiajs/react';
import type { ColumnDef } from '@tanstack/react-table';
import { Pencil, Plus } from 'lucide-react';
import { useMemo, useState } from 'react';
import OrderReasonController from '@/actions/App/Http/Controllers/Orders/OrderReasonController';
import { OrderReasonDialog } from '@/components/orders/order-reason-dialog';
import { PageBody } from '@/components/organization/page-header';
import { RowActions } from '@/components/organization/row-actions';
import { StatusBadge } from '@/components/organization/status-badge';
import {
    recordCount,
    RegistryTable,
    searchFilter,
    statusFilter,
} from '@/components/registry-table';
import { Badge } from '@/components/reui/badge';
import type { DataGridFeatures } from '@/components/reui/data-grid';
import { Button } from '@/components/ui/button';
import { useTableQuery } from '@/hooks/use-table-query';
import type { TableQueryState } from '@/hooks/use-table-query';
import { saveRowOrder } from '@/lib/submit';
import { index as orderReasonsIndex } from '@/routes/order-reasons';
import { index as referencesIndex } from '@/routes/references';
import type {
    OrderReasonRow,
    OrderReasonTypeValue,
    Paginated,
    ReferenceOption,
} from '@/types';

type Props = {
    type: OrderReasonTypeValue;
    title: string;
    singular: string;
    items: Paginated<OrderReasonRow>;
    filters: TableQueryState;
    showrooms: ReferenceOption[];
    can: { manage: boolean };
};

const ONLY = ['items'];

export default function OrderReasonsIndex(props: Props) {
    return <ReasonTable key={props.type} {...props} />;
}

function ReasonTable({
    type,
    title,
    singular,
    items,
    filters,
    showrooms,
    can,
}: Props) {
    const table = useTableQuery(orderReasonsIndex.url(type), filters, ONLY);
    const [dialog, setDialog] = useState<{
        open: boolean;
        item: OrderReasonRow | null;
    }>({ open: false, item: null });

    const columns = useMemo<ColumnDef<DataGridFeatures, OrderReasonRow>[]>(
        () => [
            {
                accessorKey: 'name',
                header: 'Название',
                size: 280,
                cell: ({ row }) => (
                    <div className="flex flex-col gap-0.5">
                        <span className="font-medium">{row.original.name}</span>
                        <span className="text-xs text-muted-foreground">
                            {row.original.code}
                        </span>
                        {row.original.description && (
                            <span className="text-xs text-muted-foreground">
                                {row.original.description}
                            </span>
                        )}
                    </div>
                ),
            },
            {
                id: 'scope',
                header: 'Салоны',
                size: 220,
                cell: ({ row }) =>
                    row.original.all_showrooms ? (
                        <span className="text-sm">Все салоны</span>
                    ) : row.original.showrooms.length > 0 ? (
                        <span className="text-sm">
                            {row.original.showrooms
                                .map((showroom) => showroom.name)
                                .join(', ')}
                        </span>
                    ) : (
                        <Badge variant="warning-light">Не настроено</Badge>
                    ),
            },
            {
                accessorKey: 'comment_required',
                header: 'Комментарий',
                size: 130,
                cell: ({ row }) =>
                    row.original.comment_required ? (
                        <Badge variant="outline">Обязателен</Badge>
                    ) : (
                        <span className="text-xs text-muted-foreground">
                            Не обязателен
                        </span>
                    ),
            },
            {
                accessorKey: 'active',
                header: 'Статус',
                size: 110,
                cell: ({ row }) => (
                    <StatusBadge
                        active={row.original.active}
                        activeLabel="Активна"
                        inactiveLabel="Отключена"
                    />
                ),
            },
            {
                id: 'actions',
                header: () => <span className="sr-only">Действия</span>,
                size: 60,
                cell: ({ row }) =>
                    can.manage ? (
                        <RowActions
                            label={`Действия: ${row.original.name}`}
                            actions={[
                                {
                                    label: 'Изменить',
                                    icon: Pencil,
                                    onSelect: () =>
                                        setDialog({
                                            open: true,
                                            item: row.original,
                                        }),
                                },
                            ]}
                            status={{
                                active: row.original.active,
                                url: OrderReasonController.status.url({
                                    type,
                                    id: row.original.id,
                                }),
                                lockVersion: row.original.lock_version,
                                title: `Отключить «${row.original.name}»?`,
                                description:
                                    'Причина останется в истории, но выбрать её заново будет нельзя. Причину можно включить снова.',
                                activateLabel: 'Включить',
                                deactivateLabel: 'Отключить',
                            }}
                        />
                    ) : null,
            },
        ],
        [type, can.manage],
    );

    return (
        <>
            <Head title={`Справочники — ${title}`} />
            <PageBody>
                <RegistryTable
                    title={title}
                    summary={[
                        recordCount(items.meta.total, table.isFiltered, [
                            'причина',
                            'причины',
                            'причин',
                        ]),
                        'Причина действует во всех салонах или только в выбранных',
                    ]}
                    filters={{
                        fields: [
                            searchFilter('код или название…'),
                            statusFilter,
                        ],
                        conditions: table.query.filter,
                        onChange: table.setConditions,
                    }}
                    action={
                        can.manage && (
                            <Button
                                type="button"
                                onClick={() =>
                                    setDialog({ open: true, item: null })
                                }
                            >
                                <Plus />
                                Добавить
                            </Button>
                        )
                    }
                    rows={items}
                    columns={columns}
                    isLoading={table.isLoading}
                    failed={table.failed}
                    onPageChange={table.goToPage}
                    onRetry={table.reload}
                    emptyMessage={
                        table.isFiltered
                            ? 'По заданным условиям причины не найдены.'
                            : 'Причин пока нет.'
                    }
                    onReorder={
                        can.manage
                            ? (ids) =>
                                  saveRowOrder(
                                      OrderReasonController.reorder.url(type),
                                      ids,
                                      ONLY,
                                  )
                            : undefined
                    }
                />
            </PageBody>

            <OrderReasonDialog
                open={dialog.open}
                type={type}
                singular={singular}
                item={dialog.item}
                showrooms={showrooms}
                onOpenChange={(open) =>
                    setDialog((current) => ({ ...current, open }))
                }
            />
        </>
    );
}

OrderReasonsIndex.layout = {
    breadcrumbs: [{ title: 'Справочники', href: referencesIndex() }],
};
