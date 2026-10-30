import { router } from '@inertiajs/react';
import { useConnectionStatus, useEcho } from '@laravel/echo-react';
import { useEffect, useRef } from 'react';
import { toast } from 'sonner';
import { emitRealtimeSignal } from '@/hooks/use-realtime-signal';
import type { RealtimeSignal } from '@/types/realtime';

const EVENTS = [
    '.order.updated',
    '.order.assigned',
    '.order.access-check',
    '.call.updated',
    '.call.incoming',
];

const SEEN_LIMIT = 500;

type ChannelSignal = Exclude<RealtimeSignal, { type: 'resync' }>;

function versionKey(signal: ChannelSignal): string | null {
    switch (signal.type) {
        case 'order.updated':
            return `order:${signal.order_id}`;
        case 'call.updated':
            return `call:${signal.call_id}`;
        default:
            return null;
    }
}

function notify(signal: ChannelSignal): void {
    const open = (url: string) => ({
        label: 'Открыть',
        onClick: () => router.visit(url),
    });

    if (signal.type === 'order.assigned') {
        toast.info(`Вам назначена ${signal.label}`, {
            action: open(signal.url),
        });
    }

    if (signal.type === 'call.incoming') {
        toast.info('Входящий звонок', {
            description: signal.label ?? undefined,
            action: signal.url ? open(signal.url) : undefined,
        });
    }
}

/**
 * The single subscription to the employee's personal channel, mounted once in the app
 * layout. Drops repeated and outdated signals, shows the notifications (no sound, no
 * browser permission) and passes the rest to the open page. After a reconnect the pages
 * reload their data, since signals sent meanwhile were missed.
 */
export function RealtimeBridge({ userId }: { userId: number }) {
    const seen = useRef(new Set<string>());
    const versions = useRef(new Map<string, number>());

    useEcho<ChannelSignal>(`App.Models.User.${userId}`, EVENTS, (signal) => {
        if (seen.current.has(signal.id)) {
            return;
        }

        seen.current.add(signal.id);

        if (seen.current.size > SEEN_LIMIT) {
            seen.current.delete(seen.current.values().next().value as string);
        }

        const key = versionKey(signal);

        if (key !== null && 'version' in signal) {
            if (signal.version < (versions.current.get(key) ?? 0)) {
                return;
            }

            versions.current.set(key, signal.version);
        }

        notify(signal);
        emitRealtimeSignal(signal);
    });

    const status = useConnectionStatus();
    const previous = useRef(status);
    const connectedOnce = useRef(false);

    useEffect(() => {
        if (status === 'connected') {
            if (connectedOnce.current && previous.current !== 'connected') {
                emitRealtimeSignal({ type: 'resync' });
            }

            connectedOnce.current = true;
        }

        previous.current = status;
    }, [status]);

    return null;
}
