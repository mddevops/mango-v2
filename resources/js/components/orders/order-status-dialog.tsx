import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useEffect, useState } from 'react';
import OrderStatusController from '@/actions/App/Http/Controllers/Orders/OrderStatusController';
import FormField from '@/components/form-field';
import { FormAlert } from '@/components/organization/form-alert';
import type { FormDialogControls } from '@/components/organization/form-dialog';
import { FormDialog } from '@/components/organization/form-dialog';
import { CheckboxOption } from '@/components/organization/form-section';
import { TechnicalCode } from '@/components/organization/technical-code';
import { OrderStatusBadge } from '@/components/orders/order-status-badge';
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
import { Textarea } from '@/components/ui/textarea';
import { saveOptions } from '@/lib/submit';
import type {
    Option,
    OrderReasonTypeValue,
    OrderStatusColor,
    OrderStatusRow,
} from '@/types';

const NO_REASON = 'none';

type Props = {
    open: boolean;
    item: OrderStatusRow | null;
    colors: Option[];
    reasonTypes: Option[];
    onOpenChange: (open: boolean) => void;
};

export function OrderStatusDialog({ open, onOpenChange, ...props }: Props) {
    return (
        <FormDialog
            open={open}
            onOpenChange={onOpenChange}
            className="max-h-[90vh] overflow-y-auto sm:max-w-xl"
        >
            {(controls) => <OrderStatusForm {...props} controls={controls} />}
        </FormDialog>
    );
}

function OrderStatusForm({
    item,
    colors,
    reasonTypes,
    controls,
}: Omit<Props, 'open' | 'onOpenChange'> & { controls: FormDialogControls }) {
    const { setBusy, setDirty, requestClose, close } = controls;
    const [failure, setFailure] = useState<string | null>(null);
    const form = useForm({
        name: item?.name ?? '',
        description: item?.description ?? '',
        color: (item?.color ?? 'gray') as OrderStatusColor,
        show_as_tab: item?.show_as_tab ?? false,
        requires_callback_at: item?.requires_callback_at ?? false,
        requires_arrival_at: item?.requires_arrival_at ?? false,
        required_reason: (item?.required_reason ?? NO_REASON) as
            | OrderReasonTypeValue
            | typeof NO_REASON,
        lock_version: item?.lock_version ?? 0,
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
            description: data.description.trim() === '' ? null : data.description,
            color: data.color,
            show_as_tab: data.show_as_tab,
            requires_callback_at: data.requires_callback_at,
            requires_arrival_at: data.requires_arrival_at,
            required_reason:
                data.required_reason === NO_REASON
                    ? null
                    : data.required_reason,
            ...(item ? { lock_version: data.lock_version } : {}),
        }));

        if (item) {
            form.put(OrderStatusController.update.url(item.id), options);
        } else {
            form.post(OrderStatusController.store.url(), options);
        }
    };

    const errors = form.errors as Record<string, string | undefined>;

    return (
        <form onSubmit={submit} className="flex flex-col gap-4" noValidate>
            <DialogHeader>
                <DialogTitle>
                    {item ? 'Статус заявки: изменение' : 'Статус заявки: новый'}
                </DialogTitle>
                <DialogDescription>
                    Статусы не удаляются — их можно отключить. Порядок в
                    списках меняется перетаскиванием строк таблицы.
                </DialogDescription>
            </DialogHeader>

            <FormAlert conflict={form.errors.lock_version} failure={failure} />

            <div className="flex flex-col gap-4">
                <FormField label="Название" error={form.errors.name}>
                    <Input
                        id="order-status-name"
                        value={form.data.name}
                        onChange={(event) =>
                            form.setData('name', event.target.value)
                        }
                        required
                        maxLength={100}
                        autoFocus
                    />
                </FormField>
                <FormField label="Описание" error={form.errors.description}>
                    <Textarea
                        id="order-status-description"
                        value={form.data.description}
                        onChange={(event) =>
                            form.setData('description', event.target.value)
                        }
                        maxLength={1000}
                        rows={2}
                    />
                </FormField>
                <div className="grid gap-4">
                    <FormField label="Цвет" error={form.errors.color}>
                        <Select
                            value={form.data.color}
                            onValueChange={(value) =>
                                form.setData('color', value as OrderStatusColor)
                            }
                        >
                            <SelectTrigger
                                id="order-status-color"
                                className="w-full"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent position="popper">
                                <SelectGroup>
                                    {colors.map((color) => (
                                        <SelectItem
                                            key={color.value}
                                            value={color.value}
                                        >
                                            <OrderStatusBadge
                                                color={
                                                    color.value as OrderStatusColor
                                                }
                                            >
                                                {color.label}
                                            </OrderStatusBadge>
                                        </SelectItem>
                                    ))}
                                </SelectGroup>
                            </SelectContent>
                        </Select>
                    </FormField>
                </div>
                <CheckboxOption
                    id="order-status-tab"
                    checked={form.data.show_as_tab}
                    onChange={(checked) => form.setData('show_as_tab', checked)}
                    label="Показывать вкладкой над списком заявок"
                />
                {item?.code && (
                    <TechnicalCode id="order-status-code" code={item.code} />
                )}
            </div>

            <Separator />

            <section className="flex flex-col gap-3">
                <div className="flex flex-col gap-1">
                    <h3 className="text-sm font-semibold">
                        Требования при выборе статуса
                    </h3>
                    <p className="text-xs text-muted-foreground">
                        Настройки сохраняются сейчас, а применяться к заявкам
                        начнут после появления раздела заявок.
                        {item?.is_initial &&
                            ' Начальный статус не может иметь требований: его получают все новые заявки автоматически.'}
                    </p>
                </div>
                <CheckboxOption
                    id="order-status-callback"
                    checked={form.data.requires_callback_at}
                    onChange={(checked) =>
                        form.setData('requires_callback_at', checked)
                    }
                    label="Указать дату и время перезвона"
                />
                <CheckboxOption
                    id="order-status-arrival"
                    checked={form.data.requires_arrival_at}
                    onChange={(checked) =>
                        form.setData('requires_arrival_at', checked)
                    }
                    label="Указать дату и время планируемого приезда"
                />
                <FormField
                    label="Указать причину"
                    error={errors.required_reason}
                >
                    <Select
                        value={form.data.required_reason}
                        onValueChange={(value) =>
                            form.setData(
                                'required_reason',
                                value as OrderReasonTypeValue,
                            )
                        }
                    >
                        <SelectTrigger
                            id="order-status-reason"
                            className="w-full"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent position="popper">
                            <SelectGroup>
                                <SelectItem value={NO_REASON}>
                                    Не требуется
                                </SelectItem>
                                {reasonTypes.map((type) => (
                                    <SelectItem
                                        key={type.value}
                                        value={type.value}
                                    >
                                        {type.label}
                                    </SelectItem>
                                ))}
                            </SelectGroup>
                        </SelectContent>
                    </Select>
                </FormField>
                {errors.requirements && (
                    <p className="text-sm text-destructive">
                        {errors.requirements}
                    </p>
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
                    {item ? 'Сохранить' : 'Создать'}
                </Button>
            </DialogFooter>
        </form>
    );
}
