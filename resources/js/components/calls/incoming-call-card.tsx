import { PhoneIncoming, X } from 'lucide-react';
import { Badge } from '@/components/reui/badge';
import { Button } from '@/components/ui/button';
import { ShineBorder } from '@/components/ui/shine-border';
import { formatMoment } from '@/lib/order-format';

export type CallCardState = 'ringing' | 'talking' | 'ended' | 'missed';

export type CallCardData = {
    call_id: number;
    state: CallCardState;
    phone: string | null;
    client: string | null;
    status: string | null;
    source: string | null;
    responsible: string | null;
    order_id: number;
    order_created_at: string | null;
    url: string;
};

const TITLES: Record<CallCardState, string> = {
    ringing: 'Входящий звонок от',
    talking: 'Разговор с',
    ended: 'Звонок завершён',
    missed: 'Пропущенный звонок',
};

const BADGES: Record<
    CallCardState,
    { label: string; variant: 'info-light' | 'success-light' | 'secondary' | 'destructive-light' }
> = {
    ringing: { label: 'Звонит', variant: 'info-light' },
    talking: { label: 'Разговор', variant: 'success-light' },
    ended: { label: 'Завершён', variant: 'secondary' },
    missed: { label: 'Пропущен', variant: 'destructive-light' },
};

function Row({ label, value }: { label: string; value: string }) {
    return (
        <div className="flex min-w-0 gap-2 text-xs">
            <dt className="w-24 shrink-0 text-muted-foreground">{label}</dt>
            <dd className="min-w-0 truncate font-medium" title={value}>
                {value}
            </dd>
        </div>
    );
}

export function IncomingCallCard({
    card,
    onOpen,
    onHide,
}: {
    card: CallCardData;
    onOpen: () => void;
    onHide: () => void;
}) {
    const active = card.state === 'ringing' || card.state === 'talking';
    const badge = BADGES[card.state];

    return (
        <ShineBorder
            active={active}
            role="status"
            aria-live="polite"
            className="shadow-lg"
        >
            <div className="flex flex-col gap-3 p-3">
                <div className="flex items-start gap-2">
                    <PhoneIncoming className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                    <div className="min-w-0 flex-1">
                        <div className="text-xs text-muted-foreground">
                            {TITLES[card.state]}:
                        </div>
                        <div className="truncate text-sm font-semibold">
                            {card.phone ?? 'Номер не определён'}
                        </div>
                    </div>
                    <Badge variant={badge.variant}>
                        {badge.label}
                    </Badge>
                    <Button
                        variant="ghost"
                        size="icon-xs"
                        aria-label="Скрыть"
                        onClick={onHide}
                    >
                        <X />
                    </Button>
                </div>
                <dl className="flex flex-col gap-1">
                    <Row label="Клиент" value={card.client || 'Не указано'} />
                    <Row label="Статус" value={card.status || 'Не указано'} />
                    <Row
                        label="Источник"
                        value={card.source || 'Источник не определён'}
                    />
                    <Row
                        label="Оператор"
                        value={card.responsible || 'Не назначен'}
                    />
                    <Row
                        label="Дата заявки"
                        value={
                            card.order_created_at
                                ? formatMoment(card.order_created_at)
                                : 'Не указано'
                        }
                    />
                </dl>
                <div className="flex gap-2">
                    <Button size="sm" className="flex-1" onClick={onOpen}>
                        Открыть заявку
                    </Button>
                    <Button size="sm" variant="outline" onClick={onHide}>
                        Скрыть
                    </Button>
                </div>
            </div>
        </ShineBorder>
    );
}
