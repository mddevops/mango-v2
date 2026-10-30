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
