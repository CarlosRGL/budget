<?php

namespace App\Banking;

use App\Enums\BankStatus;
use App\Support\Locales;
use Illuminate\Support\Carbon;

/**
 * A transaction as returned by EnableBanking, reduced to what the import needs.
 */
final readonly class BankTransaction
{
    public function __construct(
        public ?string $externalId,
        public int $amount,
        public BankStatus $status,
        public Carbon $date,
        public string $rawLabel,
        public string $label,
        public ?string $cardSuffix,
        public string $fingerprint,
    ) {}

    /**
     * Read an API transaction; null for those that never moved money (cancelled, rejected, scheduled).
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromApi(array $data): ?self
    {
        $status = match ($data['status'] ?? 'BOOK') {
            'BOOK', 'OTHR' => BankStatus::Booked,
            'PDNG', 'HOLD' => BankStatus::Pending,
            default => null,
        };

        if ($status === null) {
            return null;
        }

        $amount = self::cents((string) ($data['transaction_amount']['amount'] ?? '0'));
        $amount = match ($data['credit_debit_indicator'] ?? null) {
            'DBIT' => -abs($amount),
            'CRDT' => abs($amount),
            default => $amount,
        };

        $date = Carbon::parse($data['transaction_date'] ?? $data['booking_date'] ?? $data['value_date'] ?? now())->startOfDay();
        $valueDate = (string) ($data['value_date'] ?? $data['booking_date'] ?? $data['transaction_date'] ?? $date->toDateString());

        $rawLabel = self::rawLabel($data, $amount);
        $cleaned = BankLabel::clean($rawLabel);

        $externalId = $data['entry_reference'] ?? $data['transaction_id'] ?? null;

        return new self(
            externalId: filled($externalId) ? (string) $externalId : null,
            amount: $amount,
            status: $status,
            date: $date,
            rawLabel: $rawLabel,
            label: $cleaned['label'],
            cardSuffix: $cleaned['card_suffix'],
            fingerprint: hash('sha256', implode('|', [$valueDate, $amount, BankLabel::normalize($rawLabel)])),
        );
    }

    /**
     * Decimal string ("12.3", "-4.56", "100") to integer cents, without floats.
     */
    public static function cents(string $amount): int
    {
        $amount = trim($amount);
        $negative = str_starts_with($amount, '-');
        [$units, $decimals] = array_pad(explode('.', ltrim($amount, '+-'), 2), 2, '');

        $cents = (int) $units * 100 + (int) str_pad(substr($decimals, 0, 2), 2, '0');

        return $negative ? -$cents : $cents;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function rawLabel(array $data, int $amount): string
    {
        $remittance = $data['remittance_information'] ?? [];
        $label = trim(implode(' ', is_array($remittance) ? $remittance : [$remittance]));

        if ($label === '') {
            $counterparty = $amount < 0 ? ($data['creditor']['name'] ?? null) : ($data['debtor']['name'] ?? null);
            $label = trim((string) ($counterparty ?? $data['note'] ?? ''));
        }

        return $label === '' ? Locales::data('Bank transaction') : mb_substr($label, 0, 255);
    }
}
