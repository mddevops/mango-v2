import { useEffect, useState } from 'react';

const dayFormat = new Intl.DateTimeFormat('ru-RU', {
    day: 'numeric',
    month: 'short',
});
const timeFormat = new Intl.DateTimeFormat('ru-RU', {
    hour: '2-digit',
    minute: '2-digit',
});
const fullFormat = new Intl.DateTimeFormat('ru-RU', {
    dateStyle: 'full',
    timeStyle: 'short',
});

function capitalize(text: string): string {
    return text.charAt(0).toUpperCase() + text.slice(1);
}

/** The employee's local date and time; refreshed on every minute change. Hidden on phones. */
export function HeaderClock() {
    const [now, setNow] = useState(() => new Date());

    useEffect(() => {
        let timer: ReturnType<typeof setTimeout>;

        const schedule = () => {
            const current = new Date();
            setNow(current);
            timer = setTimeout(
                schedule,
                60_000 -
                    current.getSeconds() * 1000 -
                    current.getMilliseconds(),
            );
        };

        schedule();

        return () => clearTimeout(timer);
    }, []);

    return (
        <time
            dateTime={now.toISOString()}
            title={capitalize(fullFormat.format(now))}
            className="mr-1 hidden items-center md:flex"
        >
            <span className="text-sm font-medium tabular-nums">
                {dayFormat.format(now).replace('.', '')} ·{' '}
                {timeFormat.format(now)}
            </span>
        </time>
    );
}
