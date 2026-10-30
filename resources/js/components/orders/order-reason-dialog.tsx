import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useEffect, useState } from 'react';
import OrderReasonController from '@/actions/App/Http/Controllers/Orders/OrderReasonController';
import FormField from '@/components/form-field';
import { FormAlert } from '@/components/organization/form-alert';
import type { FormDialogControls } from '@/components/organization/form-dialog';
import { FormDialog } from '@/components/organization/form-dialog';
import { CheckboxOption } from '@/components/organization/form-section';
import { TechnicalCode } from '@/components/organization/technical-code';
import { Badge } from '@/components/reui/badge';
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
import { Separator } from '@/components/ui/separator';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { saveOptions } from '@/lib/submit';
import type {
    OrderReasonRow,
    OrderReasonTypeValue,
    ReferenceOption,
} from '@/types';

type Props = {
    open: boolean;
    type: OrderReasonTypeValue;
    singular: string;
    item: OrderReasonRow | null;
    showrooms: ReferenceOption[];
    onOpenChange: (open: boolean) => void;
};

export function OrderReasonDialog({ open, onOpenChange, ...props }: Props) {
    return (
        <FormDialog
            open={open}
            onOpenChange={onOpenChange}
            className="max-h-[90vh] overflow-y-auto sm:max-w-xl"
        >
            {(controls) => <OrderReasonForm {...props} controls={controls} />}
        </FormDialog>
    );
}

function OrderReasonForm({
    type,
    singular,
    item,
    showrooms,
    controls,
}: Omit<Props, 'open' | 'onOpenChange'> & { controls: FormDialogControls }) {
    const { setBusy, setDirty, requestClose, close } = controls;
    const [failure, setFailure] = useState<string | null>(null);
    const form = useForm({
        name: item?.name ?? '',
        description: item?.description ?? '',
        comment_required: item?.comment_required ?? false,
        all_showrooms: item?.all_showrooms ?? true,
        showroom_ids: item?.showrooms.map((showroom) => showroom.id) ?? [],
        lock_version: item?.lock_version ?? 0,
    });

    useEffect(() => setDirty(form.isDirty), [form.isDirty, setDirty]);

    const visibleShowrooms = showrooms.filter(
        (showroom) =>
            showroom.active || form.data.showroom_ids.includes(showroom.id),
    );

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
            comment_required: data.comment_required,
            all_showrooms: data.all_showrooms,
            showroom_ids: data.all_showrooms ? [] : data.showroom_ids,
            ...(item ? { lock_version: data.lock_version } : {}),
        }));

        if (item) {
            form.put(
                OrderReasonController.update.url({ type, id: item.id }),
                options,
            );
        } else {
            form.post(OrderReasonController.store.url(type), options);
        }
    };

    const errors = form.errors as Record<string, string | undefined>;

    return (
        <form onSubmit={submit} className="flex flex-col gap-4" noValidate>
            <DialogHeader>
                <DialogTitle>
                    {item ? `${singular}: изменение` : `${singular}: новая`}
                </DialogTitle>
                <DialogDescription>
                    Причины не удаляются — их можно отключить. Порядок в
                    списках меняется перетаскиванием строк таблицы.
                </DialogDescription>
            </DialogHeader>

            <FormAlert conflict={form.errors.lock_version} failure={failure} />

            <div className="flex flex-col gap-4">
                <FormField label="Название" error={form.errors.name}>
                    <Input
                        id="order-reason-name"
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
                        id="order-reason-description"
                        value={form.data.description}
                        onChange={(event) =>
                            form.setData('description', event.target.value)
                        }
                        maxLength={1000}
                        rows={2}
                    />
                </FormField>
                <CheckboxOption
                    id="order-reason-comment"
                    checked={form.data.comment_required}
                    onChange={(checked) =>
                        form.setData('comment_required', checked)
                    }
                    label="Обязателен поясняющий комментарий"
                />
                {item?.code && (
                    <TechnicalCode id="order-reason-code" code={item.code} />
                )}
            </div>

            <Separator />

            <section className="flex flex-col gap-3">
                <Field orientation="horizontal">
                    <FieldContent>
                        <FieldLabel htmlFor="order-reason-all-showrooms">
                            Все салоны
                        </FieldLabel>
                        <FieldDescription>
                            Если выключено, причина предлагается только в
                            выбранных салонах.
                        </FieldDescription>
                    </FieldContent>
                    <Switch
                        id="order-reason-all-showrooms"
                        checked={form.data.all_showrooms}
                        onCheckedChange={(checked) =>
                            form.setData('all_showrooms', checked)
                        }
                    />
                </Field>
                {!form.data.all_showrooms && (
                    <div className="flex flex-col gap-2">
                        <div className="grid gap-2 sm:grid-cols-2">
                            {visibleShowrooms.map((showroom) => (
                                <CheckboxOption
                                    key={showroom.id}
                                    id={`order-reason-showroom-${showroom.id}`}
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
                                                      (id) =>
                                                          id !== showroom.id,
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
                        {errors.showroom_ids && (
                            <p className="text-sm text-destructive">
                                {errors.showroom_ids}
                            </p>
                        )}
                    </div>
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
