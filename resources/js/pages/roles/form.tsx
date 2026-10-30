import { Head, Link, router, useForm } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import { toast } from 'sonner';
import RoleController from '@/actions/App/Http/Controllers/Organization/RoleController';
import FormField from '@/components/form-field';
import {
    FormAlert,
    saveFailureMessage,
} from '@/components/organization/form-alert';
import {
    CheckboxOption,
    FormSection,
} from '@/components/organization/form-section';
import { PageBody, PageHeader } from '@/components/organization/page-header';
import { Alert, AlertDescription } from '@/components/reui/alert';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import { FieldError } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { firstError, saveOptions } from '@/lib/submit';
import {
    create as rolesCreate,
    edit as rolesEdit,
    index as rolesIndex,
} from '@/routes/roles';
import type { PermissionGroup } from '@/types';

type EditableRole = {
    id: number;
    name: string;
    label: string;
    is_system: boolean;
    is_administrator: boolean;
    lock_version: number;
    permissions: string[];
    users_count: number;
};

type Props = {
    role: EditableRole | null;
    groups: PermissionGroup[];
    ownPermissions: string[];
};

export default function RoleForm({ role, groups, ownPermissions }: Props) {
    const isNew = role === null;
    const locked = Boolean(role?.is_administrator);
    const [failure, setFailure] = useState<string | null>(null);

    const form = useForm({
        name: role?.name ?? '',
        label: role?.label ?? '',
        permissions: role?.permissions ?? [],
        lock_version: role?.lock_version ?? 0,
    });

    const errors = form.errors as Record<string, string | undefined>;
    const permissionsError = Object.entries(errors).find(([key]) =>
        key.startsWith('permissions'),
    )?.[1];
    const missing = (role?.permissions ?? []).filter(
        (permission) => !ownPermissions.includes(permission),
    );

    const toggle = (name: string, checked: boolean) =>
        form.setData(
            'permissions',
            checked
                ? [...new Set([...form.data.permissions, name])]
                : form.data.permissions.filter((p) => p !== name),
        );

    const toggleGroup = (group: PermissionGroup, checked: boolean) => {
        const names = group.permissions
            .map((permission) => permission.name)
            .filter((name) => ownPermissions.includes(name));
        form.setData(
            'permissions',
            checked
                ? [...new Set([...form.data.permissions, ...names])]
                : form.data.permissions.filter(
                      (permission) => !names.includes(permission),
                  ),
        );
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();

        if (form.processing) {
            return;
        }

        const visit = saveOptions(setFailure, { preserveState: 'errors' });

        if (isNew) {
            form.transform(({ lock_version: _version, ...data }) => data);
            form.post(RoleController.store.url(), visit);
        } else {
            form.transform(({ name: _name, ...data }) => data);
            form.put(RoleController.update.url(role.id), visit);
        }
    };

    const title = isNew ? 'Новая роль' : role.label;

    return (
        <>
            <Head title={title} />
            <PageBody>
                <PageHeader
                    title={title}
                    description={
                        isNew
                            ? 'Можно выдать только те права, которые есть у вас.'
                            : `Назначена сотрудникам: ${role.users_count}.`
                    }
                    actions={
                        role && !role.is_system && <DeleteRole role={role} />
                    }
                />

                {locked && (
                    <Alert variant="warning">
                        <AlertDescription>
                            Системная роль администратора всегда содержит все
                            права. Её нельзя удалить или ограничить — можно
                            изменить только название.
                        </AlertDescription>
                    </Alert>
                )}

                {missing.length > 0 && !locked && (
                    <Alert variant="warning">
                        <AlertDescription>
                            Роль содержит права, которых нет у вас. Сохранить
                            изменения не получится — обратитесь к
                            администратору.
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

                    <FormSection title="Описание">
                        <div className="grid gap-4 md:grid-cols-2">
                            <FormField
                                label="Название"
                                error={form.errors.label}
                            >
                                <Input
                                    id="role-label"
                                    value={form.data.label}
                                    onChange={(event) =>
                                        form.setData(
                                            'label',
                                            event.target.value,
                                        )
                                    }
                                    required
                                    maxLength={100}
                                />
                            </FormField>
                            <FormField
                                label="Системное имя"
                                error={form.errors.name}
                                description={
                                    isNew
                                        ? 'Латиница, цифры и «_». Не меняется после создания.'
                                        : 'Не меняется после создания.'
                                }
                            >
                                <Input
                                    id="role-name"
                                    value={form.data.name}
                                    onChange={(event) =>
                                        form.setData(
                                            'name',
                                            event.target.value.toLowerCase(),
                                        )
                                    }
                                    required={isNew}
                                    disabled={!isNew}
                                    maxLength={100}
                                    autoComplete="off"
                                    placeholder="senior_operator"
                                />
                            </FormField>
                        </div>
                    </FormSection>

                    {groups.map((group) => {
                        const names = group.permissions.map(
                            (permission) => permission.name,
                        );
                        const allChecked = names.every((name) =>
                            form.data.permissions.includes(name),
                        );
                        const delegable = names.some((name) =>
                            ownPermissions.includes(name),
                        );

                        return (
                            <FormSection
                                key={group.key}
                                title={group.label}
                                actions={
                                    !locked &&
                                    delegable && (
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            onClick={() =>
                                                toggleGroup(group, !allChecked)
                                            }
                                        >
                                            {allChecked
                                                ? 'Снять все'
                                                : 'Выбрать все'}
                                        </Button>
                                    )
                                }
                            >
                                <div className="grid gap-2 sm:grid-cols-2">
                                    {group.permissions.map((permission) => {
                                        const own = ownPermissions.includes(
                                            permission.name,
                                        );

                                        return (
                                            <CheckboxOption
                                                key={permission.name}
                                                id={`permission-${permission.name}`}
                                                checked={
                                                    locked ||
                                                    form.data.permissions.includes(
                                                        permission.name,
                                                    )
                                                }
                                                disabled={locked || !own}
                                                onChange={(checked) =>
                                                    toggle(
                                                        permission.name,
                                                        checked,
                                                    )
                                                }
                                                label={permission.label}
                                                hint={
                                                    own
                                                        ? permission.name
                                                        : `${permission.name} · нет у вас, выдать нельзя`
                                                }
                                            />
                                        );
                                    })}
                                </div>
                            </FormSection>
                        );
                    })}

                    <FieldError>{permissionsError}</FieldError>

                    <div className="flex flex-wrap justify-end gap-2">
                        <Button variant="outline" asChild>
                            <Link href={rolesIndex()}>Отмена</Link>
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            {form.processing && <Spinner />}
                            {isNew ? 'Создать роль' : 'Сохранить'}
                        </Button>
                    </div>
                </form>
            </PageBody>
        </>
    );
}

function DeleteRole({ role }: { role: EditableRole }) {
    const [open, setOpen] = useState(false);
    const [processing, setProcessing] = useState(false);
    const blocked = role.users_count > 0;

    const destroy = () => {
        if (processing) {
            return;
        }

        router.delete(RoleController.destroy.url(role.id), {
            onStart: () => setProcessing(true),
            onFinish: () => {
                setProcessing(false);
                setOpen(false);
            },
            onError: (errors) => {
                toast.error(firstError(errors) ?? saveFailureMessage);
            },
            onHttpException: () => {
                toast.error(saveFailureMessage);

                return false;
            },
            onNetworkError: () => {
                toast.error(saveFailureMessage);

                return false;
            },
        });
    };

    return (
        <AlertDialog
            open={open}
            onOpenChange={(next) => !processing && setOpen(next)}
        >
            <Button
                type="button"
                variant="outline"
                onClick={() => setOpen(true)}
                disabled={blocked}
            >
                <Trash2 />
                {blocked
                    ? 'Удаление недоступно: роль назначена'
                    : 'Удалить роль'}
            </Button>
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>
                        Удалить роль «{role.label}»?
                    </AlertDialogTitle>
                    <AlertDialogDescription>
                        Роль не назначена ни одному сотруднику. Действие будет
                        записано в аудит.
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter>
                    <AlertDialogCancel disabled={processing}>
                        Отмена
                    </AlertDialogCancel>
                    <AlertDialogAction
                        variant="destructive"
                        disabled={processing}
                        onClick={(event) => {
                            event.preventDefault();
                            destroy();
                        }}
                    >
                        {processing && <Spinner />}
                        Удалить
                    </AlertDialogAction>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}

RoleForm.layout = ({ role }: Props) => ({
    breadcrumbs: [
        { title: 'Роли и права', href: rolesIndex() },
        role
            ? { title: role.label, href: rolesEdit(role.id) }
            : { title: 'Новая роль', href: rolesCreate() },
    ],
});
