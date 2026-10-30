import { useHttp } from '@inertiajs/react';
import type { FormDataType, UrlMethodPair } from '@inertiajs/core';
import { useEffect, useRef } from 'react';
import { toast } from 'sonner';

const SAVE_DELAY_MS = 500;

/**
 * A view preference kept in the employee's account. The page starts from the value the
 * server rendered with and saves each change shortly after the last one, so a burst of
 * clicks makes one request.
 */
export function useSavedPreferences<T extends FormDataType<T>>(
    endpoint: UrlMethodPair,
    initial: T,
) {
    const { data, setData, submit } = useHttp<T>(initial);
    const changed = useRef(false);
    const submitRef = useRef(submit);
    submitRef.current = submit;
    const { url, method } = endpoint;

    useEffect(() => {
        if (!changed.current) {
            return;
        }

        const timer = setTimeout(() => {
            submitRef.current({ url, method }).catch(() => {
                toast.error('Не удалось сохранить настройки списка.');
            });
        }, SAVE_DELAY_MS);

        return () => clearTimeout(timer);
    }, [data, url, method]);

    const update = (next: T) => {
        changed.current = true;
        setData(next);
    };

    return [data, update] as const;
}
