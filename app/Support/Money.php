<?php

namespace App\Support;

class Money
{
    /**
     * Format an amount in cents like the front-end helper, in the given
     * language (the current one by default): `1 234,56 €` in French,
     * `€1,234.56` in English, `1234,56 €` or `12.345,67 €` in Spanish.
     */
    public static function format(int $cents, ?string $locale = null): string
    {
        $locale ??= app()->getLocale();
        $absolute = abs($cents);
        $whole = intdiv($absolute, 100);
        $decimals = str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);
        $sign = $cents < 0 ? '-' : '';

        return match ($locale) {
            'en' => $sign.'€'.number_format($whole, 0, '', ',').'.'.$decimals,
            // Spanish groups thousands only from five digits on.
            'es' => $sign.number_format($whole, 0, '', $whole >= 10_000 ? '.' : '').','.$decimals."\u{a0}€",
            default => $sign.number_format($whole, 0, '', "\u{202f}").','.$decimals."\u{a0}€",
        };
    }
}
