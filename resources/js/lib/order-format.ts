import { plural } from '@/lib/utils';

const momentFormat = new Intl.DateTimeFormat('ru-RU', {
    dateStyle: 'short',
    timeStyle: 'short',
});

export function formatMoment(iso: string | null): string {
    return iso ? momentFormat.format(new Date(iso)) : '—';
}

/** A server calendar date (2026-10-22) shown as 22.10.2026, without timezone shifts. */
export function formatBusinessDate(date: string): string {
    const [year, month, day] = date.split('-');

    return `${day}.${month}.${year}`;
}

/** The showroom-local form value (2026-10-22T10:30) shown as 22.10.2026 10:30. */
export function formatLocal(value: string | null): string {
    if (!value) {
        return '—';
    }

    const [date, time] = value.split('T');

    return `${formatBusinessDate(date)} ${time}`;
}

function span(minutes: number): string {
    const days = Math.floor(minutes / 1440);
    const hours = Math.floor((minutes % 1440) / 60);
    const rest = minutes % 60;

    if (days > 0) {
        return hours > 0 ? `${days} д ${hours} ч` : `${days} д`;
    }

    if (hours > 0) {
        return rest > 0 ? `${hours} ч ${rest} мин` : `${hours} ч`;
    }

    return `${rest} мин`;
}

/**
 * The callback seen from the server's «now» of the list: «Сейчас», «Через 25 минут»,
 * «Просрочено на 2 ч 15 мин».
 */
export function callbackHint(seconds: number): string {
    if (seconds >= 0) {
        const minutes = Math.floor(seconds / 60);

        return minutes === 0
            ? 'Сейчас'
            : `Через ${plural(minutes, ['минуту', 'минуты', 'минут'])}`;
    }

    const overdue = Math.floor(-seconds / 60);

    return overdue === 0
        ? 'Просрочено меньше минуты'
        : `Просрочено на ${span(overdue)}`;
}
