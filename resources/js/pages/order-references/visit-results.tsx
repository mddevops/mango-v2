import { Head } from '@inertiajs/react';
import type { ColumnDef } from '@tanstack/react-table';
import { Pencil, Plus } from 'lucide-react';
import { useMemo, useState } from 'react';
import VisitResultController from '@/actions/App/Http/Controllers/Orders/VisitResultController';
import { VisitResultDialog } from '@/components/orders/visit-result-dialog';
import { PageBody } from '@/components/organization/page-header';
import { RowActions } from '@/components/organization/row-actions';
import { StatusBadge } from '@/components/organization/status-badge';
import {
    recordCount,
    RegistryTable,
    searchFilter,
    statusFilter,
} from '@/components/registry-table';
import type { DataGridFeatures } from '@/components/reui/data-grid';
import { Button } from '@/components/ui/button';
import { useTableQuery } from '@/hooks/use-table-query';
import type { TableQueryState } from '@/hooks/use-table-query';
import { saveRowOrder } from '@/lib/submit';
import { index as visitResultsIndex } from '@/routes/visit-results';
import { index as referencesIndex } from '@/routes/references';
import type { Paginated, VisitResultRow } from '@/types';

type Props = {
    items: Paginated<VisitResultRow>;
    filters: TableQueryState;
    can: { manage: boolean };
};

const ONLY = ['items'];

export default function VisitResultsIndex({ items, filters, can }: Props) {
    const table = useTableQuery(visitResultsIndex.url(), filters, ONLY);
    const [dialog, setDialog] = useState<{
        open: boolean;
        item: VisitResultRow | null;
    }>({ open: false, item: null });

    const columns = useMemo<ColumnDef<DataGridFeatures, VisitResultRow>[]>(
        () => [
            {
                accessorKey: 'name',
                header: 'Название',
                size: 280,
                cell: ({ row }) => (
                    <div className="flex min-w-0 flex-col">
                        <span className="font-medium">{row.original.name}</span>
                        <span className="text-xs text-muted-foreground">
                            {row.original.code}
                        </span>
                    </div>
                ),
            },
            {
                accessorKey: 'orders_count',
                header: 'Заявок',
                size: 110,
            },
            {
                accessorKey: 'active',
                header: 'Статус',
                size: 110,
                cell: ({ row }) => <StatusBadge active={row.original.active} />,
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
                                url: VisitResultController.status.url(
                                    row.original.id,
                                ),
                                lockVersion: row.original.lock_version,
                                title: `Отключить «${row.original.name}»?`,
                                description:
                                    'Заявки сохранят этот итог визита, но выбрать его заново будет нельзя. Значение можно включить снова.',
                                activateLabel: 'Включить',
                                deactivateLabel: 'Отключить',
                            }}
                        />
                    ) : null,
            },
        ],
        [can.manage],
    );

    return (
        <>
            <Head title="Справочники заявок — Итоги визита" />
            <PageBody>
                <RegistryTable
                    title="Итоги визита"
                    summary={[
                        recordCount(items.meta.total, table.isFiltered, [
                            'значение',
                            'значения',
                            'значений',
                        ]),
                        'Общий справочник для всех салонов. Выбирается в заявке для статусов, требующих итог визита',
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
                            ? 'По заданным условиям значения не найдены.'
                            : 'Значений пока нет.'
                    }
                    onReorder={
                        can.manage
                            ? (ids) =>
                                  saveRowOrder(
                                      VisitResultController.reorder.url(),
                                      ids,
                                      ONLY,
                                  )
                            : undefined
                    }
                />
            </PageBody>

            <VisitResultDialog
                open={dialog.open}
                item={dialog.item}
                onOpenChange={(open) =>
                    setDialog((current) => ({ ...current, open }))
                }
            />
        </>
    );
}

VisitResultsIndex.layout = {
    breadcrumbs: [{ title: 'Справочники', href: referencesIndex() }],
};
