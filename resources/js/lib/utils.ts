import type { InertiaLinkProps } from '@inertiajs/react';
import { clsx } from 'clsx';
import type { ClassValue } from 'clsx';
import { twMerge } from 'tailwind-merge';

export function cn(...inputs: ClassValue[]) {
    return twMerge(clsx(inputs));
}

const pluralRules = new Intl.PluralRules('ru-RU');

/**
 * «1 салон», «3 салона», «12 салонов»: forms are [one, few, many].
 */
export function plural(
    count: number,
    [one, few, many]: [string, string, string],
): string {
    const rule = pluralRules.select(count);
    const word = rule === 'one' ? one : rule === 'few' ? few : many;

    return `${count.toLocaleString('ru-RU')} ${word}`;
}

export function toUrl(url: NonNullable<InertiaLinkProps['href']>): string {
    return typeof url === 'string' ? url : url.url;
}
