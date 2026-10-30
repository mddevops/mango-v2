/**
 * Russian numbers only: whatever is typed or pasted (8 951…, +7 951…, 951…) becomes
 * «+7 (951) 534-67-83». A leading 7 or 8 is read as the country code. Separators are
 * added only before a following digit, so Backspace never gets stuck on them.
 * The server normalizes and validates the number again.
 */
export function formatRuPhone(value: string): string {
    let digits = value.replace(/\D/g, '');

    if (digits.startsWith('7') || digits.startsWith('8')) {
        digits = digits.slice(1);
    }

    digits = digits.slice(0, 10);

    if (digits === '') {
        return '';
    }

    let out = `+7 (${digits.slice(0, 3)}`;

    if (digits.length > 3) {
        out += `) ${digits.slice(3, 6)}`;
    }

    if (digits.length > 6) {
        out += `-${digits.slice(6, 8)}`;
    }

    if (digits.length > 8) {
        out += `-${digits.slice(8, 10)}`;
    }

    return out;
}

export const RU_PHONE_PLACEHOLDER = '+7 (___) ___-__-__';

/** A formatted number with all ten digits after the country code. */
export function isCompleteRuPhone(value: string): boolean {
    return value.replace(/\D/g, '').length === 11;
}
