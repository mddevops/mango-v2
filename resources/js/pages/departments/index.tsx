import { Head } from '@inertiajs/react';
import type { ColumnDef } from '@tanstack/react-table';
import { Building2, Pencil, Plus, Shapes } from 'lucide-react';
import { useMemo, useState } from 'react';
import DepartmentController from '@/actions/App/Http/Controllers/Organization/DepartmentController';
import { DepartmentDialog } from '@/components/organization/department-dialog';
import { PageBody } from '@/components/organization/page-header';
import { RowActions } from '@/components/organization/row-actions';
import { StatusBadge } from '@/components/organization/status-badge';
import {
    recordCount,
    RegistryTable,
    searchFilter,
    statusFilter,
} from '@/components/registry-table';
import type { RegistryFilter } from '@/components/registry-table';
import { Badge } from '@/components/reui/badge';
import type { DataGridFeatures } from '@/components/reui/data-grid';
import { Button } from '@/components/ui/button';
import { useTableQuery } from '@/hooks/use-table-query';
import type { TableQueryState } from '@/hooks/use-table-query';
import { index as departmentsIndex } from '@/routes/departments';
import type { DepartmentKindOption, DepartmentRow, Paginated } from '@/types';

type Props = {
    departments: Paginated<DepartmentRow>;
    filters: TableQueryState;
    showrooms: { id: number; name: string; active: boolean }[];
    kinds: DepartmentKindOption[];
    can: { create: boolean; createShared: boolean };
};

const ONLY = ['departments'];

export default function DepartmentsIndex({
    departments,
    filters,
    showrooms,
    kinds,
    can,
}: Props) {
    const table = useTableQuery(departmentsIndex.url(), filters, ONLY);
    const [dialog, setDialog] = useState<{
        open: boolean;
        department: DepartmentRow | null;
    }>({
        open: false,
        department: null,
    });

    const canCreateAny =
        can.create &&
        (can.createShared || showrooms.some((showroom) => showroom.active));

    const showroomOptions = useMemo(
        () =>
            showrooms.map((showroom) => ({
                value: String(showroom.id),
                label: showroom.name,
            })),
        [showrooms],
    );

    const filterFields = useMemo<RegistryFilter[]>(
        () => [
            searchFilter('код или название…'),
            {
                key: 'showroom',
                label: 'Салон',
                icon: Building2,
                type: 'select',
                options: showroomOptions,
                // «пусто» — общие отделы без салона
                emptiable: true,
            },
            {
                key: 'kind',
                label: 'Тип',
                icon: Shapes,
                type: 'select',
                options: kinds,
            },
            statusFilter,
        ],
        [showroomOptions, kinds],
    );

    const columns = useMemo<ColumnDef<DataGridFeatures, DepartmentRow>[]>(
        () => [
            {
                accessorKey: 'name',
                header: 'Отдел',
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
                accessorKey: 'kind_label',
                header: 'Тип',
                size: 150,
            },
            {
                id: 'showroom',
                header: 'Салон',
                size: 200,
                cell: ({ row }) =>
                    row.original.showroom ? (
                        row.original.showroom.name
                    ) : (
                        <Badge variant="info-light">Общий</Badge>
                    ),
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
                                            department: row.original,
                                        }),
                                },
                            ]}
                            status={{
                                active: row.original.active,
                                url: DepartmentController.status.url(
                                    row.original.id,
                                ),
                                lockVersion: row.original.lock_version,
                                title: `Деактивировать отдел «${row.original.name}»?`,
                                description:
                                    'Отдел останется в истории, но в него нельзя будет назначать новых сотрудников. Его можно активировать снова.',
                                deactivateLabel: 'Деактивировать',
                            }}
                        />
                    ) : null,
            },
        ],
        [],
    );

    return (
        <>
            <Head title="Отделы" />
            <PageBody>
                <RegistryTable
                    title="Отделы"
                    summary={[
                        recordCount(departments.meta.total, table.isFiltered, [
                            'отдел',
                            'отдела',
                            'отделов',
                        ]),
                        'Отделы салонов и общие отделы, например колл-центр',
                    ]}
                    filters={{
                        fields: filterFields,
                        conditions: table.query.filter,
                        onChange: table.setConditions,
                    }}
                    action={
                        canCreateAny && (
                            <Button
                                type="button"
                                onClick={() =>
                                    setDialog({ open: true, department: null })
                                }
                            >
                                <Plus />
                                Добавить отдел
                            </Button>
                        )
                    }
                    rows={departments}
                    columns={columns}
                    isLoading={table.isLoading}
                    failed={table.failed}
                    onPageChange={table.goToPage}
                    onRetry={table.reload}
                    emptyMessage={
                        table.isFiltered
                            ? 'По заданным условиям отделы не найдены.'
                            : 'Отделов пока нет.'
                    }
                />
            </PageBody>

            <DepartmentDialog
                open={dialog.open}
                department={dialog.department}
                showrooms={showrooms}
                kinds={kinds}
                canCreateShared={can.createShared}
                onOpenChange={(open) =>
                    setDialog((current) => ({ ...current, open }))
                }
            />
        </>
    );
}

DepartmentsIndex.layout = {
    breadcrumbs: [{ title: 'Отделы', href: departmentsIndex() }],
};
