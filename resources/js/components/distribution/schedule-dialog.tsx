import { router, useForm } from '@inertiajs/react';
import { Copy, Plus, Trash2 } from 'lucide-react';
import type { FormEvent } from 'react';
import { useEffect, useState } from 'react';
import FormField from '@/components/form-field';
import { FormAlert } from '@/components/organization/form-alert';
import type { FormDialogControls } from '@/components/organization/form-dialog';
import { FormDialog } from '@/components/organization/form-dialog';
import { CheckboxOption } from '@/components/organization/form-section';
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
import { Separator } from '@/components/ui/separator';
import { Spinner } from '@/components/ui/spinner';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { firstError, saveOptions } from '@/lib/submit';
import {
    copy as copySchedule,
    update as updateSchedule,
} from '@/routes/distribution/schedules';
import type {
    DistributionMemberRow,
    ReferenceOption,
    ScheduleException,
} from '@/types';

export const WEEKDAYS = ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'];

type Props = {
    open: boolean;
    member: DistributionMemberRow | null;
    showroom: ReferenceOption | null;
    onOpenChange: (open: boolean) => void;
};

export function ScheduleDialog({ open, onOpenChange, member, showroom }: Props) {
    return (
        <FormDialog
            open={open}
            onOpenChange={onOpenChange}
            className="max-h-[90vh] overflow-y-auto sm:max-w-xl"
        >
            {(controls) =>
                member &&
                showroom && (
                    <ScheduleForm
                        member={member}
                        showroom={showroom}
                        controls={controls}
                    />
                )
            }
        </FormDialog>
    );
}

function ScheduleForm({
    member,
    showroom,
    controls,
}: {
    member: DistributionMemberRow;
    showroom: ReferenceOption;
    controls: FormDialogControls;
}) {
    const { setBusy, setDirty, requestClose, close } = controls;
    const [failure, setFailure] = useState<string | null>(null);
    const [copyFrom, setCopyFrom] = useState<string>('');
    const [copying, setCopying] = useState(false);
    const [copyError, setCopyError] = useState<string | null>(null);
    const form = useForm({
        participates: member.participates,
        weekdays: member.weekdays,
        exceptions: member.exceptions as ScheduleException[],
        lock_version: member.lock_version,
    });

    useEffect(() => setDirty(form.isDirty), [form.isDirty, setDirty]);

    const submit = (event: FormEvent) => {
        event.preventDefault();

        if (form.processing) {
            return;
        }

        form.put(
            updateSchedule.url({ showroom: showroom.id, user: member.id }),
            saveOptions(setFailure, {
                preserveState: true,
                onStart: () => setBusy(true),
                onFinish: () => setBusy(false),
                onSuccess: () => close(),
            }),
        );
    };

    const copy = () => {
        if (copying || copyFrom === '') {
            return;
        }

        router.post(
            copySchedule.url({ showroom: showroom.id, user: member.id }),
            { from_showroom_id: Number(copyFrom), lock_version: member.lock_version },
            saveOptions(setFailure, {
                preserveState: true,
                onStart: () => {
                    setCopyError(null);
                    setCopying(true);
                    setBusy(true);
                },
                onFinish: () => {
                    setCopying(false);
                    setBusy(false);
                },
                onError: (errors) => setCopyError(firstError(errors) ?? null),
                onSuccess: () => close(),
            }),
        );
    };

    const errors = form.errors as Record<string, string | undefined>;
    const setException = (index: number, value: Partial<ScheduleException>) =>
        form.setData(
            'exceptions',
            form.data.exceptions.map((exception, current) =>
                current === index ? { ...exception, ...value } : exception,
            ),
        );
    const exceptionError = Object.entries(errors).find(([key]) =>
        key.startsWith('exceptions'),
    )?.[1];

    return (
        <form onSubmit={submit} className="flex flex-col gap-4" noValidate>
            <DialogHeader>
                <DialogTitle>
                    Распределение: {member.name} · {showroom.name}
                </DialogTitle>
                <DialogDescription>
                    Участие и график действуют только в салоне «{showroom.name}
                    ». Обратные звонки этого салона сотрудник получает в
                    рабочие по графику дни. Уже назначенные заявки не
                    переназначаются.
                </DialogDescription>
            </DialogHeader>

            <FormAlert conflict={form.errors.lock_version} failure={failure} />

            <CheckboxOption
                id="distribution-participates"
                checked={form.data.participates}
                onChange={(checked) => form.setData('participates', checked)}
                label="Участвует в распределении"
                hint={
                    member.active
                        ? undefined
                        : 'Сотрудник отключён и в распределение не попадёт.'
                }
            />

            {errors.participates && (
                <p className="text-sm text-destructive">
                    {errors.participates}
                </p>
            )}

            {member.copy_from.length > 0 && (
                <>
                    <Separator />
                    <section className="flex flex-col gap-2">
                        <h3 className="text-sm font-semibold">
                            Скопировать график из другого салона
                        </h3>
                        <p className="text-xs text-muted-foreground">
                            Рабочие дни и исключения заменятся копией и дальше
                            меняются независимо. Участие не меняется,
                            несохранённые правки формы не сохраняются.
                        </p>
                        <div className="flex items-end gap-2">
                            <FormField label="Салон" className="flex-1">
                                <Select
                                    value={copyFrom}
                                    onValueChange={setCopyFrom}
                                >
                                    <SelectTrigger
                                        id="distribution-copy-from"
                                        className="w-full"
                                    >
                                        <SelectValue placeholder="Выберите салон" />
                                    </SelectTrigger>
                                    <SelectContent position="popper">
                                        <SelectGroup>
                                            {member.copy_from.map((other) => (
                                                <SelectItem
                                                    key={other.id}
                                                    value={String(other.id)}
                                                >
                                                    {other.name}
                                                </SelectItem>
                                            ))}
                                        </SelectGroup>
                                    </SelectContent>
                                </Select>
                            </FormField>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={copy}
                                disabled={copying || copyFrom === ''}
                            >
                                {copying ? <Spinner /> : <Copy />}
                                Скопировать
                            </Button>
                        </div>
                        {copyError && (
                            <p className="text-sm text-destructive">
                                {copyError}
                            </p>
                        )}
                    </section>
                </>
            )}

            <Separator />

            <section className="flex flex-col gap-2">
                <h3 className="text-sm font-semibold">Рабочие дни недели</h3>
                <p className="text-xs text-muted-foreground">
                    День проверяется по расчётной дате заявки. Без отмеченных
                    дней сотрудник не работает.
                </p>
                <ToggleGroup
                    type="multiple"
                    variant="outline"
                    spacing={1}
                    value={WEEKDAYS.filter(
                        (_, index) => form.data.weekdays[index],
                    )}
                    onValueChange={(days) =>
                        form.setData(
                            'weekdays',
                            WEEKDAYS.map((day) => days.includes(day)),
                        )
                    }
                    aria-label="Рабочие дни недели"
                >
                    {WEEKDAYS.map((day) => (
                        <ToggleGroupItem
                            key={day}
                            value={day}
                            className="w-10"
                        >
                            {day}
                        </ToggleGroupItem>
                    ))}
                </ToggleGroup>
            </section>

            <Separator />

            <section className="flex flex-col gap-2">
                <div className="flex items-center justify-between gap-2">
                    <h3 className="text-sm font-semibold">Исключения по датам</h3>
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        onClick={() =>
                            form.setData('exceptions', [
                                ...form.data.exceptions,
                                { date: '', works: false },
                            ])
                        }
                    >
                        <Plus />
                        Добавить дату
                    </Button>
                </div>
                <p className="text-xs text-muted-foreground">
                    Исключение важнее недельного графика.
                </p>
                {form.data.exceptions.map((exception, index) => (
                    <div key={index} className="flex items-end gap-2">
                        <FormField label="Дата" className="flex-1">
                            <Input
                                id={`distribution-exception-date-${index}`}
                                type="date"
                                value={exception.date}
                                onChange={(event) =>
                                    setException(index, {
                                        date: event.target.value,
                                    })
                                }
                            />
                        </FormField>
                        <FormField label="В этот день" className="flex-1">
                            <Select
                                value={exception.works ? 'works' : 'off'}
                                onValueChange={(value) =>
                                    setException(index, {
                                        works: value === 'works',
                                    })
                                }
                            >
                                <SelectTrigger
                                    id={`distribution-exception-works-${index}`}
                                    className="w-full"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent position="popper">
                                    <SelectGroup>
                                        <SelectItem value="works">
                                            Работает
                                        </SelectItem>
                                        <SelectItem value="off">
                                            Выходной
                                        </SelectItem>
                                    </SelectGroup>
                                </SelectContent>
                            </Select>
                        </FormField>
                        <Button
                            type="button"
                            size="icon"
                            variant="ghost"
                            aria-label="Удалить исключение"
                            onClick={() =>
                                form.setData(
                                    'exceptions',
                                    form.data.exceptions.filter(
                                        (_, current) => current !== index,
                                    ),
                                )
                            }
                        >
                            <Trash2 />
                        </Button>
                    </div>
                ))}
                {exceptionError && (
                    <p className="text-sm text-destructive">{exceptionError}</p>
                )}
            </section>

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
                    Сохранить
                </Button>
            </DialogFooter>
        </form>
    );
}
