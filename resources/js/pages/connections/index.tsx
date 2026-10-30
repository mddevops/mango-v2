import { Head } from '@inertiajs/react';
import type { ColumnDef } from '@tanstack/react-table';
import { PhoneIncoming, Pencil, Plus } from 'lucide-react';
import { useMemo, useState } from 'react';
import IntegrationConnectionController from '@/actions/App/Http/Controllers/Sources/IntegrationConnectionController';
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
import { Alert, AlertDescription, AlertTitle } from '@/components/reui/alert';
import { Badge } from '@/components/reui/badge';
import type { DataGridFeatures } from '@/components/reui/data-grid';
import { ConnectionDialog } from '@/components/sources/connection-dialog';
import { MangoIntakeDialog } from '@/components/sources/mango-intake-dialog';
import { Button } from '@/components/ui/button';
import { useTableQuery } from '@/hooks/use-table-query';
import type { TableQueryState } from '@/hooks/use-table-query';
import { index as connectionsIndex } from '@/routes/connections';
import type { ConnectionRow, Paginated, ReferenceOption } from '@/types';

type Props = {
    connections: Paginated<ConnectionRow>;
    filters: TableQueryState;
    showrooms: ReferenceOption[];
    secretFields: { key: string; label: string }[];
    can: { manageSecrets: boolean };
};

const ONLY = ['connections'];

const FILTERS: RegistryFilter[] = [searchFilter('название…'), statusFilter];

export default function ConnectionsIndex({
    connections,
    filters,
    showrooms,
    secretFields,
    can,
}: Props) {
    const table = useTableQuery(connectionsIndex.url(), filters, ONLY);
    const [dialog, setDialog] = useState<{
        open: boolean;
        connection: ConnectionRow | null;
    }>({ open: false, connection: null });
    const [intake, setIntake] = useState<{
        open: boolean;
        connection: ConnectionRow | null;
    }>({ open: false, connection: null });

    const columns = useMemo<ColumnDef<DataGridFeatures, ConnectionRow>[]>(
        () => [
            {
                accessorKey: 'name',
                header: 'Подключение',
                size: 220,
                cell: ({ row }) => (
                    <div className="flex flex-col">
                        <span className="font-medium">{row.original.name}</span>
                        <span className="text-xs text-muted-foreground">
                            {row.original.provider_label}
                        </span>
                    </div>
                ),
            },
            {
                id: 'showrooms',
                header: 'Салоны',
                size: 220,
                cell: ({ row }) => (
                    <span className="text-sm">
                        {row.original.showrooms
                            .map((showroom) => showroom.name)
                            .join(', ')}
                    </span>
                ),
            },
            {
                id: 'secrets',
                header: 'Ключи',
                size: 180,
                cell: ({ row }) => (
                    <div className="flex flex-wrap gap-1">
                        {secretFields.map((field) => (
                            <Badge
                                key={field.key}
                                variant={
                                    row.original.secrets[field.key]
                                        ? 'success-light'
                                        : 'secondary'
                                }
                            >
                                {field.label}:{' '}
                                {row.original.secrets[field.key]
                                    ? 'задан'
                                    : 'не задан'}
                            </Badge>
                        ))}
                    </div>
                ),
            },
            {
                accessorKey: 'sources_count',
                header: 'Источников',
                size: 110,
            },
            {
                id: 'verification',
                header: 'Проверка',
                size: 130,
                cell: () => <Badge variant="warning-light">Не проверено</Badge>,
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
                cell: ({ row }) => (
                    <RowActions
                        label={`Действия: ${row.original.name}`}
                        actions={[
                            {
                                label: 'Изменить',
                                icon: Pencil,
                                onSelect: () =>
                                    setDialog({
                                        open: true,
                                        connection: row.original,
                                    }),
                            },
                            {
                                label: 'Приём событий',
                                icon: PhoneIncoming,
                                onSelect: () =>
                                    setIntake({
                                        open: true,
                                        connection: row.original,
                                    }),
                            },
                        ]}
                        status={{
                            active: row.original.active,
                            url: IntegrationConnectionController.status.url(
                                row.original.id,
                            ),
                            lockVersion: row.original.lock_version,
                            title: `Отключить подключение «${row.original.name}»?`,
                            description:
                                'Привязки источников и линии сохранятся. Новые привязки к отключённому подключению создать будет нельзя.',
                            activateLabel: 'Включить',
                            deactivateLabel: 'Отключить',
                        }}
                    />
                ),
            },
        ],
        [secretFields],
    );

    return (
        <>
            <Head title="Подключения" />
            <PageBody>
                <Alert variant="warning">
                    <AlertTitle>Принимаются только события звонков</AlertTitle>
                    <AlertDescription>
                        CRM принимает события звонков от Mango VPBX по адресу из
                        «Приёма событий», но сама к Mango не обращается: записи
                        разговоров, уведомления и звонки из CRM будут сделаны
                        позже. Работа с реальной АТС не проверена. Приём заявок
                        с сайта включается в карточке источника. Mango
                        Calltracking и Calltouch отложены и здесь не
                        настраиваются.
                    </AlertDescription>
                </Alert>
                <RegistryTable
                    title="Подключения Mango VPBX"
                    summary={[
                        recordCount(connections.meta.total, table.isFiltered, [
                            'подключение',
                            'подключения',
                            'подключений',
                        ]),
                        'Учётные записи виртуальной АТС. Одна учётная запись может обслуживать несколько источников и салонов',
                    ]}
                    filters={{
                        fields: FILTERS,
                        conditions: table.query.filter,
                        onChange: table.setConditions,
                    }}
                    action={
                        <Button
                            type="button"
                            onClick={() =>
                                setDialog({ open: true, connection: null })
                            }
                        >
                            <Plus />
                            Добавить подключение
                        </Button>
                    }
                    rows={connections}
                    columns={columns}
                    isLoading={table.isLoading}
                    failed={table.failed}
                    onPageChange={table.goToPage}
                    onRetry={table.reload}
                    emptyMessage={
                        table.isFiltered
                            ? 'По заданным условиям подключения не найдены.'
                            : 'Подключений пока нет.'
                    }
                />
            </PageBody>

            <ConnectionDialog
                open={dialog.open}
                connection={dialog.connection}
                showrooms={showrooms}
                secretFields={secretFields}
                canManageSecrets={can.manageSecrets}
                onOpenChange={(open) =>
                    setDialog((current) => ({ ...current, open }))
                }
            />
            <MangoIntakeDialog
                open={intake.open}
                connection={intake.connection}
                onOpenChange={(open) =>
                    setIntake((current) => ({ ...current, open }))
                }
            />
        </>
    );
}

ConnectionsIndex.layout = {
    breadcrumbs: [{ title: 'Подключения', href: connectionsIndex() }],
};
