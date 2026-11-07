import { Head, router } from '@inertiajs/react';
import type { ColumnDef } from '@tanstack/react-table';
import { Flag, Pencil, Plus } from 'lucide-react';
import { useMemo, useState } from 'react';
import { toast } from 'sonner';
import OrderStatusController from '@/actions/App/Http/Controllers/Orders/OrderStatusController';
import { OrderStatusBadge } from '@/components/orders/order-status-badge';
import { OrderStatusDialog } from '@/components/orders/order-status-dialog';
import { saveFailureMessage } from '@/components/organization/form-alert';
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
import { firstError, saveRowOrder } from '@/lib/submit';
import { index as orderStatusesIndex } from '@/routes/order-statuses';
import { index as referencesIndex } from '@/routes/references';
import type { Option, OrderStatusRow, Paginated } from '@/types';

type Props = {
    items: Paginated<OrderStatusRow>;
    filters: TableQueryState;
    initial: string | null;
    colors: Option[];
    reasonTypes: Option[];
    can: { manage: boolean };
};

const ONLY = ['items', 'initial'];

function requirements(row: OrderStatusRow, reasonTypes: Option[]): string[] {
    return [
        ...(row.requires_callback_at ? ['Дата перезвона'] : []),
        ...(row.requires_arrival_at ? ['Дата приезда'] : []),
        ...(row.requires_visit_result ? ['Итог визита'] : []),
        ...(row.required_reason
            ? [
                  reasonTypes.find(
                      (type) => type.value === row.required_reason,
                  )?.label ?? row.required_reason,
              ]
            : []),
    ];
}

export default function OrderStatusesIndex({
    items,
    filters,
    initial,
    colors,
    reasonTypes,
    can,
}: Props) {
    const table = useTableQuery(orderStatusesIndex.url(), filters, ONLY);
    const [dialog, setDialog] = useState<{
        open: boolean;
        item: OrderStatusRow | null;
    }>({ open: false, item: null });

    const columns = useMemo<ColumnDef<DataGridFeatures, OrderStatusRow>[]>(
        () => {
            const makeInitial = (row: OrderStatusRow) =>
                router.patch(
                    OrderStatusController.initial.url(row.id),
                    { lock_version: row.lock_version },
                    {
                        preserveScroll: true,
                        preserveState: true,
                        onError: (errors) => {
                            toast.error(
                                firstError(errors) ?? saveFailureMessage,
                            );
                        },
                        onHttpException: () => {
                            toast.error(saveFailureMessage);

                            return false;
                        },
                        onNetworkError: () => {
                            toast.error(saveFailureMessage);

                            return false;
                        },
                    },
                );

            return [
                {
                    accessorKey: 'name',
                    header: 'Название',
                    size: 260,
                    cell: ({ row }) => (
                        <div className="flex flex-col gap-1">
                            <div className="flex flex-wrap items-center gap-2">
                                <OrderStatusBadge color={row.original.color}>
                                    {row.original.name}
                                </OrderStatusBadge>
                                {row.original.is_initial && (
                                    <Badge variant="primary-light">
                                        Начальный
                                    </Badge>
                                )}
                                {row.original.purpose && (
                                    <Badge variant="info-light">Системный</Badge>
                                )}
                                {row.original.show_as_tab && (
                                    <Badge variant="outline">Вкладка</Badge>
                                )}
                            </div>
                            {row.original.purpose && (
                                <span className="text-xs text-muted-foreground">
                                    {row.original.purpose}
                                </span>
                            )}
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
                    id: 'requirements',
                    header: 'Требования',
                    size: 220,
                    cell: ({ row }) => {
                        const list = requirements(row.original, reasonTypes);

                        return list.length > 0 ? (
                            <div className="flex flex-wrap gap-1">
                                {list.map((label) => (
                                    <Badge key={label} variant="outline">
                                        {label}
                                    </Badge>
                                ))}
                            </div>
                        ) : (
                            <span className="text-xs text-muted-foreground">
                                Нет
                            </span>
                        );
                    },
                },
                {
                    accessorKey: 'active',
                    header: 'Статус',
                    size: 110,
                    cell: ({ row }) => (
                        <StatusBadge active={row.original.active} />
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
                                    ...(!row.original.is_initial &&
                                    !row.original.purpose &&
                                    row.original.active
                                        ? [
                                              {
                                                  label: 'Сделать начальным',
                                                  icon: Flag,
                                                  onSelect: () =>
                                                      makeInitial(row.original),
                                              },
                                          ]
                                        : []),
                                ]}
                                status={
                                    row.original.is_initial ||
                                    row.original.purpose
                                        ? undefined
                                        : {
                                              active: row.original.active,
                                              url: OrderStatusController.status.url(
                                                  row.original.id,
                                              ),
                                              lockVersion:
                                                  row.original.lock_version,
                                              title: `Отключить «${row.original.name}»?`,
                                              description:
                                                  'Статус останется в истории, но выбрать его для заявок будет нельзя. Статус можно включить снова.',
                                              activateLabel: 'Включить',
                                              deactivateLabel: 'Отключить',
                                          }
                                }
                            />
                        ) : null,
                },
            ];
        },
        [can.manage, reasonTypes],
    );

    return (
        <>
            <Head title="Справочники — Статусы заявок" />
            <PageBody>
                <RegistryTable
                    title="Статусы заявок"
                    summary={[
                        recordCount(items.meta.total, table.isFiltered, [
                            'статус',
                            'статуса',
                            'статусов',
                        ]),
                        initial
                            ? `Новые заявки получают статус «${initial}»`
                            : 'Начальный статус не выбран',
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
                            ? 'По заданным условиям статусы не найдены.'
                            : 'Статусов пока нет.'
                    }
                    onReorder={
                        can.manage
                            ? (ids) =>
                                  saveRowOrder(
                                      OrderStatusController.reorder.url(),
                                      ids,
                                      ONLY,
                                  )
                            : undefined
                    }
                />
            </PageBody>

            <OrderStatusDialog
                open={dialog.open}
                item={dialog.item}
                colors={colors}
                reasonTypes={reasonTypes}
                onOpenChange={(open) =>
                    setDialog((current) => ({ ...current, open }))
                }
            />
        </>
    );
}

OrderStatusesIndex.layout = {
    breadcrumbs: [
        { title: 'Справочники', href: referencesIndex() },
        { title: 'Статусы заявок', href: orderStatusesIndex() },
    ],
};
