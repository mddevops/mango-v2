import { Head, Link } from '@inertiajs/react';
import { Building2, Users, UsersRound } from 'lucide-react';
import { FormSection } from '@/components/organization/form-section';
import { PageBody, PageHeader } from '@/components/organization/page-header';
import { Badge } from '@/components/reui/badge';
import { Button } from '@/components/ui/button';
import { usePermissions } from '@/hooks/use-permissions';
import { dashboard } from '@/routes';
import { index as departmentsIndex } from '@/routes/departments';
import { index as showroomsIndex } from '@/routes/showrooms';
import { index as usersIndex } from '@/routes/users';

type Employee = {
    name: string;
    email: string;
    globalAccess: boolean;
    roles: { id: number; label: string }[];
    showrooms: {
        id: number;
        name: string;
        address: string | null;
        active: boolean;
    }[];
    departments: {
        id: number;
        name: string;
        kind: string;
        showroom: string | null;
        active: boolean;
    }[];
};

function Empty({ children }: { children: string }) {
    return <p className="text-sm text-muted-foreground">{children}</p>;
}

export default function Dashboard({ employee }: { employee: Employee }) {
    const { can } = usePermissions();

    return (
        <>
            <Head title="Рабочее место" />
            <PageBody>
                <PageHeader
                    title={`Здравствуйте, ${employee.name}`}
                    description={employee.email}
                    actions={
                        <>
                            {can('showrooms.view') && (
                                <Button variant="outline" asChild>
                                    <Link href={showroomsIndex()}>
                                        <Building2 />
                                        Автосалоны
                                    </Link>
                                </Button>
                            )}
                            {can('departments.view') && (
                                <Button variant="outline" asChild>
                                    <Link href={departmentsIndex()}>
                                        <UsersRound />
                                        Отделы
                                    </Link>
                                </Button>
                            )}
                            {can('users.view') && (
                                <Button variant="outline" asChild>
                                    <Link href={usersIndex()}>
                                        <Users />
                                        Сотрудники
                                    </Link>
                                </Button>
                            )}
                        </>
                    }
                />

                <div className="grid gap-4 lg:grid-cols-3">
                    <FormSection title="Мои роли">
                        {employee.roles.length === 0 ? (
                            <Empty>Роли не назначены.</Empty>
                        ) : (
                            <div className="flex flex-wrap gap-2">
                                {employee.roles.map((role) => (
                                    <Badge
                                        key={role.id}
                                        variant="primary-light"
                                        size="lg"
                                    >
                                        {role.label}
                                    </Badge>
                                ))}
                            </div>
                        )}
                    </FormSection>

                    <FormSection
                        title="Мои салоны"
                        description={
                            employee.globalAccess
                                ? 'Глобальный доступ: все активные салоны.'
                                : undefined
                        }
                    >
                        {employee.showrooms.length === 0 ? (
                            <Empty>Доступ к салонам не выдан.</Empty>
                        ) : (
                            <ul className="flex flex-col gap-2">
                                {employee.showrooms.map((showroom) => (
                                    <li
                                        key={showroom.id}
                                        className="flex flex-col"
                                    >
                                        <span className="flex items-center gap-2 text-sm font-medium">
                                            {showroom.name}
                                            {!showroom.active && (
                                                <Badge variant="destructive-light">
                                                    Отключён
                                                </Badge>
                                            )}
                                        </span>
                                        {showroom.address && (
                                            <span className="text-xs text-muted-foreground">
                                                {showroom.address}
                                            </span>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </FormSection>

                    <FormSection title="Мои отделы">
                        {employee.departments.length === 0 ? (
                            <Empty>Вы не состоите в отделах.</Empty>
                        ) : (
                            <ul className="flex flex-col gap-2">
                                {employee.departments.map((department) => (
                                    <li
                                        key={department.id}
                                        className="flex flex-col"
                                    >
                                        <span className="flex items-center gap-2 text-sm font-medium">
                                            {department.name}
                                            {!department.active && (
                                                <Badge variant="destructive-light">
                                                    Отключён
                                                </Badge>
                                            )}
                                        </span>
                                        <span className="text-xs text-muted-foreground">
                                            {department.kind} ·{' '}
                                            {department.showroom ??
                                                'общий отдел'}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </FormSection>
                </div>
            </PageBody>
        </>
    );
}

Dashboard.layout = {
    breadcrumbs: [{ title: 'Рабочее место', href: dashboard() }],
};
