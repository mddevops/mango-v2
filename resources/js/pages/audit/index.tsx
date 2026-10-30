import { Head } from '@inertiajs/react';
import type { ColumnDef } from '@tanstack/react-table';
import { Box } from 'lucide-react';
import { useMemo } from 'react';
import { PageBody } from '@/components/organization/page-header';
import {
    recordCount,
    RegistryTable,
    searchFilter,
} from '@/components/registry-table';
import type { RegistryFilter } from '@/components/registry-table';
import { Badge } from '@/components/reui/badge';
import type { DataGridFeatures } from '@/components/reui/data-grid';
import { useTableQuery } from '@/hooks/use-table-query';
import type { TableQueryState } from '@/hooks/use-table-query';
import { index as auditIndex } from '@/routes/audit';
import type { AuditEntry, Option, Paginated } from '@/types';

type Props = {
    entries: Paginated<AuditEntry>;
    filters: TableQueryState;
    subjects: Option[];
};

const ONLY = ['entries'];

const dateFormat = new Intl.DateTimeFormat('ru-RU', {
    dateStyle: 'short',
    timeStyle: 'medium',
});

export default function AuditIndex({ entries, filters, subjects }: Props) {
    const table = useTableQuery(auditIndex.url(), filters, ONLY);

    const filterFields = useMemo<RegistryFilter[]>(
        () => [
            searchFilter('объект, автор, описание…'),
            {
                key: 'subject',
                label: 'Объект',
                icon: Box,
                type: 'select',
                options: subjects,
            },
        ],
        [subjects],
    );

    const columns = useMemo<ColumnDef<DataGridFeatures, AuditEntry>[]>(
        () => [
            {
                accessorKey: 'created_at',
                header: 'Когда',
                size: 170,
                cell: ({ row }) => (
                    <time
                        dateTime={row.original.created_at}
                        className="tabular-nums"
                    >
                        {dateFormat.format(new Date(row.original.created_at))}
                    </time>
                ),
            },
            {
                accessorKey: 'actor',
                header: 'Кто',
                size: 180,
                cell: ({ row }) => row.original.actor ?? '—',
            },
            {
                id: 'subject',
                header: 'Объект',
                size: 220,
                cell: ({ row }) => (
                    <div className="flex flex-col items-start gap-1">
                        <Badge variant="outline">
                            {row.original.subject_type}
                        </Badge>
                        <span>{row.original.subject ?? '—'}</span>
                    </div>
                ),
            },
            {
                accessorKey: 'description',
                header: 'Что произошло',
                size: 380,
                cell: ({ row }) => (
                    <span className="whitespace-normal">
                        {row.original.description}
                    </span>
                ),
            },
        ],
        [],
    );

    return (
        <>
            <Head title="Аудит" />
            <PageBody>
                <RegistryTable
                    title="Аудит"
                    summary={[
                        recordCount(entries.meta.total, table.isFiltered, [
                            'запись',
                            'записи',
                            'записей',
                        ]),
                        'Журнал изменений структуры, сотрудников и прав. Показаны только записи по доступным вам салонам',
                    ]}
                    filters={{
                        fields: filterFields,
                        conditions: table.query.filter,
                        onChange: table.setConditions,
                    }}
                    rows={entries}
                    columns={columns}
                    isLoading={table.isLoading}
                    failed={table.failed}
                    onPageChange={table.goToPage}
                    onRetry={table.reload}
                    emptyMessage={
                        table.isFiltered
                            ? 'Записи не найдены.'
                            : 'Журнал пока пуст.'
                    }
                />
            </PageBody>
        </>
    );
}

AuditIndex.layout = {
    breadcrumbs: [{ title: 'Аудит', href: auditIndex() }],
};
