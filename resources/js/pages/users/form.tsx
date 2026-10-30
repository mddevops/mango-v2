import { Head, Link, useForm } from '@inertiajs/react';
import { Globe, KeyRound, ShieldOff } from 'lucide-react';
import type { FormEvent } from 'react';
import { useEffect, useMemo, useState } from 'react';
import UserController from '@/actions/App/Http/Controllers/Organization/UserController';
import FormField from '@/components/form-field';
import { FormAlert } from '@/components/organization/form-alert';
import {
    CheckboxOption,
    FormSection,
} from '@/components/organization/form-section';
import { PageBody, PageHeader } from '@/components/organization/page-header';
import { SetPasswordDialog } from '@/components/organization/set-password-dialog';
import { ConfirmPatchAction } from '@/components/organization/confirm-patch-action';
import { StatusAction } from '@/components/organization/status-action';
import { StatusBadge } from '@/components/organization/status-badge';
import PasswordInput from '@/components/password-input';
import { Alert, AlertDescription } from '@/components/reui/alert';
import { Badge } from '@/components/reui/badge';
import { Button } from '@/components/ui/button';
import { FieldError } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { saveOptions } from '@/lib/submit';
import {
    create as usersCreate,
    edit as usersEdit,
    index as usersIndex,
} from '@/routes/users';

type EditableUser = {
    id: number;
    name: string;
    email: string;
    active: boolean;
    has_global_access: boolean;
    lock_version: number;
    is_self: boolean;
    role_ids: number[];
    showroom_ids: number[];
    department_ids: number[];
    hidden_assignments: number;
};

type Options = {
    roles: { id: number; label: string; delegable: boolean }[];
    showrooms: { id: number; name: string; active: boolean }[];
    departments: {
        id: number;
        name: string;
        kind_label: string;
        showroom_id: number | null;
        showroom: string | null;
        active: boolean;
    }[];
    globalEditor: boolean;
};

type Props = {
    user: EditableUser | null;
    options: Options;
    passwordRules: string;
    can: {
        update?: boolean;
        setPassword: boolean;
        changeStatus: boolean;
        grantGlobalAccess: boolean;
    };
};

function collectError(
    errors: Record<string, string | undefined>,
    key: string,
): string | undefined {
    return Object.entries(errors).find(
        ([field]) => field === key || field.startsWith(`${key}.`),
    )?.[1];
}

function toggle(list: number[], id: number, checked: boolean): number[] {
    return checked
        ? [...new Set([...list, id])]
        : list.filter((value) => value !== id);
}

export default function UserForm({ user, options, passwordRules, can }: Props) {
    const isNew = user === null;
    const editable = isNew || Boolean(can.update);
    const assignmentsLocked = !editable || Boolean(user?.is_self);
    const [failure, setFailure] = useState<string | null>(null);
    const [passwordOpen, setPasswordOpen] = useState(false);

    const form = useForm({
        name: user?.name ?? '',
        email: user?.email ?? '',
        password: '',
        password_confirmation: '',
        active: user?.active ?? true,
        lock_version: user?.lock_version ?? 0,
        role_ids: user?.role_ids ?? [],
        showroom_ids: user?.showroom_ids ?? [],
        department_ids: user?.department_ids ?? [],
    });

    useEffect(() => {
        if (user && !form.isDirty) {
            form.setDefaults('lock_version', user.lock_version);
            form.setData('lock_version', user.lock_version);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [user?.lock_version]);

    const errors = form.errors as Record<string, string | undefined>;

    const availableDepartments = useMemo(
        () =>
            options.departments.filter(
                (department) =>
                    department.showroom_id === null ||
                    form.data.showroom_ids.includes(department.showroom_id),
            ),
        [options.departments, form.data.showroom_ids],
    );

    const setShowroom = (id: number, checked: boolean) => {
        const showroomIds = toggle(form.data.showroom_ids, id, checked);
        const departmentIds = form.data.department_ids.filter(
            (departmentId) => {
                const department = options.departments.find(
                    (item) => item.id === departmentId,
                );

                return (
                    !department ||
                    department.showroom_id === null ||
                    showroomIds.includes(department.showroom_id)
                );
            },
        );

        form.setData((data) => ({
            ...data,
            showroom_ids: showroomIds,
            department_ids: departmentIds,
        }));
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();

        if (form.processing || !editable) {
            return;
        }

        const visit = saveOptions(setFailure, { preserveState: 'errors' });

        if (isNew) {
            form.transform(({ lock_version: _version, ...data }) => data);
            form.post(UserController.store.url(), visit);
        } else {
            form.transform(
                ({
                    password: _p,
                    password_confirmation: _c,
                    active: _a,
                    ...data
                }) => data,
            );
            form.put(UserController.update.url(user.id), visit);
        }
    };

    const title = isNew ? 'Новый сотрудник' : user.name;

    return (
        <>
            <Head title={title} />
            <PageBody>
                <PageHeader
                    title={title}
                    description={
                        isNew ? (
                            'Заполните учётные данные и выдайте доступ.'
                        ) : (
                            <span className="flex flex-wrap items-center gap-2">
                                {user.email}
                                <StatusBadge active={user.active} />
                                {user.has_global_access && (
                                    <Badge variant="primary-light">
                                        Глобальный доступ
                                    </Badge>
                                )}
                            </span>
                        )
                    }
                    actions={
                        user && (
                            <>
                                {can.setPassword && (
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={() => setPasswordOpen(true)}
                                    >
                                        <KeyRound />
                                        Задать новый пароль
                                    </Button>
                                )}
                                {can.changeStatus && (
                                    <StatusAction
                                        size="default"
                                        active={user.active}
                                        url={UserController.status.url(user.id)}
                                        lockVersion={user.lock_version}
                                        title={`Отключить сотрудника «${user.name}»?`}
                                        description="Сотрудник не сможет войти, его открытые сессии завершатся при следующем запросе. Учётную запись можно включить снова."
                                    />
                                )}
                            </>
                        )
                    }
                />

                {!editable && (
                    <Alert variant="info">
                        <AlertDescription>
                            Только просмотр: у вас нет права изменять этого
                            сотрудника или его роли шире ваших.
                        </AlertDescription>
                    </Alert>
                )}

                {user?.is_self && editable && (
                    <Alert variant="info">
                        <AlertDescription>
                            Это ваша учётная запись. Свои роли, салоны и отделы
                            изменить нельзя — это делает другой администратор.
                        </AlertDescription>
                    </Alert>
                )}

                <form
                    onSubmit={submit}
                    className="flex flex-col gap-4"
                    noValidate
                >
                    <FormAlert
                        conflict={form.errors.lock_version}
                        failure={failure}
                    />

                    <FormSection title="Учётная запись">
                        <div className="grid gap-4 md:grid-cols-2">
                            <FormField label="Имя" error={form.errors.name}>
                                <Input
                                    id="user-name"
                                    value={form.data.name}
                                    onChange={(event) =>
                                        form.setData('name', event.target.value)
                                    }
                                    required
                                    maxLength={255}
                                    disabled={!editable}
                                    autoComplete="off"
                                />
                            </FormField>
                            <FormField label="Email" error={form.errors.email}>
                                <Input
                                    id="user-email"
                                    type="email"
                                    value={form.data.email}
                                    onChange={(event) =>
                                        form.setData(
                                            'email',
                                            event.target.value,
                                        )
                                    }
                                    required
                                    maxLength={255}
                                    disabled={!editable}
                                    autoComplete="off"
                                />
                            </FormField>
                            {isNew && (
                                <>
                                    <FormField
                                        label="Пароль"
                                        error={form.errors.password}
                                    >
                                        <PasswordInput
                                            id="user-password"
                                            value={form.data.password}
                                            onChange={(event) =>
                                                form.setData(
                                                    'password',
                                                    event.target.value,
                                                )
                                            }
                                            required
                                            autoComplete="new-password"
                                            passwordrules={passwordRules}
                                        />
                                    </FormField>
                                    <FormField
                                        label="Повтор пароля"
                                        error={
                                            form.errors.password_confirmation
                                        }
                                    >
                                        <PasswordInput
                                            id="user-password-confirmation"
                                            value={
                                                form.data.password_confirmation
                                            }
                                            onChange={(event) =>
                                                form.setData(
                                                    'password_confirmation',
                                                    event.target.value,
                                                )
                                            }
                                            required
                                            autoComplete="new-password"
                                        />
                                    </FormField>
                                    <div className="md:col-span-2">
                                        <CheckboxOption
                                            id="user-active"
                                            checked={form.data.active}
                                            onChange={(checked) =>
                                                form.setData('active', checked)
                                            }
                                            label="Учётная запись активна"
                                            hint="Неактивный сотрудник не сможет войти в систему."
                                        />
                                    </div>
                                </>
                            )}
                        </div>
                    </FormSection>

                    <FormSection
                        title="Роли"
                        description="Роль определяет действия. Данные ограничены салонами и отделами ниже."
                    >
                        <div className="flex flex-col gap-2">
                            <div className="grid gap-2 sm:grid-cols-2">
                                {options.roles.map((role) => {
                                    const checked = form.data.role_ids.includes(
                                        role.id,
                                    );

                                    return (
                                        <CheckboxOption
                                            key={role.id}
                                            id={`role-${role.id}`}
                                            checked={checked}
                                            disabled={
                                                assignmentsLocked ||
                                                (!role.delegable && !checked)
                                            }
                                            onChange={(value) =>
                                                form.setData(
                                                    'role_ids',
                                                    toggle(
                                                        form.data.role_ids,
                                                        role.id,
                                                        value,
                                                    ),
                                                )
                                            }
                                            label={role.label}
                                            hint={
                                                role.delegable
                                                    ? undefined
                                                    : 'Нельзя выдать: роль содержит права, которых нет у вас.'
                                            }
                                        />
                                    );
                                })}
                            </div>
                            <FieldError>
                                {collectError(errors, 'role_ids')}
                            </FieldError>
                        </div>
                    </FormSection>

                    <FormSection
                        title="Доступ к салонам"
                        description="Сотрудник видит данные только выбранных салонов."
                        actions={
                            user &&
                            can.grantGlobalAccess && (
                                <ConfirmPatchAction
                                    url={UserController.globalAccess.url(
                                        user.id,
                                    )}
                                    payload={{
                                        active: !user.has_global_access,
                                        lock_version: user.lock_version,
                                    }}
                                    destructive={user.has_global_access}
                                    icon={
                                        user.has_global_access ? (
                                            <ShieldOff />
                                        ) : (
                                            <Globe />
                                        )
                                    }
                                    label={
                                        user.has_global_access
                                            ? 'Отозвать глобальный доступ'
                                            : 'Выдать глобальный доступ'
                                    }
                                    title={
                                        user.has_global_access
                                            ? `Отозвать глобальный доступ у «${user.name}»?`
                                            : `Выдать «${user.name}» доступ ко всем салонам?`
                                    }
                                    description={
                                        user.has_global_access
                                            ? 'Сотрудник будет видеть только назначенные ему салоны.'
                                            : 'Сотрудник увидит данные всех салонов, включая будущие. Выдавайте только при необходимости.'
                                    }
                                />
                            )
                        }
                    >
                        <div className="flex flex-col gap-3">
                            {user?.has_global_access && (
                                <Alert variant="info">
                                    <AlertDescription>
                                        У сотрудника глобальный доступ: он видит
                                        все салоны независимо от списка ниже.
                                    </AlertDescription>
                                </Alert>
                            )}
                            {options.showrooms.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    Нет доступных салонов. Сначала создайте
                                    салон.
                                </p>
                            ) : (
                                <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                                    {options.showrooms.map((showroom) => {
                                        const checked =
                                            form.data.showroom_ids.includes(
                                                showroom.id,
                                            );

                                        return (
                                            <CheckboxOption
                                                key={showroom.id}
                                                id={`showroom-${showroom.id}`}
                                                checked={checked}
                                                disabled={
                                                    assignmentsLocked ||
                                                    (!showroom.active &&
                                                        !checked)
                                                }
                                                onChange={(value) =>
                                                    setShowroom(
                                                        showroom.id,
                                                        value,
                                                    )
                                                }
                                                label={showroom.name}
                                                aside={
                                                    !showroom.active && (
                                                        <Badge variant="destructive-light">
                                                            Отключён
                                                        </Badge>
                                                    )
                                                }
                                            />
                                        );
                                    })}
                                </div>
                            )}
                            <FieldError>
                                {collectError(errors, 'showroom_ids')}
                            </FieldError>
                            {!options.globalEditor && isNew && (
                                <p className="text-xs text-muted-foreground">
                                    Нужно выбрать хотя бы один из ваших салонов.
                                </p>
                            )}
                            {Boolean(user?.hidden_assignments) && (
                                <p className="text-xs text-muted-foreground">
                                    У сотрудника есть назначения вне вашей зоны
                                    доступа ({user?.hidden_assignments}). Они не
                                    показываются и сохранятся без изменений.
                                </p>
                            )}
                        </div>
                    </FormSection>

                    <FormSection
                        title="Отделы"
                        description="Можно выбрать отделы только тех салонов, к которым у сотрудника есть доступ."
                    >
                        <div className="flex flex-col gap-3">
                            {availableDepartments.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    Нет доступных отделов. Выберите салон, в
                                    котором есть отделы.
                                </p>
                            ) : (
                                <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                                    {availableDepartments.map((department) => {
                                        const checked =
                                            form.data.department_ids.includes(
                                                department.id,
                                            );

                                        return (
                                            <CheckboxOption
                                                key={department.id}
                                                id={`department-${department.id}`}
                                                checked={checked}
                                                disabled={
                                                    assignmentsLocked ||
                                                    (!department.active &&
                                                        !checked)
                                                }
                                                onChange={(value) =>
                                                    form.setData(
                                                        'department_ids',
                                                        toggle(
                                                            form.data
                                                                .department_ids,
                                                            department.id,
                                                            value,
                                                        ),
                                                    )
                                                }
                                                label={department.name}
                                                hint={`${department.kind_label} · ${department.showroom ?? 'общий отдел'}`}
                                                aside={
                                                    !department.active && (
                                                        <Badge variant="destructive-light">
                                                            Отключён
                                                        </Badge>
                                                    )
                                                }
                                            />
                                        );
                                    })}
                                </div>
                            )}
                            <FieldError>
                                {collectError(errors, 'department_ids')}
                            </FieldError>
                        </div>
                    </FormSection>

                    {editable && (
                        <div className="flex flex-wrap justify-end gap-2">
                            <Button variant="outline" asChild>
                                <Link href={usersIndex()}>Отмена</Link>
                            </Button>
                            <Button type="submit" disabled={form.processing}>
                                {form.processing && <Spinner />}
                                {isNew ? 'Создать сотрудника' : 'Сохранить'}
                            </Button>
                        </div>
                    )}
                </form>
            </PageBody>

            {user && can.setPassword && (
                <SetPasswordDialog
                    open={passwordOpen}
                    onOpenChange={setPasswordOpen}
                    url={UserController.password.url(user.id)}
                    userName={user.name}
                    passwordRules={passwordRules}
                />
            )}
        </>
    );
}

UserForm.layout = ({ user }: Props) => ({
    breadcrumbs: [
        { title: 'Сотрудники', href: usersIndex() },
        user
            ? { title: user.name, href: usersEdit(user.id) }
            : { title: 'Новый сотрудник', href: usersCreate() },
    ],
});
