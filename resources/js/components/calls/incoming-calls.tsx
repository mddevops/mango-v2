import { router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';
import { IncomingCallCard } from '@/components/calls/incoming-call-card';
import type {
    CallCardData,
    CallCardState,
} from '@/components/calls/incoming-call-card';
import { useRealtimeSignal } from '@/hooks/use-realtime-signal';
import { card as cardRoute } from '@/routes/calls';

const HIDDEN_KEY = 'crm.hidden-call-cards';
const HIDDEN_LIMIT = 200;
const FINISHED_MS = 15_000;

const isFinal = (state: CallCardState | 'revoked') =>
    state === 'ended' || state === 'missed';

function readHidden(): number[] {
    try {
        const value: unknown = JSON.parse(
            sessionStorage.getItem(HIDDEN_KEY) ?? '[]',
        );

        return Array.isArray(value) ? value.filter(Number.isInteger) : [];
    } catch {
        return [];
    }
}

/** The card data, or null when the employee may no longer see it. */
async function fetchCard(callId: number): Promise<CallCardData | null> {
    const response = await fetch(cardRoute.url(callId), {
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
    });

    if (response.status === 404 || response.status === 403) {
        return null;
    }

    if (!response.ok) {
        throw new Error(`call card ${response.status}`);
    }

    return (await response.json()) as CallCardData;
}

/**
 * Incoming call cards at the bottom right, one per Mango call (call id = connection +
 * entry_id), never per order: every new call of the same order gets its own card, and
 * further signals of a call update its card. Signals give only the call id, state and
 * version; the data comes from the server, which checks access to the order every time.
 * A finished call stays on its final state and hides after 15 s (a call already over
 * when its first signal came is shown that way too); a card hidden by the employee does
 * not come back for that call in this tab. Stale calls get no signal from the server.
 */
export function IncomingCalls() {
    const [cards, setCards] = useState<CallCardData[]>([]);
    const current = useRef<CallCardData[]>([]);
    const hidden = useRef<Set<number> | null>(null);
    const requests = useRef(new Map<number, number>());
    const timers = useRef(new Map<number, ReturnType<typeof setTimeout>>());

    const hiddenSet = () => {
        hidden.current ??= new Set(readHidden());

        return hidden.current;
    };

    const commit = (next: CallCardData[]) => {
        current.current = next;
        setCards(next);
    };

    const remove = (callId: number) => {
        const timer = timers.current.get(callId);

        if (timer) {
            clearTimeout(timer);
            timers.current.delete(callId);
        }

        requests.current.delete(callId);

        if (current.current.some((card) => card.call_id === callId)) {
            commit(current.current.filter((card) => card.call_id !== callId));
        }
    };

    const hide = (callId: number) => {
        const set = hiddenSet();
        set.add(callId);

        try {
            sessionStorage.setItem(
                HIDDEN_KEY,
                JSON.stringify([...set].slice(-HIDDEN_LIMIT)),
            );
        } catch {
            // Storage is unavailable: the card stays hidden until the page is reloaded.
        }

        remove(callId);
    };

    const scheduleHide = (callId: number) => {
        if (!timers.current.has(callId)) {
            timers.current.set(
                callId,
                setTimeout(() => {
                    timers.current.delete(callId);
                    hide(callId);
                }, FINISHED_MS),
            );
        }
    };

    const load = async (callId: number) => {
        const request = (requests.current.get(callId) ?? 0) + 1;
        requests.current.set(callId, request);

        let data: CallCardData | null;

        try {
            data = await fetchCard(callId);
        } catch {
            return;
        }

        if (requests.current.get(callId) !== request || hiddenSet().has(callId)) {
            return;
        }

        if (data === null) {
            remove(callId);

            return;
        }

        const existing = current.current.find((card) => card.call_id === callId);
        const state =
            existing && isFinal(existing.state) && !isFinal(data.state)
                ? existing.state
                : data.state;
        const next = { ...data, state };

        commit(
            existing
                ? current.current.map((card) =>
                      card.call_id === callId ? next : card,
                  )
                : [...current.current, next],
        );

        if (isFinal(state)) {
            scheduleHide(callId);
        }
    };

    const refresh = (filter: (card: CallCardData) => boolean) => {
        current.current
            .filter(filter)
            .forEach((card) => void load(card.call_id));
    };

    useRealtimeSignal((signal) => {
        switch (signal.type) {
            case 'call.card':
                if (hiddenSet().has(signal.call_id)) {
                    return;
                }

                if (signal.state === 'revoked') {
                    remove(signal.call_id);
                } else {
                    void load(signal.call_id);
                }

                return;
            case 'order.updated':
            case 'order.access-check':
                refresh((card) => card.order_id === signal.order_id);

                return;
            case 'resync':
                refresh(() => true);

                return;
        }
    });

    useEffect(() => {
        const pending = timers.current;

        return () => {
            pending.forEach((timer) => clearTimeout(timer));
            pending.clear();
        };
    }, []);

    const open = async (card: CallCardData) => {
        let data: CallCardData | null;

        try {
            data = await fetchCard(card.call_id);
        } catch {
            toast.error('Не удалось открыть заявку. Повторите попытку.');

            return;
        }

        if (data === null) {
            remove(card.call_id);
            toast.info('Заявка больше недоступна.');

            return;
        }

        router.visit(data.url);
    };

    if (cards.length === 0) {
        return null;
    }

    return (
        <div className="fixed right-4 bottom-4 z-50 flex max-h-[min(70vh,36rem)] w-[min(360px,calc(100vw-2rem))] flex-col gap-2 overflow-y-auto p-1 sm:right-6 sm:bottom-6">
            {cards.map((card) => (
                <IncomingCallCard
                    key={card.call_id}
                    card={card}
                    onOpen={() => void open(card)}
                    onHide={() => hide(card.call_id)}
                />
            ))}
        </div>
    );
}
