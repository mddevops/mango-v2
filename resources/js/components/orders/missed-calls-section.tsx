import { router } from '@inertiajs/react';
import { Check, CheckCheck, PhoneMissed } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { saveFailureMessage } from '@/components/organization/form-alert';
import { FormSection } from '@/components/organization/form-section';
import { Badge } from '@/components/reui/badge';
import { Button } from '@/components/ui/button';
import { formatMoment } from '@/lib/order-format';
import { firstError } from '@/lib/submit';
import { handle } from '@/routes/orders/missed-calls';
import type { OrderMissedCall } from '@/types';

const ONLY = ['missedCalls'];

/**
 * Unhandled missed calls of the order. Only an explicit action closes them: «Обработать
 * все» sends exactly the calls shown, so one that came meanwhile stays open.
 */
export function MissedCallsSection({
    orderId,
    calls,
    canHandle,
}: {
    orderId: number;
    calls: OrderMissedCall[];
    canHandle: boolean;
}) {
    const [busy, setBusy] = useState(false);

    if (calls.length === 0) {
        return null;
    }

    const submit = (ids: number[]) => {
        const fail = () => {
            toast.error(saveFailureMessage);

            return false as const;
        };

        router.post(
            handle.url(orderId),
            { call_ids: ids },
            {
                only: ONLY,
                preserveScroll: true,
                preserveState: true,
                onStart: () => setBusy(true),
                onFinish: () => setBusy(false),
                onError: (errors) => {
                    toast.error(firstError(errors) ?? saveFailureMessage);
                    router.reload({ only: ONLY });
                },
                onHttpException: fail,
                onNetworkError: fail,
            },
        );
    };

    return (
        <FormSection
            title="Пропущенные звонки"
            description="Не обработаны. Отметка ставится только вручную."
            actions={
                canHandle &&
                calls.length > 1 && (
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        disabled={busy}
                        onClick={() => submit(calls.map((call) => call.id))}
                    >
                        <CheckCheck />
                        Обработать все
                    </Button>
                )
            }
        >
            <ul className="flex flex-col gap-2">
                {calls.map((call) => (
                    <li
                        key={call.id}
                        className="flex flex-wrap items-center justify-between gap-2 rounded-lg border px-3 py-2"
                    >
                        <span className="flex min-w-0 flex-wrap items-center gap-2 text-sm">
                            <PhoneMissed className="size-4 shrink-0 text-destructive" />
                            <span className="tabular-nums">
                                {formatMoment(call.started_at)}
                            </span>
                            <Badge variant="destructive-light">Пропущен</Badge>
                            {(call.source || call.line) && (
                                <span className="text-xs text-muted-foreground">
                                    {[
                                        call.source &&
                                            `источник: ${call.source}`,
                                        call.line && `линия: ${call.line}`,
                                    ]
                                        .filter(Boolean)
                                        .join(' · ')}
                                </span>
                            )}
                        </span>
                        {canHandle && (
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                disabled={busy}
                                onClick={() => submit([call.id])}
                            >
                                <Check />
                                Отметить обработанным
                            </Button>
                        )}
                    </li>
                ))}
            </ul>
        </FormSection>
    );
}
