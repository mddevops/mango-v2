import { Head, router } from '@inertiajs/react';
import type { ColumnDef } from '@tanstack/react-table';
import { Building2, Pencil } from 'lucide-react';
import { useMemo, useState } from 'react';
import {
    ScheduleDialog,
    WEEKDAYS,
} from '@/components/distribution/schedule-dialog';
import { PageBody } from '@/components/organization/page-header';
import { RowActions } from '@/components/organization/row-actions';
import {
    recordCount,
    RegistryTable,
    searchFilter,
} from '@/components/registry-table';
import type { RegistryFilter } from '@/components/registry-table';
import { Badge } from '@/components/reui/badge';
import type { DataGridFeatures } from '@/components/reui/data-grid';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useTableQuery } from '@/hooks/use-table-query';
import type { TableQueryState } from '@/hooks/use-table-query';
import { formatBusinessDate } from '@/lib/order-format';
import { schedules as schedulesIndex } from '@/routes/distribution';
import type {
    DistributionMemberRow,
    Paginated,
    ReferenceOption,
} from '@/types';

type Props = {
    members: Paginated<DistributionMemberRow> | null;
    filters: TableQueryState;
    showrooms: ReferenceOption[];
    showroom: number | null;
    can: { manage: boolean };
};

const ONLY = ['members'];

const FILTER_FIELDS: RegistryFilter[] = [searchFilter('имя сотрудника…')];

export default function DistributionSchedules({
    members,
    filters,
    showrooms,
    showroom,
    can,
}: Props) {
    const table = useTableQuery(
        schedulesIndex.url({ query: { showroom: showroom ?? undefined } }),
        filters,
        ONLY,
    );
    const [dialog, setDialog] = useState<{
        open: boolean;
        member: DistributionMemberRow | null;
    }>({ open: false, member: null });
    const current = showrooms.find((option) => option.id === showroom) ?? null;
    const selectShowroom = (value: string) =>
        router.get(schedulesIndex.url({ query: { showroom: value } }));

    const columns = useMemo<
        ColumnDef<DataGridFeatures, DistributionMemberRow>[]
    >(
        () => [
            {
                accessorKey: 'name',
                header: 'Сотрудник',
                size: 220,
                cell: ({ row }) => (
                    <div className="flex flex-col items-start gap-1">
                        <span>{row.original.name}</span>
                        {!row.original.active && (
                            <Badge variant="destructive-light">Отключён</Badge>
                        )}
                    </div>
                ),
            },
            {
                accessorKey: 'participates',
                header: 'Участие',
                size: 130,
                cell: ({ row }) =>
                    row.original.participates ? (
                        <Badge variant="success-light">Участвует</Badge>
                    ) : (
                        <Badge variant="outline">Не участвует</Badge>
                    ),
            },
            {
                id: 'weekdays',
                header: 'Рабочие дни',
                size: 250,
                cell: ({ row }) => (
                    <div className="flex gap-1">
                        {WEEKDAYS.map((day, index) => (
                            <Badge
                                key={day}
                                variant={
                                    row.original.weekdays[index]
                                        ? 'primary-light'
                                        : 'outline'
                                }
                                className={
                                    row.original.weekdays[index]
                                        ? undefined
                                        : 'text-muted-foreground'
                                }
                            >
                                {day}
                            </Badge>
                        ))}
                    </div>
                ),
            },
            {
                id: 'exceptions',
                header: 'Исключения',
                size: 220,
                cell: ({ row }) =>
                    row.original.exceptions.length > 0 ? (
                        <div className="flex flex-wrap gap-1">
                            {row.original.exceptions.map((exception) => (
                                <Badge
                                    key={exception.date}
                                    variant={
                                        exception.works
                                            ? 'success-light'
                                            : 'warning-light'
                                    }
                                >
                                    {formatBusinessDate(exception.date)}:{' '}
                                    {exception.works ? 'работает' : 'выходной'}
                                </Badge>
                            ))}
                        </div>
                    ) : (
                        <span className="text-xs text-muted-foreground">—</span>
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
                                    label: 'Изменить участие и график',
                                    icon: Pencil,
                                    onSelect: () =>
                                        setDialog({
                                            open: true,
                                            member: row.original,
                                        }),
                                },
                            ]}
                        />
                    ) : null,
            },
        ],
        [can.manage],
    );

    return (
        <>
            <Head title="Распределение — Графики и участие" />
            <PageBody>
                {showrooms.length > 1 && (
                    <div className="flex items-center gap-2">
                        <Building2 className="size-4 text-muted-foreground" />
                        <Select
                            value={showroom === null ? '' : String(showroom)}
                            onValueChange={selectShowroom}
                        >
                            <SelectTrigger
                                className="w-72"
                                aria-label="Салон"
                            >
                                <SelectValue placeholder="Выберите салон" />
                            </SelectTrigger>
                            <SelectContent position="popper">
                                <SelectGroup>
                                    {showrooms.map((option) => (
                                        <SelectItem
                                            key={option.id}
                                            value={String(option.id)}
                                        >
                                            {option.active
                                                ? option.name
                                                : `${option.name} (отключён)`}
                                        </SelectItem>
                                    ))}
                                </SelectGroup>
                            </SelectContent>
                        </Select>
                    </div>
                )}
                {members === null || current === null ? (
                    <p className="text-sm text-muted-foreground">
                        Нет доступных салонов.
                    </p>
                ) : (
                <RegistryTable
                    title={`Графики и участие · ${current.name}`}
                    summary={[
                        recordCount(members.meta.total, table.isFiltered, [
                            'сотрудник',
                            'сотрудника',
                            'сотрудников',
                        ]),
                        'Сотрудники салона с правом просмотра заявок. Обратные звонки салона получают только его участники, работающие по графику этого салона в день заявки',
                    ]}
                    filters={{
                        fields: FILTER_FIELDS,
                        conditions: table.query.filter,
                        onChange: table.setConditions,
                    }}
                    rows={members}
                    columns={columns}
                    isLoading={table.isLoading}
                    failed={table.failed}
                    onPageChange={table.goToPage}
                    onRetry={table.reload}
                    emptyMessage={
                        table.isFiltered
                            ? 'Сотрудники не найдены.'
                            : 'Нет сотрудников салона с правом просмотра заявок.'
                    }
                />
                )}
            </PageBody>

            <ScheduleDialog
                open={dialog.open}
                member={dialog.member}
                showroom={current}
                onOpenChange={(open) =>
                    setDialog((current) => ({ ...current, open }))
                }
            />
        </>
    );
}

DistributionSchedules.layout = {
    breadcrumbs: [{ title: 'Графики распределения', href: schedulesIndex() }],
};
