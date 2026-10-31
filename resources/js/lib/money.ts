import { intlLocale, locale } from '@/lib/i18n';

const euros = new Intl.NumberFormat(intlLocale(), {
    style: 'currency',
    currency: 'EUR',
});

/**
 * Format an amount in cents in the member's language: `1 234,56 €`,
 * `€1,234.56`.
 */
export function formatMoney(cents: number): string {
    return euros.format(cents / 100);
}

/**
 * Parse an amount typed by a person (`1 234,56`, `1234.56`, `-21,56`, `21 €`)
 * into integer cents, without going through floating point. Returns null when
 * the input is not an amount.
 */
export function parseMoney(input: string): number | null {
    const compact = input.replace(/[\s  €]/g, '');
    const match = /^([+-]?)(\d+)(?:[.,](\d{0,2}))?$/.exec(compact);

    if (!match) {
        return null;
    }

    const [, sign, units, decimals = ''] = match;
    const cents =
        Number.parseInt(units, 10) * 100 +
        Number.parseInt(decimals.padEnd(2, '0'), 10);

    return sign === '-' && cents !== 0 ? -cents : cents;
}

/**
 * Write cents in a form field the way people type them: `1234,56` (or
 * `1234.56` in English).
 */
export function moneyToInput(cents: number): string {
    const sign = cents < 0 ? '-' : '';
    const absolute = Math.abs(cents);
    const decimals = String(absolute % 100).padStart(2, '0');

    const separator = locale() === 'en' ? '.' : ',';

    return `${sign}${Math.floor(absolute / 100)}${separator}${decimals}`;
}

/**
 * Parse an amount or a simple sum typed in a budget field (`650+20`, `100-12,50`)
 * into integer cents. Returns null when the input is not a valid expression.
 */
export function evaluateMoney(input: string): number | null {
    const compact = input.replace(/[\s  €]/g, '');

    if (compact === '') {
        return null;
    }

    const terms = compact.match(/[+-]?[^+-]+/g);

    if (!terms || terms.join('') !== compact) {
        return null;
    }

    let total = 0;

    for (const term of terms) {
        const cents = parseMoney(term);

        if (cents === null) {
            return null;
        }

        total += cents;
    }

    return total;
}
