<?php

namespace App\Banking;

/**
 * Default cleaning of raw bank labels, before the household's rename rules:
 * "CARTE 17/10/26 BONNEAU BOULANG CB*2594" becomes "BONNEAU BOULANG", card 2594.
 */
class BankLabel
{
    /**
     * Prefixes added by French banks in front of the merchant or counterparty.
     */
    private const PREFIXES = [
        '/^CARTE \d{2}\/\d{2}\/\d{2,4} /i',
        '/^PRLV SEPA /i',
        '/^VIR (SEPA )?/i',
    ];

    private const CARD_SUFFIX = '/\s*CB\*(\d{4})$/i';

    /**
     * @return array{label: string, card_suffix: string|null}
     */
    public static function clean(string $raw): array
    {
        $label = self::squish($raw);
        $cardSuffix = null;

        if (preg_match(self::CARD_SUFFIX, $label, $matches) === 1) {
            $cardSuffix = $matches[1];
            $label = (string) preg_replace(self::CARD_SUFFIX, '', $label);
        }

        foreach (self::PREFIXES as $prefix) {
            $label = (string) preg_replace($prefix, '', $label);
        }

        $label = trim($label);

        return ['label' => $label === '' ? self::squish($raw) : $label, 'card_suffix' => $cardSuffix];
    }

    /**
     * Uppercase, single-spaced label used to compare labels.
     */
    public static function normalize(string $raw): string
    {
        return mb_strtoupper(self::squish($raw));
    }

    private static function squish(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }
}
