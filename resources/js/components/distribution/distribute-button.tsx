import type { LucideIcon } from 'lucide-react';
import { Hand, Shuffle } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { postOrderAction } from '@/lib/order-actions';
import { distribute, take } from '@/routes/orders';

function ActionButton({
    url,
    label,
    icon: Icon,
    only,
}: {
    url: string;
    label: string;
    icon: LucideIcon;
    only?: string[];
}) {
    const [busy, setBusy] = useState(false);

    return (
        <Button
            type="button"
            size="sm"
            variant="outline"
            disabled={busy}
            onClick={() => postOrderAction(url, { only, onBusy: setBusy })}
        >
            {busy ? <Spinner /> : <Icon />}
            {label}
        </Button>
    );
}

export function DistributeButton({
    orderId,
    label = 'Отправить в распределение',
    only,
}: {
    orderId: number;
    label?: string;
    only?: string[];
}) {
    return (
        <ActionButton
            url={distribute.url(orderId)}
            label={label}
            icon={Shuffle}
            only={only}
        />
    );
}

export function TakeButton({
    orderId,
    only,
}: {
    orderId: number;
    only?: string[];
}) {
    return (
        <ActionButton
            url={take.url(orderId)}
            label="Взять в работу"
            icon={Hand}
            only={only}
        />
    );
}
