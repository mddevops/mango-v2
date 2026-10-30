import { Head } from '@inertiajs/react';
import { Pencil, Plus } from 'lucide-react';
import { useMemo, useState } from 'react';
import ShowroomController from '@/actions/App/Http/Controllers/Organization/ShowroomController';
import { PageBody } from '@/components/organization/page-header';
import { RowActions } from '@/components/organization/row-actions';
import { ShowroomDialog } from '@/components/organization/showroom-dialog';
import { StatusBadge } from '@/components/organization/status-badge';
import type { DataGridFeatures } from '@/components/reui/data-grid';
import {
    recordCount,
    RegistryTable,
    searchFilter,
    statusFilter,
} from '@/components/registry-table';
import type { RegistryFilter } from '@/components/registry-table';
import { Button } from '@/components/ui/button';
import { useTableQuery } from '@/hooks/use-table-query';
import type { TableQueryState } from '@/hooks/use-table-query';
import { index as showroomsIndex } from '@/routes/showrooms';
import type { Option, Paginated, ShowroomRow } from '@/types';
import type { ColumnDef } from '@tanstack/react-table';

type Props = {
    showrooms: Paginated<ShowroomRow>;
    filters: TableQueryState;
    timezones: Option[];
    can: { create: boolean };
};

const ONLY = ['showrooms'];

const FILTERS: RegistryFilter[] = [
    searchFilter('код, название, адрес…'),
    statusFilter,
];

export default function ShowroomsIndex({
    showrooms,
    filters,
    timezones,
    can,
}: Props) {
    const table = useTableQuery(showroomsIndex.url(), filters, ONLY);
    const [dialog, setDialog] = useState<{
        open: boolean;
        showroom: ShowroomRow | null;
    }>({
        open: false,
        showroom: null,
    });

    const timezoneLabels = useMemo(
        () => new Map(timezones.map((zone) => [zone.value, zone.label])),
        [timezones],
    );

    const columns = useMemo<ColumnDef<DataGridFeatures, ShowroomRow>[]>(
        () => [
            {
                accessorKey: 'name',
                header: 'Салон',
                size: 220,
                cell: ({ row }) => (
                    <div className="flex flex-col">
                        <span className="font-medium">{row.original.name}</span>
                        <span className="text-xs text-muted-foreground">
                            {row.original.code}
                        </span>
                    </div>
                ),
            },
            {
                accessorKey: 'address',
                header: 'Адрес',
                size: 240,
                cell: ({ row }) => row.original.address ?? '—',
            },
            {
                accessorKey: 'phone',
                header: 'Телефон',
                size: 150,
                cell: ({ row }) => row.original.phone ?? '—',
            },
            {
                accessorKey: 'timezone',
                header: 'Часовой пояс',
                size: 200,
                cell: ({ row }) =>
                    timezoneLabels.get(row.original.timezone) ??
                    row.original.timezone,
            },
            {
                id: 'business_day',
                header: 'Расчётный день',
                size: 150,
                cell: ({ row }) =>
                    row.original.business_day_boundary_enabled
                        ? `Граница ${row.original.business_day_boundary}`
                        : 'Календарный',
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
                    row.original.can.update ? (
                        <RowActions
                            label={`Действия: ${row.original.name}`}
                            actions={[
                                {
                                    label: 'Изменить',
                                    icon: Pencil,
                                    onSelect: () =>
                                        setDialog({
                                            open: true,
                                            showroom: row.original,
                                        }),
                                },
                            ]}
                            status={{
                                active: row.original.active,
                                url: ShowroomController.status.url(
                                    row.original.id,
                                ),
                                lockVersion: row.original.lock_version,
                                title: `Деактивировать салон «${row.original.name}»?`,
                                description:
                                    'Салон останется в истории, но в него нельзя будет назначать сотрудников и создавать отделы. Его можно активировать снова.',
                                deactivateLabel: 'Деактивировать',
                            }}
                        />
                    ) : null,
            },
        ],
        [timezoneLabels],
    );

    return (
        <>
            <Head title="Автосалоны" />
            <PageBody>
                <RegistryTable
                    title="Автосалоны"
                    summary={[
                        recordCount(showrooms.meta.total, table.isFiltered, [
                            'салон',
                            'салона',
                            'салонов',
                        ]),
                        'Салоны с историей не удаляются — их можно деактивировать',
                    ]}
                    filters={{
                        fields: FILTERS,
                        conditions: table.query.filter,
                        onChange: table.setConditions,
                    }}
                    action={
                        can.create && (
                            <Button
                                type="button"
                                onClick={() =>
                                    setDialog({ open: true, showroom: null })
                                }
                            >
                                <Plus />
                                Добавить салон
                            </Button>
                        )
                    }
                    rows={showrooms}
                    columns={columns}
                    isLoading={table.isLoading}
                    failed={table.failed}
                    onPageChange={table.goToPage}
                    onRetry={table.reload}
                    emptyMessage={
                        table.isFiltered
                            ? 'По заданным условиям салоны не найдены.'
                            : 'Салонов пока нет.'
                    }
                />
            </PageBody>

            <ShowroomDialog
                open={dialog.open}
                showroom={dialog.showroom}
                timezones={timezones}
                onOpenChange={(open) =>
                    setDialog((current) => ({ ...current, open }))
                }
            />
        </>
    );
}

ShowroomsIndex.layout = {
    breadcrumbs: [{ title: 'Автосалоны', href: showroomsIndex() }],
};
