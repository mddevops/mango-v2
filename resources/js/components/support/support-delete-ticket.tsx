import { router } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
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
import { destroy as destroyTicket } from '@/routes/support';

export function SupportDeleteTicket({
    ticket,
    only,
}: {
    ticket: { id: number; subject: string };
    /** Page props to reload after the request is deleted. */
    only: string[];
}) {
    const [open, setOpen] = useState(false);
    const [processing, setProcessing] = useState(false);

    const submit = () => {
        if (processing) {
            return;
        }

        router.delete(destroyTicket.url(ticket.id), {
            only: [...only, 'supportUnread'],
            preserveScroll: true,
            preserveState: true,
            onStart: () => setProcessing(true),
            onFinish: () => {
                setProcessing(false);
                setOpen(false);
            },
            onError: (errors) =>
                toast.error(firstError(errors) ?? saveFailureMessage),
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
                variant="ghost"
                size="icon-sm"
                aria-label={`Удалить обращение №${ticket.id}`}
                className="text-destructive hover:bg-destructive/10 hover:text-destructive"
                onClick={(event) => {
                    event.stopPropagation();
                    setOpen(true);
                }}
            >
                <Trash2 />
            </Button>
            <AlertDialogContent onClick={(event) => event.stopPropagation()}>
                <AlertDialogHeader>
                    <AlertDialogTitle>Удалить обращение?</AlertDialogTitle>
                    <AlertDialogDescription>
                        Обращение «{ticket.subject}» исчезнет из списка у автора
                        и у поддержки.
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
                            submit();
                        }}
                    >
                        {processing && <Spinner />}
                        Удалить
                    </AlertDialogAction>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}
