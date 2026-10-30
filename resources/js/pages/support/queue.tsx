import { Head, Link } from '@inertiajs/react';
import type { ColumnDef } from '@tanstack/react-table';
import { Mail } from 'lucide-react';
import { useMemo } from 'react';
import { PageBody } from '@/components/organization/page-header';
import {
    recordCount,
    RegistryTable,
    searchFilter,
} from '@/components/registry-table';
import type { RegistryFilter } from '@/components/registry-table';
import type { DataGridFeatures } from '@/components/reui/data-grid';
import { SupportDeleteTicket } from '@/components/support/support-delete-ticket';
import {
    SupportTicketIcon,
    supportStateLabel,
} from '@/components/support/support-ticket-icon';
import { useTableQuery } from '@/hooks/use-table-query';
import { formatMoment } from '@/lib/order-format';
import { index as supportIndex, show as showTicket } from '@/routes/support';
import type { SupportQueuePage, SupportQueueRow } from '@/types/support';

const ONLY = ['tickets'];

const FILTERS: RegistryFilter[] = [
    searchFilter('тема, автор или ID…'),
    {
        key: 'state',
        label: 'Состояние',
        icon: Mail,
        type: 'select',
        multiple: false,
        options: (['unread', 'sent', 'read'] as const).map((state) => ({
            value: state,
            label: supportStateLabel(state),
        })),
    },
];

/** Every employee's request for the support staff: newest activity first. */
export default function SupportQueue({ tickets, filters }: SupportQueuePage) {
    const table = useTableQuery(supportIndex.url(), filters, ONLY);

    const columns = useMemo<ColumnDef<DataGridFeatures, SupportQueueRow>[]>(
        () => [
            {
                accessorKey: 'subject',
                header: 'Тема',
                size: 360,
                cell: ({ row }) => (
                    <div className="flex min-w-0 items-center gap-2">
                        <SupportTicketIcon state={row.original.state} />
                        <Link
                            href={showTicket(row.original.id)}
                            className={
                                row.original.state === 'unread'
                                    ? 'truncate font-semibold text-primary hover:underline'
                                    : 'truncate font-medium text-primary hover:underline'
                            }
                        >
                            {row.original.subject}
                        </Link>
                    </div>
                ),
            },
            {
                accessorKey: 'id',
                header: 'ID',
                size: 80,
                cell: ({ row }) => (
                    <span className="tabular-nums">{row.original.id}</span>
                ),
            },
            {
                accessorKey: 'author',
                header: 'Автор',
                size: 200,
            },
            {
                accessorKey: 'last_message_at',
                header: 'Последнее сообщение',
                size: 170,
                cell: ({ row }) => (
                    <time
                        dateTime={row.original.last_message_at}
                        className="tabular-nums"
                    >
                        {formatMoment(row.original.last_message_at)}
                    </time>
                ),
            },
            {
                accessorKey: 'created_at',
                header: 'Создано',
                size: 170,
                cell: ({ row }) => (
                    <time
                        dateTime={row.original.created_at}
                        className="tabular-nums"
                    >
                        {formatMoment(row.original.created_at)}
                    </time>
                ),
            },
            {
                id: 'actions',
                header: () => <span className="sr-only">Действия</span>,
                size: 60,
                cell: ({ row }) => (
                    <SupportDeleteTicket ticket={row.original} only={ONLY} />
                ),
            },
        ],
        [],
    );

    return (
        <>
            <Head title="Обращения в поддержку" />
            <PageBody>
                <RegistryTable
                    title="Обращения в поддержку"
                    summary={[
                        recordCount(tickets.meta.total, table.isFiltered, [
                            'обращение',
                            'обращения',
                            'обращений',
                        ]),
                        'Обращения сотрудников: откройте тему, чтобы прочитать переписку и ответить',
                    ]}
                    filters={{
                        fields: FILTERS,
                        conditions: table.query.filter,
                        onChange: table.setConditions,
                    }}
                    rows={tickets}
                    columns={columns}
                    isLoading={table.isLoading}
                    failed={table.failed}
                    onPageChange={table.goToPage}
                    onRetry={table.reload}
                    emptyMessage={
                        table.isFiltered
                            ? 'Обращения не найдены.'
                            : 'Обращений пока нет.'
                    }
                />
            </PageBody>
        </>
    );
}

SupportQueue.layout = {
    breadcrumbs: [{ title: 'Поддержка', href: supportIndex() }],
};
