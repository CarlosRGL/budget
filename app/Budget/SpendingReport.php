<?php

namespace App\Budget;

use App\Models\Account;
use App\Models\Team;
use App\Models\Transaction;
use App\Models\TransactionSplit;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Figures of the statistics page, on budget accounts and with the budget's
 * rules (BudgetCalculator): spending is the net activity of each category
 * (refunds lower it), income is what went to Ready to Assign, by payee.
 * Starting balances are neither, and transfers between budget accounts are
 * not spending.
 */
class SpendingReport
{
    /**
     * @param  list<int>|null  $accountIds  Budget accounts to keep, null for all of them.
     * @return array{
     *     spending: list<array{categoryId: int, month: string, amount: int}>,
     *     uncategorized: array<string, int>,
     *     income: list<array{payee: string, month: string, amount: int}>,
     * }
     */
    public function forPeriod(Team $team, CarbonImmutable $from, CarbonImmutable $to, ?array $accountIds = null): array
    {
        $accounts = Account::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->where('on_budget', true)
            ->when($accountIds !== null, fn (Builder $query) => $query->whereIn('id', $accountIds))
            ->pluck('id');
        $budgetAccounts = Account::withoutGlobalScopes()->where('team_id', $team->id)->where('on_budget', true)->pluck('id');
        $period = [$from->startOfMonth()->toDateString(), $to->endOfMonth()->toDateString()];

        return [
            'spending' => $this->spending($team, $accounts, $period),
            'uncategorized' => $this->uncategorized($team, $accounts, $budgetAccounts, $period),
            'income' => $this->income($team, $accounts, $period),
        ];
    }

    /**
     * @param  Collection<int, int>  $accounts
     * @param  array{string, string}  $period
     * @return list<array{categoryId: int, month: string, amount: int}>
     */
    private function spending(Team $team, Collection $accounts, array $period): array
    {
        $direct = Transaction::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->whereIn('account_id', $accounts)
            ->whereNotNull('category_id')
            ->whereBetween('date', $period)
            ->groupBy('category_id', 'date')
            ->selectRaw('category_id, date, SUM(amount) as total')
            ->toBase()
            ->get();

        $splits = TransactionSplit::query()
            ->join('transactions', 'transactions.id', '=', 'transaction_splits.transaction_id')
            ->where('transactions.team_id', $team->id)
            ->whereIn('transactions.account_id', $accounts)
            ->whereNotNull('transaction_splits.category_id')
            ->whereBetween('transactions.date', $period)
            ->groupBy('transaction_splits.category_id', 'transactions.date')
            ->selectRaw('transaction_splits.category_id as category_id, transactions.date as date, SUM(transaction_splits.amount) as total')
            ->toBase()
            ->get();

        $totals = [];
        foreach ($direct->concat($splits) as $row) {
            $key = (int) $row->category_id.'|'.substr((string) $row->date, 0, 7);
            $totals[$key] = ($totals[$key] ?? 0) - (int) $row->total;
        }

        $rows = [];
        foreach ($totals as $key => $amount) {
            [$categoryId, $month] = explode('|', $key);
            $rows[] = ['categoryId' => (int) $categoryId, 'month' => $month, 'amount' => $amount];
        }

        return $rows;
    }

    /**
     * Spending that reached no envelope yet (see BudgetCalculator::uncategorizedActivity).
     *
     * @param  Collection<int, int>  $accounts
     * @param  Collection<int, int>  $budgetAccounts
     * @param  array{string, string}  $period
     * @return array<string, int> month => cents spent
     */
    private function uncategorized(Team $team, Collection $accounts, Collection $budgetAccounts, array $period): array
    {
        $rows = Transaction::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->whereIn('account_id', $accounts)
            ->whereBetween('date', $period)
            ->whereNull('category_id')
            ->where('inflow_to_rta', false)
            ->where('is_starting_balance', false)
            ->whereDoesntHave('splits')
            ->where(fn (Builder $query) => $query
                ->whereNull('transfer_pair_id')
                ->orWhereHas('transferPair', fn (Builder $pair) => $pair
                    ->withoutGlobalScopes()
                    ->whereNotIn('account_id', $budgetAccounts)))
            ->toBase()
            ->get(['date', 'amount']);

        $totals = [];
        foreach ($rows as $row) {
            $month = substr((string) $row->date, 0, 7);
            $totals[$month] = ($totals[$month] ?? 0) - (int) $row->amount;
        }

        return $totals;
    }

    /**
     * @param  Collection<int, int>  $accounts
     * @param  array{string, string}  $period
     * @return list<array{payee: string, month: string, amount: int}>
     */
    private function income(Team $team, Collection $accounts, array $period): array
    {
        $direct = Transaction::withoutGlobalScopes()
            ->leftJoin('payees', 'payees.id', '=', 'transactions.payee_id')
            ->where('transactions.team_id', $team->id)
            ->whereIn('transactions.account_id', $accounts)
            ->where('transactions.inflow_to_rta', true)
            ->where('transactions.is_starting_balance', false)
            ->whereBetween('transactions.date', $period)
            ->toBase()
            ->get(['payees.name as payee', 'transactions.date', 'transactions.amount']);

        $splits = TransactionSplit::query()
            ->join('transactions', 'transactions.id', '=', 'transaction_splits.transaction_id')
            ->leftJoin('payees', 'payees.id', '=', 'transactions.payee_id')
            ->where('transactions.team_id', $team->id)
            ->whereIn('transactions.account_id', $accounts)
            ->where('transaction_splits.inflow_to_rta', true)
            ->whereBetween('transactions.date', $period)
            ->toBase()
            ->get(['payees.name as payee', 'transactions.date', 'transaction_splits.amount']);

        $totals = [];
        foreach ($direct->concat($splits) as $row) {
            $key = ($row->payee ?? __('No payee')).'|'.substr((string) $row->date, 0, 7);
            $totals[$key] = ($totals[$key] ?? 0) + (int) $row->amount;
        }

        $rows = [];
        foreach ($totals as $key => $amount) {
            $separator = strrpos($key, '|');
            $rows[] = ['payee' => substr($key, 0, (int) $separator), 'month' => substr($key, (int) $separator + 1), 'amount' => $amount];
        }

        return $rows;
    }
}
