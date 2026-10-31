<?php

namespace App\Loans;

use App\Models\Loan;
use App\Models\LoanPart;
use App\Models\Transaction;
use App\Models\TransactionSplit;
use Carbon\CarbonImmutable;

/**
 * A loan as the screens show it: where it stands today and its month by
 * month schedule, all lines together.
 */
final class LoanReport
{
    /**
     * @return array<string, mixed>
     */
    public static function summary(Loan $loan, CarbonImmutable $today): array
    {
        $parts = $loan->parts;
        $amortizations = $parts->map(fn (LoanPart $part) => $part->amortization());

        $principal = (int) $parts->sum('principal');
        $remaining = self::remaining($loan, $today);
        $totalInterest = (int) $amortizations->sum(fn (Amortization $a) => array_sum(array_column($a->schedule(), 'interest')));
        $paidInterest = (int) $amortizations->sum(fn (Amortization $a) => array_sum(array_column(
            array_filter($a->schedule(), fn (array $row) => $row['date']->lte($today)),
            'interest',
        )));
        $next = $amortizations
            ->map(fn (Amortization $a) => collect($a->schedule())->first(fn (array $row) => $row['date']->gt($today)))
            ->filter();

        return [
            'id' => $loan->id,
            'name' => $loan->name,
            'category' => $loan->category ? ['id' => $loan->category->id, 'name' => $loan->category->name] : null,
            'account' => $loan->account ? [
                'id' => $loan->account->id,
                'name' => $loan->account->name,
                'balance' => (int) $loan->account->transactions()->sum('amount'),
            ] : null,
            'principal' => $principal,
            'remaining' => $remaining,
            'repaid' => $principal - $remaining,
            'payment' => self::payment($loan, $today),
            'nextPaymentDate' => $next->min(fn (array $row) => $row['date'])?->toDateString(),
            'totalInterest' => $totalInterest,
            'paidInterest' => $paidInterest,
            'endDate' => $amortizations->max(fn (Amortization $a) => $a->lastPaymentDate())?->toDateString(),
            'parts' => $parts->map(fn (LoanPart $part) => [
                'id' => $part->id,
                'label' => $part->label,
                'principal' => $part->principal,
                'rateMilli' => $part->rate_milli,
                'months' => $part->months,
                'firstPaymentDate' => $part->first_payment_date->toDateString(),
                'payment' => $part->payment,
                'computedPayment' => $part->amortization()->payment(),
                'remaining' => $part->amortization()->balanceAt($today),
            ])->all(),
        ];
    }

    /**
     * The monthly payment of the lines not repaid yet.
     */
    public static function payment(Loan $loan, CarbonImmutable $today): int
    {
        $payment = 0;

        foreach ($loan->parts as $part) {
            $amortization = $part->amortization();
            $payment += $amortization->balanceAt($today) > 0 ? $amortization->payment() : 0;
        }

        return $payment;
    }

    /**
     * Capital still owed once the payments due on or before the date are made.
     */
    public static function remaining(Loan $loan, CarbonImmutable $date): int
    {
        $remaining = 0;

        foreach ($loan->parts as $part) {
            $remaining += $part->amortization()->balanceAt($date);
        }

        return $remaining;
    }

    /**
     * One row per month: what each line still owes after that month's payment,
     * and the interest and capital paid that month.
     *
     * @return list<array{month: string, balances: list<int>, interest: int, principal: int}>
     */
    public static function months(Loan $loan): array
    {
        $amortizations = $loan->parts->map(fn (LoanPart $part) => $part->amortization())->values();
        $months = [];

        foreach ($amortizations as $amortization) {
            foreach ($amortization->schedule() as $row) {
                $month = $row['date']->format('Y-m');
                $months[$month]['interest'] = ($months[$month]['interest'] ?? 0) + $row['interest'];
                $months[$month]['principal'] = ($months[$month]['principal'] ?? 0) + $row['principal'];
            }
        }

        ksort($months);
        $rows = [];

        foreach ($months as $month => $paid) {
            $endOfMonth = CarbonImmutable::parse("{$month}-01")->endOfMonth();

            $rows[] = [
                'month' => (string) $month,
                'balances' => array_values($amortizations->map(fn (Amortization $a) => $a->balanceAt($endOfMonth))->all()),
                'interest' => $paid['interest'],
                'principal' => $paid['principal'],
            ];
        }

        return $rows;
    }

    /**
     * Every due date, all lines together: what is paid that day and how it
     * splits between interest and capital.
     *
     * @return list<array{date: CarbonImmutable, payment: int, interest: int, principal: int}>
     */
    public static function dueDates(Loan $loan): array
    {
        $dues = [];

        foreach ($loan->parts as $part) {
            foreach ($part->amortization()->schedule() as $row) {
                $key = $row['date']->toDateString();
                $dues[$key] ??= ['date' => $row['date'], 'payment' => 0, 'interest' => 0, 'principal' => 0];
                $dues[$key]['payment'] += $row['payment'];
                $dues[$key]['interest'] += $row['interest'];
                $dues[$key]['principal'] += $row['principal'];
            }
        }

        ksort($dues);

        return array_values($dues);
    }

    /**
     * The payments found in the loan's category, month by month, against what
     * the schedule expects. Starts with the household's first transaction:
     * earlier months were never typed in the app.
     *
     * @return list<array{month: string, dueDate: string, expected: int, paid: int, status: string, transactions: list<array{id: int, date: string, payee: string|null, account: string|null, amount: int}>}>
     */
    public static function payments(Loan $loan, CarbonImmutable $today): array
    {
        $categoryId = $loan->category_id;
        $firstDate = Transaction::query()->min('date');

        if ($categoryId === null || $firstDate === null) {
            return [];
        }

        $from = CarbonImmutable::parse((string) $firstDate)->startOfMonth();
        $until = $today->endOfMonth();
        $dues = array_filter(
            self::dueDates($loan),
            fn (array $due) => $due['date']->between($from, $until),
        );

        if ($dues === []) {
            return [];
        }

        $found = [];
        $direct = Transaction::query()
            ->with(['payee:id,name', 'account:id,name'])
            ->where('category_id', $categoryId)
            ->whereBetween('date', [$from->toDateString(), $until->toDateString()])
            ->get();

        foreach ($direct as $transaction) {
            $found[] = [
                'id' => $transaction->id,
                'date' => $transaction->date->toDateString(),
                'payee' => $transaction->payee?->name,
                'account' => $transaction->account?->name,
                'amount' => $transaction->amount,
            ];
        }

        $splits = TransactionSplit::query()
            ->with(['transaction.payee:id,name', 'transaction.account:id,name'])
            ->where('category_id', $categoryId)
            ->whereHas('transaction', fn ($query) => $query->whereBetween('date', [$from->toDateString(), $until->toDateString()]))
            ->get();

        foreach ($splits as $split) {
            $transaction = $split->transaction;
            $found[] = [
                'id' => $transaction->id,
                'date' => $transaction->date->toDateString(),
                'payee' => $transaction->payee?->name,
                'account' => $transaction->account?->name,
                'amount' => $split->amount,
            ];
        }

        $byMonth = [];

        foreach ($found as $row) {
            $byMonth[substr($row['date'], 0, 7)][] = $row;
        }

        $rows = [];

        foreach (array_reverse($dues) as $due) {
            $month = $due['date']->format('Y-m');
            $transactions = $byMonth[$month] ?? [];
            usort($transactions, fn (array $a, array $b) => strcmp($a['date'], $b['date']));
            $paid = -array_sum(array_column($transactions, 'amount'));

            $rows[] = [
                'month' => $month,
                'dueDate' => $due['date']->toDateString(),
                'expected' => $due['payment'],
                'paid' => $paid,
                'status' => match (true) {
                    $paid === $due['payment'] => 'paid',
                    $paid !== 0 => 'different',
                    $due['date']->gt($today) => 'upcoming',
                    default => 'missing',
                },
                'transactions' => $transactions,
            ];
        }

        return $rows;
    }
}
