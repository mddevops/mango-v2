import { Head, Link } from '@inertiajs/react';
import type { ColumnDef } from '@tanstack/react-table';
import { Building2, Eye, Pencil, Plus, ShieldCheck } from 'lucide-react';
import { useMemo } from 'react';
import UserController from '@/actions/App/Http/Controllers/Organization/UserController';
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
import { create, edit, index as usersIndex } from '@/routes/users';
import type { Paginated, UserRow } from '@/types';

type Props = {
    users: Paginated<UserRow>;
    filters: TableQueryState;
    showrooms: { id: number; name: string }[];
    roles: { id: number; label: string }[];
    can: { create: boolean };
};

const ONLY = ['users'];

function TagList({ items, empty }: { items: string[]; empty: string }) {
    if (items.length === 0) {
        return <span className="text-muted-foreground">{empty}</span>;
    }

    return (
        <div className="flex flex-wrap gap-1">
            {items.map((item) => (
                <Badge key={item} variant="outline">
                    {item}
                </Badge>
            ))}
        </div>
    );
}

export default function UsersIndex({
    users,
    filters,
    showrooms,
    roles,
    can,
}: Props) {
    const table = useTableQuery(usersIndex.url(), filters, ONLY);

    const filterFields = useMemo<RegistryFilter[]>(
        () => [
            searchFilter('имя или email…'),
            {
                key: 'showroom',
                label: 'Салон',
                icon: Building2,
                type: 'select',
                options: showrooms.map((showroom) => ({
                    value: String(showroom.id),
                    label: showroom.name,
                })),
            },
            {
                key: 'role',
                label: 'Роль',
                icon: ShieldCheck,
                type: 'select',
                options: roles.map((role) => ({
                    value: String(role.id),
                    label: role.label,
                })),
                emptiable: true,
            },
            statusFilter,
        ],
        [showrooms, roles],
    );

    const columns = useMemo<ColumnDef<DataGridFeatures, UserRow>[]>(
        () => [
            {
                accessorKey: 'name',
                header: 'Сотрудник',
                size: 240,
                cell: ({ row }) => (
                    <div className="flex flex-col">
                        <span className="font-medium">
                            {row.original.name}
                            {row.original.is_self && (
                                <span className="text-muted-foreground">
                                    {' '}
                                    (вы)
                                </span>
                            )}
                        </span>
                        <span className="text-xs text-muted-foreground">
                            {row.original.email}
                        </span>
                    </div>
                ),
            },
            {
                id: 'roles',
                header: 'Роли',
                size: 220,
                cell: ({ row }) => (
                    <TagList items={row.original.roles} empty="Без ролей" />
                ),
            },
            {
                id: 'showrooms',
                header: 'Салоны',
                size: 220,
                cell: ({ row }) =>
                    row.original.has_global_access ? (
                        <Badge variant="primary-light">Все салоны</Badge>
                    ) : (
                        <TagList
                            items={row.original.showrooms}
                            empty="Нет доступа"
                        />
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
                cell: ({ row }) => (
                    <RowActions
                        label={`Действия: ${row.original.name}`}
                        actions={[
                            {
                                label: row.original.can.update
                                    ? 'Изменить'
                                    : 'Открыть',
                                icon: row.original.can.update ? Pencil : Eye,
                                href: edit.url(row.original.id),
                            },
                        ]}
                        status={
                            row.original.can.changeStatus
                                ? {
                                      active: row.original.active,
                                      url: UserController.status.url(
                                          row.original.id,
                                      ),
                                      lockVersion: row.original.lock_version,
                                      title: `Отключить сотрудника «${row.original.name}»?`,
                                      description:
                                          'Сотрудник не сможет войти, его открытые сессии завершатся при следующем запросе. Учётную запись можно включить снова.',
                                  }
                                : undefined
                        }
                    />
                ),
            },
        ],
        [],
    );

    return (
        <>
            <Head title="Сотрудники" />
            <PageBody>
                <RegistryTable
                    title="Сотрудники"
                    summary={[
                        recordCount(users.meta.total, table.isFiltered, [
                            'сотрудник',
                            'сотрудника',
                            'сотрудников',
                        ]),
                        'Учётные записи, роли и доступ к салонам',
                    ]}
                    filters={{
                        fields: filterFields,
                        conditions: table.query.filter,
                        onChange: table.setConditions,
                    }}
                    action={
                        can.create && (
                            <Button asChild>
                                <Link href={create()}>
                                    <Plus />
                                    Добавить сотрудника
                                </Link>
                            </Button>
                        )
                    }
                    rows={users}
                    columns={columns}
                    isLoading={table.isLoading}
                    failed={table.failed}
                    onPageChange={table.goToPage}
                    onRetry={table.reload}
                    emptyMessage={
                        table.isFiltered
                            ? 'По заданным условиям сотрудники не найдены.'
                            : 'Сотрудников пока нет.'
                    }
                />
            </PageBody>
        </>
    );
}

UsersIndex.layout = {
    breadcrumbs: [{ title: 'Сотрудники', href: usersIndex() }],
};
