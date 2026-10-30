import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useEffect, useState } from 'react';
import ShowroomController from '@/actions/App/Http/Controllers/Organization/ShowroomController';
import { BusinessDayTimePicker } from '@/components/business-day-time-picker';
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
import {
    Field,
    FieldContent,
    FieldDescription,
    FieldLabel,
} from '@/components/ui/field';
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
import { Switch } from '@/components/ui/switch';
import { saveOptions } from '@/lib/submit';
import type { Option, ShowroomRow } from '@/types';

type Props = {
    open: boolean;
    showroom: ShowroomRow | null;
    timezones: Option[];
    onOpenChange: (open: boolean) => void;
};

export function ShowroomDialog({
    open,
    showroom,
    timezones,
    onOpenChange,
}: Props) {
    return (
        <FormDialog open={open} onOpenChange={onOpenChange}>
            {(controls) => (
                <ShowroomForm
                    showroom={showroom}
                    timezones={timezones}
                    controls={controls}
                />
            )}
        </FormDialog>
    );
}

function boundaryHint(boundary: string) {
    if (boundary === '00:00') {
        return 'Граница 00:00 совпадает с календарным днём.';
    }

    return boundary === ''
        ? 'Время по часовому поясу салона, 24-часовой формат.'
        : `С ${boundary} начинается следующий расчётный день: событие ровно в ${boundary} относится уже к завтрашней дате.`;
}

function ShowroomForm({
    showroom,
    timezones,
    controls,
}: {
    showroom: ShowroomRow | null;
    timezones: Option[];
    controls: FormDialogControls;
}) {
    const { setBusy, setDirty, requestClose, close } = controls;
    const [failure, setFailure] = useState<string | null>(null);
    const zones =
        showroom && !timezones.some((zone) => zone.value === showroom.timezone)
            ? [
                  ...timezones,
                  { value: showroom.timezone, label: showroom.timezone },
              ]
            : timezones;
    const form = useForm({
        name: showroom?.name ?? '',
        address: showroom?.address ?? '',
        phone: showroom?.phone ?? '',
        timezone: showroom?.timezone ?? 'Europe/Moscow',
        business_day_boundary_enabled:
            showroom?.business_day_boundary_enabled ?? true,
        business_day_boundary: showroom?.business_day_boundary ?? '19:00',
        lock_version: showroom?.lock_version ?? 0,
    });
    const errors = form.errors as Record<string, string | undefined>;
    const zoneShort =
        zones
            .find((zone) => zone.value === form.data.timezone)
            ?.label.split(/[,(]/)[0]
            .trim() ?? form.data.timezone;

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

        if (showroom) {
            form.put(ShowroomController.update.url(showroom.id), options);
        } else {
            form.transform(({ lock_version: _version, ...data }) => data);
            form.post(ShowroomController.store.url(), options);
        }
    };

    return (
        <form onSubmit={submit} className="flex flex-col gap-4" noValidate>
            <DialogHeader>
                <DialogTitle>
                    {showroom ? 'Изменить салон' : 'Новый салон'}
                </DialogTitle>
                <DialogDescription>
                    {showroom
                        ? 'Измените данные салона.'
                        : 'Укажите название и контакты салона.'}
                </DialogDescription>
            </DialogHeader>

            <FormAlert conflict={form.errors.lock_version} failure={failure} />

            <div className="flex flex-col gap-4">
                <FormField label="Название" error={form.errors.name}>
                    <Input
                        id="showroom-name"
                        value={form.data.name}
                        onChange={(event) =>
                            form.setData('name', event.target.value)
                        }
                        required
                        maxLength={255}
                        autoFocus
                    />
                </FormField>
                <FormField label="Адрес" error={form.errors.address}>
                    <Input
                        id="showroom-address"
                        value={form.data.address}
                        onChange={(event) =>
                            form.setData('address', event.target.value)
                        }
                        maxLength={255}
                    />
                </FormField>
                <FormField label="Телефон" error={form.errors.phone}>
                    <Input
                        id="showroom-phone"
                        type="tel"
                        value={form.data.phone}
                        onChange={(event) =>
                            form.setData('phone', event.target.value)
                        }
                        maxLength={50}
                        placeholder="+7 495 000-00-00"
                    />
                </FormField>
                {showroom && (
                    <TechnicalCode id="showroom-code" code={showroom.code} />
                )}
            </div>

            <Separator />

            <section className="flex flex-col gap-4">
                <div className="flex flex-col gap-1">
                    <h3 className="text-sm font-semibold">Расчётный день</h3>
                    <p className="text-xs text-muted-foreground">
                        Определяет, к какому дню относятся события салона.
                        Все источники салона используют эти настройки.
                    </p>
                </div>
                <FormField label="Часовой пояс" error={form.errors.timezone}>
                    <Select
                        value={form.data.timezone}
                        onValueChange={(value) =>
                            form.setData('timezone', value)
                        }
                    >
                        <SelectTrigger
                            id="showroom-timezone"
                            className="w-full"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent position="popper" className="max-h-72">
                            <SelectGroup>
                                {zones.map((zone) => (
                                    <SelectItem
                                        key={zone.value}
                                        value={zone.value}
                                    >
                                        {zone.label}
                                    </SelectItem>
                                ))}
                            </SelectGroup>
                        </SelectContent>
                    </Select>
                </FormField>
                <Field orientation="horizontal">
                    <FieldContent>
                        <FieldLabel htmlFor="showroom-boundary-enabled">
                            Использовать границу расчётного дня
                        </FieldLabel>
                        <FieldDescription>
                            Если выключено, расчётный день совпадает с
                            календарным: с 00:00 до 00:00 следующего дня по
                            времени салона.
                        </FieldDescription>
                    </FieldContent>
                    <Switch
                        id="showroom-boundary-enabled"
                        checked={form.data.business_day_boundary_enabled}
                        onCheckedChange={(checked) =>
                            form.setData(
                                'business_day_boundary_enabled',
                                checked,
                            )
                        }
                    />
                </Field>
                {form.data.business_day_boundary_enabled && (
                    <FormField
                        label="Граница"
                        description={boundaryHint(
                            form.data.business_day_boundary,
                        )}
                        error={errors.business_day_boundary}
                    >
                        <BusinessDayTimePicker
                            id="showroom-boundary"
                            value={form.data.business_day_boundary}
                            onChange={(value) =>
                                form.setData('business_day_boundary', value)
                            }
                            timezone={zoneShort}
                        />
                    </FormField>
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
                    {showroom ? 'Сохранить' : 'Создать'}
                </Button>
            </DialogFooter>
        </form>
    );
}
