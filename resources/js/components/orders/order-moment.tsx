import { Clock } from 'lucide-react';
import { useSyncExternalStore } from 'react';

const MINUTE = 60_000;
const DAY = 24 * 60 * MINUTE;

const timeFormat = new Intl.DateTimeFormat('ru-RU', {
    hour: '2-digit',
    minute: '2-digit',
});
const dayFormat = new Intl.DateTimeFormat('ru-RU', {
    day: 'numeric',
    month: 'short',
});
const yearDayFormat = new Intl.DateTimeFormat('ru-RU', {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
});
const fullFormat = new Intl.DateTimeFormat('ru-RU', {
    dateStyle: 'long',
    timeStyle: 'short',
});

/** One minute ticker shared by every cell, so «N мин назад» stays current. */
let now = Date.now();
const listeners = new Set<() => void>();
let timer: ReturnType<typeof setInterval> | null = null;

function subscribe(listener: () => void) {
    listeners.add(listener);

    if (timer === null) {
        now = Date.now();
        timer = setInterval(() => {
            now = Date.now();
            listeners.forEach((notify) => notify());
        }, MINUTE);
    }

    return () => {
        listeners.delete(listener);

        if (listeners.size === 0 && timer !== null) {
            clearInterval(timer);
            timer = null;
        }
    };
}

const getNow = () => now;

function startOfDay(moment: number): number {
    const day = new Date(moment);
    day.setHours(0, 0, 0, 0);

    return day.getTime();
}

function ago(diff: number): string {
    const minutes = Math.floor(diff / MINUTE);

    if (minutes < 1) {
        return 'только что';
    }

    return minutes < 60
        ? `${minutes} мин назад`
        : `${Math.floor(minutes / 60)} ч назад`;
}

/** «16 окт» without the abbreviation dot, with the year only when it is not the current one. */
function dayLabel(date: Date, current: number): string {
    if (Math.round((startOfDay(current) - startOfDay(date.getTime())) / DAY) === 1) {
        return 'Вчера';
    }

    const format =
        date.getFullYear() === new Date(current).getFullYear()
            ? dayFormat
            : yearDayFormat;

    return format.format(date).replace('.', '').replace(' г.', '');
}

/** Time on top; below it how long ago for today, otherwise «Вчера» or the date. */
export function OrderMoment({ value }: { value: string | null }) {
    const current = useSyncExternalStore(subscribe, getNow, getNow);

    if (!value) {
        return <span className="text-muted-foreground">—</span>;
    }

    const date = new Date(value);
    const isToday = startOfDay(date.getTime()) === startOfDay(current);

    return (
        <div
            className="flex flex-col gap-0.5"
            title={fullFormat.format(date)}
        >
            <span className="flex items-center gap-1.5 font-medium tabular-nums">
                <Clock
                    className="size-3.5 text-muted-foreground"
                    aria-hidden="true"
                />
                {timeFormat.format(date)}
            </span>
            <span className="text-xs text-muted-foreground">
                {isToday
                    ? ago(Math.max(0, current - date.getTime()))
                    : dayLabel(date, current)}
            </span>
        </div>
    );
}
