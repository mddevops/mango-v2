import { Link } from '@inertiajs/react';
import { ArrowLeft, ArrowRight } from 'lucide-react';
import type { SyntheticEvent } from 'react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { formatMoment } from '@/lib/order-format';
import { show as ordersShow } from '@/routes/orders';
import type { OrderComment } from '@/types';

const PREVIEW_LENGTH = 20;

function preview(body: string): string {
    const text = body.replace(/\s+/g, ' ').trim();

    return text.length > PREVIEW_LENGTH
        ? `${text.slice(0, PREVIEW_LENGTH).trimEnd()}...`
        : text;
}

/** The list card sits under a clickable card; events from the portal still bubble to it. */
function stop(event: SyntheticEvent) {
    event.stopPropagation();
}

export function OrderCommentsPopover({
    orderId,
    comments,
    total,
}: {
    orderId: number;
    comments: OrderComment[];
    total: number;
}) {
    const [current, setCurrent] = useState(0);

    if (comments.length === 0) {
        return <span className="text-muted-foreground">Нет комментариев</span>;
    }

    const comment = comments[Math.min(current, comments.length - 1)];
    const isFirst = current === 0;
    const isLast = current >= comments.length - 1;

    return (
        <Popover
            onOpenChange={(open) => {
                if (!open) {
                    setCurrent(0);
                }
            }}
        >
            <PopoverTrigger asChild>
                <button
                    type="button"
                    onClick={stop}
                    onDoubleClick={stop}
                    onKeyDown={stop}
                    className="max-w-full truncate rounded-sm text-left text-foreground underline decoration-muted-foreground/40 decoration-dashed underline-offset-4 transition-colors outline-none hover:decoration-foreground focus-visible:ring-[3px] focus-visible:ring-ring/50"
                >
                    {preview(comments[0].body)}
                </button>
            </PopoverTrigger>
            <PopoverContent
                className="w-80 gap-2 px-3 pt-3 pb-2"
                side="top"
                sideOffset={8}
                onClick={stop}
                onDoubleClick={stop}
                onKeyDown={stop}
            >
                <div className="flex items-baseline justify-between gap-2">
                    <p className="truncate leading-tight font-medium">
                        {comment.author}
                    </p>
                    <span className="shrink-0 text-xs text-muted-foreground tabular-nums">
                        {formatMoment(comment.created_at)}
                    </span>
                </div>
                <div className="max-h-48 overflow-y-auto overscroll-contain pr-1 break-words whitespace-pre-line text-muted-foreground">
                    {comment.body}
                </div>
                <div className="flex min-h-6 items-center justify-between gap-2">
                    {comments.length > 1 ? (
                        <span className="text-muted-foreground tabular-nums">
                            {current + 1} из {comments.length}
                        </span>
                    ) : (
                        <span />
                    )}
                    <div className="flex items-center gap-2">
                        {total > comments.length && (
                            <Link
                                href={ordersShow(orderId)}
                                className="text-xs text-muted-foreground underline-offset-4 transition-colors hover:text-foreground hover:underline"
                            >
                                Все {total}
                            </Link>
                        )}
                        {comments.length > 1 && (
                            <div className="flex gap-0.5">
                                <Button
                                    aria-label="Предыдущий комментарий"
                                    className="size-6"
                                    disabled={isFirst}
                                    onClick={() => setCurrent(current - 1)}
                                    size="icon"
                                    variant="ghost"
                                >
                                    <ArrowLeft className="size-3.5" />
                                </Button>
                                <Button
                                    aria-label="Следующий комментарий"
                                    className="size-6"
                                    disabled={isLast}
                                    onClick={() => setCurrent(current + 1)}
                                    size="icon"
                                    variant="ghost"
                                >
                                    <ArrowRight className="size-3.5" />
                                </Button>
                            </div>
                        )}
                    </div>
                </div>
            </PopoverContent>
        </Popover>
    );
}
