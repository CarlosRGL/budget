<?php

namespace App\Loans;

use Carbon\CarbonImmutable;

/**
 * The repayment schedule of a fixed-rate loan with constant monthly payments,
 * as French banks compute it: each month's interest is the remaining capital
 * times the yearly rate / 12, rounded half up to the cent, and the last
 * payment settles what is left.
 */
final class Amortization
{
    /** @var list<array{number: int, date: CarbonImmutable, payment: int, interest: int, principal: int, balance: int}>|null */
    private ?array $rows = null;

    public function __construct(
        public readonly int $principal,
        public readonly int $rateMilli,
        public readonly int $months,
        public readonly CarbonImmutable $firstPaymentDate,
        private readonly ?int $fixedPayment = null,
    ) {}

    /**
     * The monthly payment, in cents.
     */
    public function payment(): int
    {
        if ($this->fixedPayment !== null) {
            return $this->fixedPayment;
        }

        if ($this->months === 0) {
            return 0;
        }

        if ($this->rateMilli === 0) {
            return (int) round($this->principal / $this->months);
        }

        $rate = $this->rateMilli / 100_000 / 12;

        return (int) round($this->principal * $rate / (1 - (1 + $rate) ** -$this->months));
    }

    /**
     * @return list<array{number: int, date: CarbonImmutable, payment: int, interest: int, principal: int, balance: int}>
     */
    public function schedule(): array
    {
        if ($this->rows !== null) {
            return $this->rows;
        }

        $rows = [];
        $balance = $this->principal;
        $payment = $this->payment();

        for ($number = 1; $number <= $this->months && $balance > 0; $number++) {
            $interest = $this->interestOn($balance);
            $repaid = $number === $this->months ? $balance : min($balance, $payment - $interest);
            $balance -= $repaid;

            $rows[] = [
                'number' => $number,
                'date' => $this->firstPaymentDate->addMonthsNoOverflow($number - 1),
                'payment' => $repaid + $interest,
                'interest' => $interest,
                'principal' => $repaid,
                'balance' => $balance,
            ];
        }

        return $this->rows = $rows;
    }

    /**
     * Remaining capital once the payments due on or before the date are made.
     */
    public function balanceAt(CarbonImmutable $date): int
    {
        $balance = $this->principal;

        foreach ($this->schedule() as $row) {
            if ($row['date']->gt($date)) {
                break;
            }

            $balance = $row['balance'];
        }

        return $balance;
    }

    public function lastPaymentDate(): CarbonImmutable
    {
        $rows = $this->schedule();

        return $rows === [] ? $this->firstPaymentDate : $rows[array_key_last($rows)]['date'];
    }

    /**
     * Interest of one month, rounded half up without going through floats.
     */
    private function interestOn(int $balance): int
    {
        $denominator = 12 * 100_000;

        return intdiv(2 * $balance * $this->rateMilli + $denominator, 2 * $denominator);
    }
}
