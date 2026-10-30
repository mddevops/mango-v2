import { useHttp } from '@inertiajs/react';
import type { SyntheticEvent } from 'react';
import { useState } from 'react';
import { OrderRepeatBadge } from '@/components/orders/order-repeat-badge';
import { RelatedOrdersTable } from '@/components/orders/related-orders-table';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { Spinner } from '@/components/ui/spinner';
import { previous } from '@/routes/orders';
import type { RelatedOrder } from '@/types';

/** The list row sits under a clickable row; events from the portal still bubble to it. */
function stop(event: SyntheticEvent) {
    event.stopPropagation();
}

/** «Повтор N» that opens the client's earlier orders in the showroom, loaded on first open. */
export function OrderRepeatsPopover({
    orderId,
    count,
}: {
    orderId: number;
    count: number;
}) {
    const { submit, processing } = useHttp();
    const [rows, setRows] = useState<RelatedOrder[] | null>(null);
    const [failed, setFailed] = useState(false);

    const load = async () => {
        setFailed(false);

        try {
            setRows(
                (await submit(previous(orderId))) as RelatedOrder[],
            );
        } catch {
            setFailed(true);
        }
    };

    return (
        <Popover
            onOpenChange={(open) => {
                if (open && rows === null && !processing) {
                    void load();
                }
            }}
        >
            <PopoverTrigger asChild>
                <button
                    type="button"
                    onClick={stop}
                    onDoubleClick={stop}
                    onKeyDown={stop}
                    aria-label={`Предыдущие обращения: ${count}`}
                    className="rounded-full outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
                >
                    <OrderRepeatBadge count={count} />
                </button>
            </PopoverTrigger>
            <PopoverContent
                align="start"
                className="w-[min(44rem,calc(100vw-2rem))] gap-3 p-3"
                onClick={stop}
                onDoubleClick={stop}
                onKeyDown={stop}
            >
                <p className="font-medium">Предыдущие обращения в салоне</p>
                {rows ? (
                    <div className="max-h-80 overflow-y-auto overscroll-contain">
                        <RelatedOrdersTable
                            rows={rows}
                            emptyMessage="Предыдущих обращений, доступных вам, нет."
                        />
                    </div>
                ) : failed ? (
                    <p className="text-muted-foreground">
                        Не удалось загрузить.{' '}
                        <button
                            type="button"
                            onClick={() => void load()}
                            className="text-foreground underline underline-offset-4"
                        >
                            Повторить
                        </button>
                    </p>
                ) : (
                    <div className="flex items-center gap-2 text-muted-foreground">
                        <Spinner />
                        Загрузка…
                    </div>
                )}
            </PopoverContent>
        </Popover>
    );
}
