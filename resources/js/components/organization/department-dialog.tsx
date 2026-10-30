import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useEffect, useState } from 'react';
import DepartmentController from '@/actions/App/Http/Controllers/Organization/DepartmentController';
import FormField from '@/components/form-field';
import { FormAlert } from '@/components/organization/form-alert';
import type { FormDialogControls } from '@/components/organization/form-dialog';
import { FormDialog } from '@/components/organization/form-dialog';
import { TechnicalCode } from '@/components/organization/technical-code';
import { Button } from '@/components/ui/button';
import {
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { saveOptions } from '@/lib/submit';
import type { DepartmentKindOption, DepartmentRow } from '@/types';

type ShowroomOption = { id: number; name: string; active: boolean };

type Props = {
    open: boolean;
    department: DepartmentRow | null;
    showrooms: ShowroomOption[];
    kinds: DepartmentKindOption[];
    canCreateShared: boolean;
    onOpenChange: (open: boolean) => void;
};

export function DepartmentDialog({ open, onOpenChange, ...props }: Props) {
    return (
        <FormDialog open={open} onOpenChange={onOpenChange}>
            {(controls) => <DepartmentForm {...props} controls={controls} />}
        </FormDialog>
    );
}

const SHARED = 'shared';

function DepartmentForm({
    department,
    showrooms,
    kinds,
    canCreateShared,
    controls,
}: Omit<Props, 'open' | 'onOpenChange'> & {
    controls: FormDialogControls;
}) {
    const { setBusy, setDirty, requestClose, close } = controls;
    const [failure, setFailure] = useState<string | null>(null);
    const activeShowrooms = showrooms.filter((showroom) => showroom.active);
    const form = useForm({
        showroom: department
            ? department.showroom
                ? String(department.showroom.id)
                : SHARED
            : activeShowrooms[0]
              ? String(activeShowrooms[0].id)
              : canCreateShared
                ? SHARED
                : '',
        kind: department?.kind ?? 'showroom',
        name: department?.name ?? '',
        lock_version: department?.lock_version ?? 0,
    });

    useEffect(() => setDirty(form.isDirty), [form.isDirty, setDirty]);

    const selectedKind = kinds.find((kind) => kind.value === form.data.kind);
    const sharedNotAllowed =
        form.data.showroom === SHARED && selectedKind?.requiresShowroom;

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

        if (department) {
            form.transform((data) => ({
                name: data.name,
                lock_version: data.lock_version,
            }));
            form.put(DepartmentController.update.url(department.id), options);
        } else {
            form.transform((data) => ({
                showroom_id:
                    data.showroom === SHARED || data.showroom === ''
                        ? null
                        : Number(data.showroom),
                kind: data.kind,
                name: data.name,
            }));
            form.post(DepartmentController.store.url(), options);
        }
    };

    const errors = form.errors as Record<string, string | undefined>;

    return (
        <form onSubmit={submit} className="flex flex-col gap-4" noValidate>
            <DialogHeader>
                <DialogTitle>
                    {department ? 'Изменить отдел' : 'Новый отдел'}
                </DialogTitle>
                <DialogDescription>
                    {department
                        ? 'Салон и тип отдела после создания не меняются. Можно изменить название.'
                        : 'Тип задаёт назначение отдела, название можно поменять позже.'}
                </DialogDescription>
            </DialogHeader>

            <FormAlert conflict={form.errors.lock_version} failure={failure} />

            <div className="flex flex-col gap-4">
                <FormField label="Салон" error={errors.showroom_id}>
                    <Select
                        value={form.data.showroom}
                        onValueChange={(value) =>
                            form.setData('showroom', value)
                        }
                        disabled={Boolean(department)}
                    >
                        <SelectTrigger
                            id="department-showroom"
                            className="w-full"
                        >
                            <SelectValue placeholder="Выберите салон" />
                        </SelectTrigger>
                        <SelectContent position="popper" className="max-h-72">
                            <SelectGroup>
                                {(canCreateShared ||
                                    department?.showroom === null) && (
                                    <SelectItem value={SHARED}>
                                        Общий отдел (без салона)
                                    </SelectItem>
                                )}
                                {(department ? showrooms : activeShowrooms).map(
                                    (showroom) => (
                                        <SelectItem
                                            key={showroom.id}
                                            value={String(showroom.id)}
                                        >
                                            {showroom.name}
                                        </SelectItem>
                                    ),
                                )}
                            </SelectGroup>
                        </SelectContent>
                    </Select>
                </FormField>
                <FormField
                    label="Тип"
                    error={
                        errors.kind ??
                        (sharedNotAllowed
                            ? 'Для отдела салона нужно выбрать салон.'
                            : undefined)
                    }
                >
                    <Select
                        value={form.data.kind}
                        onValueChange={(value) => form.setData('kind', value)}
                        disabled={Boolean(department)}
                    >
                        <SelectTrigger id="department-kind" className="w-full">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent position="popper">
                            <SelectGroup>
                                {kinds.map((kind) => (
                                    <SelectItem
                                        key={kind.value}
                                        value={kind.value}
                                    >
                                        {kind.label}
                                    </SelectItem>
                                ))}
                            </SelectGroup>
                        </SelectContent>
                    </Select>
                </FormField>
                <FormField label="Название" error={form.errors.name}>
                    <Input
                        id="department-name"
                        value={form.data.name}
                        onChange={(event) =>
                            form.setData('name', event.target.value)
                        }
                        required
                        maxLength={255}
                    />
                </FormField>
                {department && (
                    <TechnicalCode
                        id="department-code"
                        code={department.code}
                    />
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
                <Button
                    type="submit"
                    disabled={
                        form.processing ||
                        Boolean(sharedNotAllowed && !department)
                    }
                >
                    {form.processing && <Spinner />}
                    {department ? 'Сохранить' : 'Создать'}
                </Button>
            </DialogFooter>
        </form>
    );
}
