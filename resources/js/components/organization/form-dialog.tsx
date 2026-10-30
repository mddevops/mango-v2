import type { ReactNode } from 'react';
import { useState } from 'react';
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
import { Dialog, DialogContent } from '@/components/ui/dialog';

export type FormDialogControls = {
    /** Blocks closing while a request is in flight. */
    setBusy: (busy: boolean) => void;
    /** Unsaved changes make every close path ask for confirmation first. */
    setDirty: (dirty: boolean) => void;
    /** Close as if the user dismissed the dialog (asks when dirty). */
    requestClose: () => void;
    /** Close unconditionally, e.g. after a successful save. */
    close: () => void;
};

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    children: (controls: FormDialogControls) => ReactNode;
    className?: string;
};

export function FormDialog({
    open,
    onOpenChange,
    children,
    className = 'sm:max-w-lg',
}: Props) {
    const [busy, setBusy] = useState(false);
    const [dirty, setDirty] = useState(false);
    const [confirming, setConfirming] = useState(false);

    const close = () => {
        setDirty(false);
        setConfirming(false);
        onOpenChange(false);
    };

    const requestClose = () => {
        if (busy) {
            return;
        }

        if (dirty) {
            setConfirming(true);

            return;
        }

        close();
    };

    return (
        <Dialog
            open={open}
            onOpenChange={(next) =>
                next ? onOpenChange(true) : requestClose()
            }
        >
            <DialogContent className={className}>
                {children({ setBusy, setDirty, requestClose, close })}

                <AlertDialog open={confirming} onOpenChange={setConfirming}>
                    <AlertDialogContent size="sm">
                        <AlertDialogHeader>
                            <AlertDialogTitle>
                                Закрыть без сохранения?
                            </AlertDialogTitle>
                            <AlertDialogDescription>
                                Введённые изменения не сохранятся.
                            </AlertDialogDescription>
                        </AlertDialogHeader>
                        <AlertDialogFooter>
                            <AlertDialogCancel>Продолжить</AlertDialogCancel>
                            <AlertDialogAction
                                variant="destructive"
                                onClick={close}
                            >
                                Закрыть
                            </AlertDialogAction>
                        </AlertDialogFooter>
                    </AlertDialogContent>
                </AlertDialog>
            </DialogContent>
        </Dialog>
    );
}
