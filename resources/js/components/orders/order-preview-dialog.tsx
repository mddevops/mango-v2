import { Link } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';
import type { ReactNode } from 'react';
import { OrderRepeatBadge } from '@/components/orders/order-repeat-badge';
import { OrderStatusBadge } from '@/components/orders/order-status-badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { formatBusinessDate, formatMoment } from '@/lib/order-format';
import { show as ordersShow } from '@/routes/orders';
import type { OrderRow } from '@/types';

function Fact({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="flex flex-col gap-0.5">
            <dt className="text-xs text-muted-foreground">{label}</dt>
            <dd>{children}</dd>
        </div>
    );
}

/** Quick look from the list row; the full card (and its view record) opens separately. */
export function OrderPreviewDialog({
    order,
    open,
    onOpenChange,
}: {
    order: OrderRow | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    return (
        <Dialog open={open && order !== null} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-lg">
                {order && (
                    <>
                        <DialogHeader>
                            <DialogTitle>Заявка № {order.id}</DialogTitle>
                            <DialogDescription className="flex flex-wrap items-center gap-2">
                                <OrderStatusBadge color={order.status.color}>
                                    {order.status.name}
                                </OrderStatusBadge>
                                <OrderRepeatBadge count={order.repeat_number} />
                            </DialogDescription>
                        </DialogHeader>

                        <dl className="grid gap-4 sm:grid-cols-2">
                            <Fact label="Клиент">{order.contact_name ?? '—'}</Fact>
                            <Fact label="Телефон">
                                <span className="tabular-nums">
                                    {order.phone ?? '—'}
                                </span>
                            </Fact>
                            <Fact label="Салон">{order.showroom}</Fact>
                            <Fact label="Источник">{order.source}</Fact>
                            <Fact label="Тип заявки">{order.channel}</Fact>
                            <Fact label="Ответственный">
                                {order.responsible ?? (
                                    <span className="text-muted-foreground">
                                        не назначен
                                    </span>
                                )}
                            </Fact>
                            <Fact label="Поступила">
                                <span className="tabular-nums">
                                    {formatMoment(order.received_at)}
                                </span>
                            </Fact>
                            <Fact label="Бизнес-дата">
                                <span className="tabular-nums">
                                    {formatBusinessDate(order.business_date)}
                                </span>
                            </Fact>
                        </dl>

                        <DialogFooter>
                            <Button
                                variant="outline"
                                onClick={() => onOpenChange(false)}
                            >
                                Закрыть
                            </Button>
                            <Button asChild>
                                <Link href={ordersShow(order.id)}>
                                    Открыть заявку
                                    <ArrowRight />
                                </Link>
                            </Button>
                        </DialogFooter>
                    </>
                )}
            </DialogContent>
        </Dialog>
    );
}
