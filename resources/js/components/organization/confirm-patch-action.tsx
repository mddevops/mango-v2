import { router } from '@inertiajs/react';
import type { ReactNode } from 'react';
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
    url: string;
    payload: Record<string, string | number | boolean>;
    label: string;
    title: string;
    description: string;
    icon?: ReactNode;
    destructive?: boolean;
};

export function ConfirmPatchAction({
    url,
    payload,
    label,
    title,
    description,
    icon,
    destructive,
}: Props) {
    const [open, setOpen] = useState(false);
    const [processing, setProcessing] = useState(false);

    const submit = () => {
        if (processing) {
            return;
        }

        router.patch(url, payload, {
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
                size="sm"
                disabled={processing}
                onClick={() => setOpen(true)}
            >
                {icon}
                {label}
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
                        variant={destructive ? 'destructive' : 'default'}
                        disabled={processing}
                        onClick={(event) => {
                            event.preventDefault();
                            submit();
                        }}
                    >
                        {processing && <Spinner />}
                        {label}
                    </AlertDialogAction>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}
