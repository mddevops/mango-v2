import { Link, router } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import { MoreHorizontal, Power, PowerOff } from 'lucide-react';
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
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Spinner } from '@/components/ui/spinner';
import { firstError } from '@/lib/submit';

type RowAction = {
    label: string;
    icon: LucideIcon;
} & (
    | { href: string; onSelect?: never }
    | { onSelect: () => void; href?: never }
);

type StatusConfig = {
    active: boolean;
    url: string;
    lockVersion: number;
    title: string;
    description: string;
    activateLabel?: string;
    deactivateLabel?: string;
};

/**
 * The confirmation dialog lives outside the menu: Radix unmounts the menu
 * content on close, which would take a nested dialog with it.
 */
export function RowActions({
    label,
    actions = [],
    status,
}: {
    label: string;
    actions?: RowAction[];
    status?: StatusConfig;
}) {
    const [confirming, setConfirming] = useState(false);
    const [processing, setProcessing] = useState(false);

    if (actions.length === 0 && !status) {
        return null;
    }

    const activateLabel = status?.activateLabel ?? 'Активировать';
    const deactivateLabel = status?.deactivateLabel ?? 'Отключить';

    const submitStatus = (next: boolean) => {
        if (!status || processing) {
            return;
        }

        router.patch(
            status.url,
            { active: next, lock_version: status.lockVersion },
            {
                preserveScroll: true,
                preserveState: true,
                onStart: () => setProcessing(true),
                onFinish: () => {
                    setProcessing(false);
                    setConfirming(false);
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

    return (
        <>
            <DropdownMenu modal={false}>
                <DropdownMenuTrigger asChild>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        className="size-7"
                        aria-label={label}
                        disabled={processing}
                    >
                        {processing ? <Spinner /> : <MoreHorizontal />}
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent side="bottom" align="end">
                    {actions.map(
                        ({ label: itemLabel, icon: Icon, href, onSelect }) =>
                            href ? (
                                <DropdownMenuItem key={itemLabel} asChild>
                                    <Link href={href}>
                                        <Icon />
                                        {itemLabel}
                                    </Link>
                                </DropdownMenuItem>
                            ) : (
                                <DropdownMenuItem
                                    key={itemLabel}
                                    onSelect={onSelect}
                                >
                                    <Icon />
                                    {itemLabel}
                                </DropdownMenuItem>
                            ),
                    )}
                    {status && actions.length > 0 && <DropdownMenuSeparator />}
                    {status &&
                        (status.active ? (
                            <DropdownMenuItem
                                variant="destructive"
                                onSelect={() => setConfirming(true)}
                            >
                                <PowerOff />
                                {deactivateLabel}
                            </DropdownMenuItem>
                        ) : (
                            <DropdownMenuItem
                                onSelect={() => submitStatus(true)}
                            >
                                <Power />
                                {activateLabel}
                            </DropdownMenuItem>
                        ))}
                </DropdownMenuContent>
            </DropdownMenu>

            {status?.active && (
                <AlertDialog
                    open={confirming}
                    onOpenChange={(next) => !processing && setConfirming(next)}
                >
                    <AlertDialogContent>
                        <AlertDialogHeader>
                            <AlertDialogTitle>{status.title}</AlertDialogTitle>
                            <AlertDialogDescription>
                                {status.description}
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
                                    submitStatus(false);
                                }}
                            >
                                {processing && <Spinner />}
                                {deactivateLabel}
                            </AlertDialogAction>
                        </AlertDialogFooter>
                    </AlertDialogContent>
                </AlertDialog>
            )}
        </>
    );
}
