/**
 * Interface strings. English text is the key: `t('Add account')` gives the
 * member's language, read from lang/{locale}.json. The server writes the
 * strings of the current language in the page (resources/views/app.blade.php),
 * so they are there before any module runs, and changing language reloads
 * the page.
 *
 * Placeholders use Laravel's syntax: `t('Hello :name', { name })`. Plurals
 * too: `tc(':count account|:count accounts', count)`, or with ranges
 * `{0} No account|{1} One account|[2,*] :count accounts`.
 */

type Replacements = Record<string, string | number>;

declare global {
    interface Window {
        __i18n?: { locale: string; messages: Record<string, string> };
    }
}

const state = (typeof window !== 'undefined' && window.__i18n) || {
    locale: 'en',
    messages: {},
};

const intlLocales: Record<string, string> = {
    en: 'en-GB',
    fr: 'fr-FR',
    es: 'es-ES',
};

/**
 * The member's language: `en`, `fr` or `es`.
 */
export function locale(): string {
    return state.locale;
}

/**
 * The language tag for `Intl` formatters (dates, amounts).
 */
export function intlLocale(): string {
    return intlLocales[state.locale] ?? 'en-GB';
}

function replace(text: string, replacements: Replacements = {}): string {
    return Object.entries(replacements)
        .sort(([a], [b]) => b.length - a.length)
        .reduce(
            (result, [key, value]) =>
                result.replaceAll(`:${key}`, String(value)),
            text,
        );
}

/**
 * Translate an English string.
 */
export function t(key: string, replacements?: Replacements): string {
    return replace(state.messages[key] || key, replacements);
}

function pick(variants: string[], count: number): string {
    for (const variant of variants) {
        const range = /^\s*(\{(-?\d+)\}|\[(-?\d+|\*),\s*(-?\d+|\*)\])\s*/.exec(
            variant,
        );

        if (!range) {
            continue;
        }

        const text = variant.slice(range[0].length);

        if (range[2] !== undefined) {
            if (Number(range[2]) === count) {
                return text;
            }

            continue;
        }

        const from = range[3] === '*' ? -Infinity : Number(range[3]);
        const to = range[4] === '*' ? Infinity : Number(range[4]);

        if (count >= from && count <= to) {
            return text;
        }
    }

    const plain = variants.map((variant) =>
        variant.replace(/^\s*(\{-?\d+\}|\[[^\]]+\])\s*/, ''),
    );

    if (plain.length === 1) {
        return plain[0];
    }

    // French counts 0 and 1 as singular, English and Spanish only 1.
    const singular = state.locale === 'fr' ? Math.abs(count) < 2 : count === 1;

    return singular ? plain[0] : plain[plain.length > 2 ? 2 : 1];
}

/**
 * Translate an English string with a singular and a plural form, separated
 * by `|`. `:count` is replaced by the number.
 */
export function tc(
    key: string,
    count: number,
    replacements: Replacements = {},
): string {
    const variants = (state.messages[key] || key).split('|');

    return replace(pick(variants, count), {
        count: count.toLocaleString(intlLocale()),
        ...replacements,
    });
}
