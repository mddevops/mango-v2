import { router } from '@inertiajs/react';
import { Power, PowerOff } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { saveFailureMessage } from '@/components/organization/form-alert';
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
import { Spinner } from '@/components/ui/spinner';
import { firstError } from '@/lib/submit';

type Props = {
    active: boolean;
    url: string;
    lockVersion: number;
    title: string;
    description: string;
    activateLabel?: string;
    deactivateLabel?: string;
    size?: 'sm' | 'default';
};

/**
 * Activates immediately; deactivation needs an explicit confirmation.
 * Sends the row version so a stale table row cannot overwrite a newer change.
 */
export function StatusAction({
    active,
    url,
    lockVersion,
    title,
    description,
    activateLabel = 'Активировать',
    deactivateLabel = 'Отключить',
    size = 'sm',
}: Props) {
    const [open, setOpen] = useState(false);
    const [processing, setProcessing] = useState(false);

    const submit = (next: boolean) => {
        if (processing) {
            return;
        }

        router.patch(
            url,
            { active: next, lock_version: lockVersion },
            {
                preserveScroll: true,
                preserveState: true,
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
            },
        );
    };

    if (!active) {
        return (
            <Button
                type="button"
                variant="outline"
                size={size}
                disabled={processing}
                onClick={() => submit(true)}
            >
                {processing ? <Spinner /> : <Power />}
                {activateLabel}
            </Button>
        );
    }

    return (
        <AlertDialog
            open={open}
            onOpenChange={(next) => !processing && setOpen(next)}
        >
            <Button
                type="button"
                variant="outline"
                size={size}
                disabled={processing}
                onClick={() => setOpen(true)}
            >
                <PowerOff />
                {deactivateLabel}
            </Button>
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>{title}</AlertDialogTitle>
                    <AlertDialogDescription>
                        {description}
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
                            submit(false);
                        }}
                    >
                        {processing && <Spinner />}
                        {deactivateLabel}
                    </AlertDialogAction>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}
