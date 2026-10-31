import { intlLocale } from '@/lib/i18n';

/**
 * Today's date in the browser's time zone, as `YYYY-MM-DD`.
 */
export function todayIso(): string {
    const now = new Date();
    const month = String(now.getMonth() + 1).padStart(2, '0');
    const day = String(now.getDate()).padStart(2, '0');

    return `${now.getFullYear()}-${month}-${day}`;
}

const monthFormat = new Intl.DateTimeFormat(intlLocale(), {
    month: 'long',
    year: 'numeric',
});

/**
 * A budget month of the URL (`2026-10`) in the member's language, capitalised: `Octobre 2026`, `October 2026`.
 */
export function formatMonth(month: string): string {
    const name = monthFormat.format(new Date(`${month}-15T12:00:00`));

    // Only the first letter: `Octubre de 2026`, not `Octubre De 2026`.
    return name.charAt(0).toLocaleUpperCase(intlLocale()) + name.slice(1);
}

/**
 * The current month in the browser's time zone, as `YYYY-MM`.
 */
export function currentMonth(): string {
    return todayIso().slice(0, 7);
}
