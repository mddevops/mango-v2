import type { VisitOptions } from '@inertiajs/core';
import { router } from '@inertiajs/react';
import { toast } from 'sonner';
import { saveFailureMessage } from '@/components/organization/form-alert';

/**
 * Visit options for a save request: validation errors stay on the form,
 * transport and server failures become a visible message instead of a silent no-op.
 */
export function saveOptions(
    setFailure: (message: string | null) => void,
    extra: Partial<VisitOptions> = {},
): Partial<VisitOptions> {
    return {
        preserveScroll: true,
        ...extra,
        onStart: (visit) => {
            setFailure(null);
            extra.onStart?.(visit);
        },
        onHttpException: () => {
            setFailure(saveFailureMessage);
            toast.error(saveFailureMessage);

            return false;
        },
        onNetworkError: () => {
            setFailure(saveFailureMessage);
            toast.error(saveFailureMessage);

            return false;
        },
    };
}

/**
 * Saves the order of the rows on the page after a drag. A refused or failed
 * request reloads the rows, so the table returns to the order the server keeps.
 */
export function saveRowOrder(url: string, ids: number[], only: string[]) {
    const restore = (message: string) => {
        toast.error(message);
        router.reload({ only });
    };

    router.patch(
        url,
        { ids },
        {
            only,
            preserveScroll: true,
            preserveState: true,
            onError: (errors) => restore(firstError(errors) ?? saveFailureMessage),
            onHttpException: () => {
                restore(saveFailureMessage);

                return false;
            },
            onNetworkError: () => {
                restore(saveFailureMessage);

                return false;
            },
        },
    );
}

export function firstError(
    errors: Record<string, string | undefined>,
): string | undefined {
    return Object.values(errors).find((message) => Boolean(message));
}
