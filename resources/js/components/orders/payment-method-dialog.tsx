import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useEffect, useState } from 'react';
import PaymentMethodController from '@/actions/App/Http/Controllers/Orders/PaymentMethodController';
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
import { Spinner } from '@/components/ui/spinner';
import { saveOptions } from '@/lib/submit';
import type { PaymentMethodRow } from '@/types';

type Props = {
    open: boolean;
    item: PaymentMethodRow | null;
    onOpenChange: (open: boolean) => void;
};

export function PaymentMethodDialog({ open, onOpenChange, item }: Props) {
    return (
        <FormDialog open={open} onOpenChange={onOpenChange}>
            {(controls) => (
                <PaymentMethodForm item={item} controls={controls} />
            )}
        </FormDialog>
    );
}

function PaymentMethodForm({
    item,
    controls,
}: {
    item: PaymentMethodRow | null;
    controls: FormDialogControls;
}) {
    const { setBusy, setDirty, requestClose, close } = controls;
    const [failure, setFailure] = useState<string | null>(null);
    const form = useForm({
        name: item?.name ?? '',
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
            ...(item ? { lock_version: data.lock_version } : {}),
        }));

        if (item) {
            form.put(PaymentMethodController.update.url(item.id), options);
        } else {
            form.post(PaymentMethodController.store.url(), options);
        }
    };

    return (
        <form onSubmit={submit} className="flex flex-col gap-4" noValidate>
            <DialogHeader>
                <DialogTitle>
                    {item
                        ? 'Способ оплаты: изменение'
                        : 'Способ оплаты: новое значение'}
                </DialogTitle>
                <DialogDescription>
                    Справочник общий для всех салонов. Значения не удаляются —
                    их можно отключить. Порядок в списках меняется
                    перетаскиванием строк таблицы.
                </DialogDescription>
            </DialogHeader>

            <FormAlert conflict={form.errors.lock_version} failure={failure} />

            <div className="flex flex-col gap-4">
                <FormField label="Название" error={form.errors.name}>
                    <Input
                        id="payment-method-name"
                        value={form.data.name}
                        onChange={(event) =>
                            form.setData('name', event.target.value)
                        }
                        required
                        maxLength={100}
                        autoFocus
                    />
                </FormField>
                {item && (
                    <TechnicalCode id="payment-method-code" code={item.code} />
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
                    {item ? 'Сохранить' : 'Создать'}
                </Button>
            </DialogFooter>
        </form>
    );
}
