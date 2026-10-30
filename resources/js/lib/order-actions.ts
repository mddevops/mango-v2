import { router } from '@inertiajs/react';
import { toast } from 'sonner';
import { saveFailureMessage } from '@/components/organization/form-alert';
import { firstError } from '@/lib/submit';

/**
 * Posts a one-click order action (take, distribute). The server checks the rights and
 * the current state again; a refusal is shown as a toast and the data is reloaded.
 */
export function postOrderAction(
    url: string,
    options: { only?: string[]; onBusy?: (busy: boolean) => void } = {},
) {
    const fail = () => {
        toast.error(saveFailureMessage);

        return false as const;
    };

    router.post(
        url,
        {},
        {
            only: options.only,
            preserveScroll: true,
            preserveState: true,
            onStart: () => options.onBusy?.(true),
            onFinish: () => options.onBusy?.(false),
            onError: (errors) => {
                toast.error(firstError(errors) ?? saveFailureMessage);
                router.reload({ only: options.only });
            },
            onHttpException: fail,
            onNetworkError: fail,
        },
    );
}
