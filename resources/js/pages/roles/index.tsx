import { Head, Link } from '@inertiajs/react';
import type { ColumnDef } from '@tanstack/react-table';
import { Lock, Pencil, Plus } from 'lucide-react';
import { useMemo } from 'react';
import { PageBody } from '@/components/organization/page-header';
import { RowActions } from '@/components/organization/row-actions';
import { RegistryTable } from '@/components/registry-table';
import { Badge } from '@/components/reui/badge';
import type { DataGridFeatures } from '@/components/reui/data-grid';
import { Button } from '@/components/ui/button';
import { plural } from '@/lib/utils';
import { create, edit, index as rolesIndex } from '@/routes/roles';
import type { Paginated, RoleRow } from '@/types';

type Props = {
    roles: RoleRow[];
    permissionsTotal: number;
    can: { create: boolean };
};

export default function RolesIndex({ roles, permissionsTotal, can }: Props) {
    const rows = useMemo<Paginated<RoleRow>>(
        () => ({
            data: roles,
            meta: {
                current_page: 1,
                per_page: Math.max(roles.length, 1),
                total: roles.length,
                last_page: 1,
            },
        }),
        [roles],
    );

    const columns = useMemo<ColumnDef<DataGridFeatures, RoleRow>[]>(
        () => [
            {
                accessorKey: 'label',
                header: 'Роль',
                size: 260,
                cell: ({ row }) => (
                    <div className="flex flex-col">
                        <span className="flex items-center gap-2 font-medium">
                            {row.original.label}
                            {row.original.is_system && (
                                <Badge variant="warning-light">
                                    <Lock />
                                    Системная
                                </Badge>
                            )}
                        </span>
                        <span className="text-xs text-muted-foreground">
                            {row.original.name}
                        </span>
                    </div>
                ),
            },
            {
                accessorKey: 'permissions_count',
                header: 'Права',
                size: 140,
                cell: ({ row }) =>
                    `${row.original.permissions_count} из ${permissionsTotal}`,
            },
            {
                accessorKey: 'users_count',
                header: 'Сотрудников',
                size: 140,
            },
            {
                id: 'actions',
                header: () => <span className="sr-only">Действия</span>,
                size: 60,
                cell: ({ row }) =>
                    row.original.can.update ? (
                        <RowActions
                            label={`Действия: ${row.original.label}`}
                            actions={[
                                {
                                    label: 'Изменить',
                                    icon: Pencil,
                                    href: edit.url(row.original.id),
                                },
                            ]}
                        />
                    ) : null,
            },
        ],
        [permissionsTotal],
    );

    return (
        <>
            <Head title="Роли и права" />
            <PageBody>
                <RegistryTable
                    title="Роли и права"
                    summary={[
                        plural(roles.length, ['роль', 'роли', 'ролей']),
                        'Роль задаёт набор действий. Доступ к данным определяется салонами и отделами сотрудника',
                    ]}
                    action={
                        can.create && (
                            <Button asChild>
                                <Link href={create()}>
                                    <Plus />
                                    Добавить роль
                                </Link>
                            </Button>
                        )
                    }
                    rows={rows}
                    columns={columns}
                    isLoading={false}
                    failed={false}
                    onPageChange={() => undefined}
                    onRetry={() => undefined}
                    emptyMessage="Ролей пока нет. Запустите сидер ролей."
                    paginate={false}
                />
            </PageBody>
        </>
    );
}

RolesIndex.layout = {
    breadcrumbs: [{ title: 'Роли и права', href: rolesIndex() }],
};
