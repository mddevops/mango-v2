import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useEffect, useState } from 'react';
import IntegrationConnectionController from '@/actions/App/Http/Controllers/Sources/IntegrationConnectionController';
import FormField from '@/components/form-field';
import { FormAlert } from '@/components/organization/form-alert';
import type { FormDialogControls } from '@/components/organization/form-dialog';
import { FormDialog } from '@/components/organization/form-dialog';
import { CheckboxOption } from '@/components/organization/form-section';
import { Badge } from '@/components/reui/badge';
import { Button } from '@/components/ui/button';
import {
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Separator } from '@/components/ui/separator';
import { Spinner } from '@/components/ui/spinner';
import { saveOptions } from '@/lib/submit';
import type { ConnectionRow, ReferenceOption } from '@/types';

type SecretField = { key: string; label: string };

type Props = {
    open: boolean;
    connection: ConnectionRow | null;
    showrooms: ReferenceOption[];
    secretFields: SecretField[];
    canManageSecrets: boolean;
    onOpenChange: (open: boolean) => void;
};

export function ConnectionDialog({ open, onOpenChange, ...props }: Props) {
    return (
        <FormDialog
            open={open}
            onOpenChange={onOpenChange}
            className="max-h-[90vh] overflow-y-auto sm:max-w-2xl"
        >
            {(controls) => <ConnectionForm {...props} controls={controls} />}
        </FormDialog>
    );
}

function ConnectionForm({
    connection,
    showrooms,
    secretFields,
    canManageSecrets,
    controls,
}: Omit<Props, 'open' | 'onOpenChange'> & {
    controls: FormDialogControls;
}) {
    const { setBusy, setDirty, requestClose, close } = controls;
    const [failure, setFailure] = useState<string | null>(null);

    const selectedShowrooms =
        connection?.showrooms.map((showroom) => showroom.id) ?? [];
    const showroomChoices = showrooms.filter(
        (showroom) =>
            showroom.active || selectedShowrooms.includes(showroom.id),
    );

    const form = useForm({
        name: connection?.name ?? '',
        showroom_ids: selectedShowrooms,
        secrets: Object.fromEntries(
            secretFields.map((field) => [field.key, '']),
        ) as Record<string, string>,
        clear_secrets: [] as string[],
        lock_version: connection?.lock_version ?? 0,
    });

    useEffect(() => setDirty(form.isDirty), [form.isDirty, setDirty]);

    const submit = (event: FormEvent) => {
        event.preventDefault();

        if (form.processing) {
            return;
        }

        const options = saveOptions(setFailure, {
            preserveState: true,
            onStart: () => setBusy(true),
            onFinish: () => setBusy(false),
            onSuccess: () => close(),
        });

        form.transform((data) => ({
            name: data.name,
            showroom_ids: data.showroom_ids,
            ...(canManageSecrets
                ? {
                      ...Object.fromEntries(
                          Object.entries(data.secrets).filter(
                              ([, value]) => value.trim() !== '',
                          ),
                      ),
                      clear_secrets: data.clear_secrets,
                  }
                : {}),
            ...(connection ? { lock_version: data.lock_version } : {}),
        }));

        if (connection) {
            form.put(
                IntegrationConnectionController.update.url(connection.id),
                options,
            );
        } else {
            form.post(IntegrationConnectionController.store.url(), options);
        }
    };

    const errors = form.errors as Record<string, string | undefined>;

    return (
        <form
            onSubmit={submit}
            className="flex flex-col gap-4"
            noValidate
            autoComplete="off"
        >
            <DialogHeader>
                <DialogTitle>
                    {connection
                        ? 'Изменить подключение Mango VPBX'
                        : 'Новое подключение Mango VPBX'}
                </DialogTitle>
                <DialogDescription>
                    Сохраняются только настройки: CRM пока не обращается к
                    Mango. Состояние подключения — «Не проверено».
                </DialogDescription>
            </DialogHeader>

            <FormAlert conflict={form.errors.lock_version} failure={failure} />

            <FormField label="Название" error={form.errors.name}>
                <Input
                    id="connection-name"
                    value={form.data.name}
                    onChange={(event) =>
                        form.setData('name', event.target.value)
                    }
                    required
                    maxLength={255}
                    autoFocus
                />
            </FormField>

            <div className="flex flex-col gap-2">
                <div className="flex flex-col gap-0.5">
                    <span className="text-sm font-medium">
                        Разрешённые салоны
                    </span>
                    <span className="text-xs text-muted-foreground">
                        К подключению можно привязать только источники этих
                        салонов.
                    </span>
                </div>
                <div className="grid gap-2 sm:grid-cols-2">
                    {showroomChoices.map((showroom) => (
                        <CheckboxOption
                            key={showroom.id}
                            id={`connection-showroom-${showroom.id}`}
                            checked={form.data.showroom_ids.includes(
                                showroom.id,
                            )}
                            onChange={(checked) =>
                                form.setData(
                                    'showroom_ids',
                                    checked
                                        ? [
                                              ...form.data.showroom_ids,
                                              showroom.id,
                                          ]
                                        : form.data.showroom_ids.filter(
                                              (id) => id !== showroom.id,
                                          ),
                                )
                            }
                            label={showroom.name}
                            aside={
                                !showroom.active && (
                                    <Badge variant="warning-light">
                                        Отключён
                                    </Badge>
                                )
                            }
                        />
                    ))}
                </div>
                {(errors.showroom_ids ?? errors['showroom_ids.0']) && (
                    <p className="text-sm text-destructive">
                        {errors.showroom_ids ?? errors['showroom_ids.0']}
                    </p>
                )}
            </div>

            <Separator />

            <div className="flex flex-col gap-3">
                <div className="flex flex-col gap-0.5">
                    <span className="text-sm font-medium">Ключи API ВАТС</span>
                    <span className="text-xs text-muted-foreground">
                        API key и API salt из личного кабинета Mango Office
                        (раздел интеграции ВАТС). Site ID и Token Mango
                        Calltracking сюда не подходят. Сохранённые значения не
                        показываются; пустое поле оставляет прежнее значение.
                    </span>
                </div>
                {secretFields.map((field) => {
                    const present = connection?.secrets[field.key] ?? false;
                    const clearing = form.data.clear_secrets.includes(
                        field.key,
                    );

                    return (
                        <div key={field.key} className="flex flex-col gap-2">
                            <FormField
                                label={field.label}
                                labelAside={
                                    <Badge
                                        variant={
                                            present
                                                ? 'success-light'
                                                : 'secondary'
                                        }
                                    >
                                        {present ? 'Задан' : 'Не задан'}
                                    </Badge>
                                }
                                error={errors[field.key]}
                            >
                                <Input
                                    id={`connection-${field.key}`}
                                    type="password"
                                    autoComplete="new-password"
                                    value={form.data.secrets[field.key]}
                                    onChange={(event) =>
                                        form.setData('secrets', {
                                            ...form.data.secrets,
                                            [field.key]: event.target.value,
                                        })
                                    }
                                    disabled={!canManageSecrets || clearing}
                                    placeholder={
                                        present
                                            ? 'Оставьте пустым, чтобы не менять'
                                            : 'Не задан'
                                    }
                                    maxLength={255}
                                />
                            </FormField>
                            {present && canManageSecrets && (
                                <CheckboxOption
                                    id={`connection-clear-${field.key}`}
                                    checked={clearing}
                                    onChange={(checked) =>
                                        form.setData((data) => ({
                                            ...data,
                                            secrets: {
                                                ...data.secrets,
                                                [field.key]: '',
                                            },
                                            clear_secrets: checked
                                                ? [
                                                      ...data.clear_secrets,
                                                      field.key,
                                                  ]
                                                : data.clear_secrets.filter(
                                                      (key) =>
                                                          key !== field.key,
                                                  ),
                                        }))
                                    }
                                    label={`Удалить сохранённый ${field.label}`}
                                />
                            )}
                        </div>
                    );
                })}
                {!canManageSecrets && (
                    <p className="text-xs text-muted-foreground">
                        Для ввода и удаления ключей нужно отдельное право.
                    </p>
                )}
            </div>

            <DialogFooter>
                <Button
                    type="button"
                    variant="outline"
                    onClick={requestClose}
                    disabled={form.processing}
                >
                    Отмена
                </Button>
                <Button type="submit" disabled={form.processing}>
                    {form.processing && <Spinner />}
                    {connection ? 'Сохранить' : 'Создать'}
                </Button>
            </DialogFooter>
        </form>
    );
}
