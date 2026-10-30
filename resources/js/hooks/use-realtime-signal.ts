import { useCallback, useEffect, useRef } from 'react';
import type { RealtimeSignal } from '@/types/realtime';

type Listener = (signal: RealtimeSignal) => void;

const listeners = new Set<Listener>();

/** Hands a signal from the single channel subscription to the pages listening now. */
export function emitRealtimeSignal(signal: RealtimeSignal): void {
    listeners.forEach((listener) => listener(signal));
}

/**
 * A page reacts to signals without subscribing itself: the one subscription lives in the
 * app layout, so there are no duplicates and nothing stays after the page is left.
 */
export function useRealtimeSignal(handler: Listener): void {
    const current = useRef(handler);

    useEffect(() => {
        current.current = handler;
    });

    useEffect(() => {
        const listener: Listener = (signal) => current.current(signal);
        listeners.add(listener);

        return () => {
            listeners.delete(listener);
        };
    }, []);
}

/** Collapses a burst of signals into one call after a quiet moment. */
export function useCoalesced(callback: () => void, delay = 800): () => void {
    const current = useRef(callback);
    const timer = useRef<ReturnType<typeof setTimeout> | null>(null);

    useEffect(() => {
        current.current = callback;
    });

    useEffect(
        () => () => {
            if (timer.current) {
                clearTimeout(timer.current);
            }
        },
        [],
    );

    return useCallback(() => {
        if (timer.current) {
            clearTimeout(timer.current);
        }

        timer.current = setTimeout(() => {
            timer.current = null;
            current.current();
        }, delay);
    }, [delay]);
}
