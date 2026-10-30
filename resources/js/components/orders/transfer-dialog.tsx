import { useForm } from '@inertiajs/react';
import { Send } from 'lucide-react';
import type { FormEvent } from 'react';
import { useEffect, useState } from 'react';
import FormField from '@/components/form-field';
import { FormAlert } from '@/components/organization/form-alert';
import type { FormDialogControls } from '@/components/organization/form-dialog';
import { FormDialog } from '@/components/organization/form-dialog';
import { Button } from '@/components/ui/button';
import {
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { saveOptions } from '@/lib/submit';
import { transfer } from '@/routes/orders';
import type { ReferenceOption } from '@/types';

export type TransferTarget = {
    id: number;
    lock_version: number;
};

type Props = {
    open: boolean;
    order: TransferTarget | null;
    showrooms: ReferenceOption[];
    only?: string[];
    onOpenChange: (open: boolean) => void;
};

export function TransferDialog({
    open,
    order,
    showrooms,
    only,
    onOpenChange,
}: Props) {
    return (
        <FormDialog open={open} onOpenChange={onOpenChange}>
            {(controls) =>
                order && (
                    <TransferForm
                        order={order}
                        showrooms={showrooms}
                        only={only}
                        controls={controls}
                    />
                )
            }
        </FormDialog>
    );
}

function TransferForm({
    order,
    showrooms,
    only,
    controls,
}: {
    order: TransferTarget;
    showrooms: ReferenceOption[];
    only?: string[];
    controls: FormDialogControls;
}) {
    const { setBusy, setDirty, requestClose, close } = controls;
    const [failure, setFailure] = useState<string | null>(null);
    // One id per opened dialog: a repeated or double submit returns the same transfer.
    const [operationId] = useState(() => crypto.randomUUID());
    const form = useForm({
        showroom_id: '',
        comment: '',
        operation_id: operationId,
        lock_version: order.lock_version,
    });

    useEffect(() => setDirty(form.isDirty), [form.isDirty, setDirty]);

    const submit = (event: FormEvent) => {
        event.preventDefault();

        if (form.processing) {
            return;
        }

        form.post(
            transfer.url(order.id),
            saveOptions(setFailure, {
                only,
                preserveState: true,
                onStart: () => setBusy(true),
                onFinish: () => setBusy(false),
                onSuccess: () => close(),
            }),
        );
    };

    return (
        <form onSubmit={submit} className="flex flex-col gap-4" noValidate>
            <DialogHeader>
                <DialogTitle>Передать заявку №{order.id}</DialogTitle>
            </DialogHeader>

            <FormAlert conflict={form.errors.lock_version} failure={failure} />

            <FormField
                label="Салон-получатель"
                error={form.errors.showroom_id ?? form.errors.operation_id}
            >
                <Select
                    value={form.data.showroom_id}
                    onValueChange={(value) => form.setData('showroom_id', value)}
                >
                    <SelectTrigger id="transfer-showroom" className="w-full">
                        <SelectValue placeholder="Выберите салон" />
                    </SelectTrigger>
                    <SelectContent position="popper">
                        <SelectGroup>
                            {showrooms.map((showroom) => (
                                <SelectItem
                                    key={showroom.id}
                                    value={String(showroom.id)}
                                >
                                    {showroom.name}
                                </SelectItem>
                            ))}
                        </SelectGroup>
                    </SelectContent>
                </Select>
            </FormField>

            <FormField
                label="Комментарий для салона-получателя"
                error={form.errors.comment}
            >
                <Textarea
                    id="transfer-comment"
                    value={form.data.comment}
                    maxLength={2000}
                    onChange={(event) =>
                        form.setData('comment', event.target.value)
                    }
                />
            </FormField>

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
                        form.processing || form.data.showroom_id === ''
                    }
                >
                    {form.processing ? <Spinner /> : <Send />}
                    Передать
                </Button>
            </DialogFooter>
        </form>
    );
}
