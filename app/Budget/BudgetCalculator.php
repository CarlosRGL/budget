<?php

namespace App\Budget;

use App\Models\Account;
use App\Models\Assignment;
use App\Models\Category;
use App\Models\Team;
use App\Models\Transaction;
use App\Models\TransactionSplit;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Computes every budget figure from assignments and transactions. Nothing is stored:
 * this is the single source of truth (design §5.7).
 *
 * For a category c and a month m:
 *   activity(c, m)  = transactions and split lines of c dated in m, on budget accounts, approved or not
 *   carryover(c, m) = max(0, available(c, m - 1)): overspending never carries over
 *   available(c, m) = carryover + assigned + activity
 *
 * Ready to Assign, seen from month m (the current month or a future one):
 *   inflows to Ready to Assign dated up to the end of m
 *   - every assignment, future months included
 *   - every overspent amount of every month before m
 * A past month shows the amount of the current month, like YNAB: it is money
 * that can be assigned today, wherever it is looked at from.
 *
 * Queries ignore the current-team global scope and always filter on the given team,
 * so the calculator also works from the console and queued jobs.
 */
class BudgetCalculator
{
    public function forMonth(Team $team, CarbonImmutable $month): MonthBudget
    {
        $month = $month->startOfMonth();
        $current = CarbonImmutable::now()->startOfMonth();
        $budget = $this->compute($team, $month)['budget'];

        return $month->lessThan($current)
            ? $budget->withReadyToAssign($this->compute($team, $current)['budget']->readyToAssign)
            : $budget;
    }

    public function categoryDetail(Team $team, Category $category, CarbonImmutable $month): CategoryDetail
    {
        $month = $month->startOfMonth();
        ['budget' => $budget, 'history' => $history] = $this->compute($team, $month);

        return $this->detail($budget->category($category), $history, $month);
    }

    /**
     * Detail figures of every category of the household, computed in one pass.
     *
     * @return array<int, CategoryDetail> Keyed by category id.
     */
    public function categoryDetails(Team $team, CarbonImmutable $month): array
    {
        $month = $month->startOfMonth();
        ['budget' => $budget, 'history' => $history] = $this->compute($team, $month);

        return array_map(
            fn (CategoryMonth $category) => $this->detail($category, $history, $month),
            $budget->categories,
        );
    }

    /**
     * @param  array<string, array<int, CategoryMonth>>  $history
     */
    private function detail(CategoryMonth $category, array $history, CarbonImmutable $month): CategoryDetail
    {
        $lastMonth = $history[$month->subMonth()->format('Y-m')][$category->categoryId] ?? null;

        $previousMonths = collect(range(1, 12))
            ->map(fn (int $monthsAgo) => $history[$month->subMonths($monthsAgo)->format('Y-m')][$category->categoryId] ?? null)
            ->filter(fn (?CategoryMonth $past) => $past !== null && ($past->assigned !== 0 || $past->activity !== 0));

        return new CategoryDetail(
            month: $category,
            assignedLastMonth: $lastMonth->assigned ?? 0,
            spentLastMonth: -($lastMonth->activity ?? 0),
            averageAssigned: (int) round($previousMonths->avg(fn (CategoryMonth $past) => $past->assigned) ?? 0),
            averageSpent: (int) round($previousMonths->avg(fn (CategoryMonth $past) => -$past->activity) ?? 0),
        );
    }

    /**
     * @return array{budget: MonthBudget, history: array<string, array<int, CategoryMonth>>}
     */
    private function compute(Team $team, CarbonImmutable $month): array
    {
        $endOfMonth = $month->endOfMonth()->toDateString();
        $budgetAccountIds = Account::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->where('on_budget', true)
            ->pluck('id');

        /** @var Collection<int, int> $groupOf category id => group id */
        $groupOf = Category::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->pluck('category_group_id', 'id');

        $activity = $this->activityByCategoryAndMonth($team, $budgetAccountIds, $endOfMonth);
        $assigned = $this->assignedByCategoryAndMonth($team);

        $history = [];
        $available = [];
        $overspentBefore = 0;

        foreach ($this->monthsUpTo($month, $activity, $assigned) as $current) {
            $key = $current->format('Y-m');
            $figures = [];

            foreach ($groupOf as $categoryId => $groupId) {
                $figures[$categoryId] = new CategoryMonth(
                    categoryId: $categoryId,
                    groupId: $groupId,
                    carryover: max(0, $available[$categoryId] ?? 0),
                    assigned: $assigned[$categoryId][$key] ?? 0,
                    activity: $activity[$categoryId][$key] ?? 0,
                );
                $available[$categoryId] = $figures[$categoryId]->available;
            }

            $history[$key] = $figures;

            if ($current->lessThan($month)) {
                $overspentBefore += array_sum(array_map(fn (CategoryMonth $category) => min(0, $category->available), $figures));
            }
        }

        $categories = $history[$month->format('Y-m')];

        $groups = [];
        foreach ($categories as $category) {
            $groups[$category->groupId] = ($groups[$category->groupId] ?? new GroupTotals($category->groupId))->add($category);
        }

        $readyToAssign = $this->inflowsToReadyToAssign($team, $budgetAccountIds, $endOfMonth)
            - array_sum(array_map('array_sum', $assigned))
            + $overspentBefore;

        return [
            'budget' => new MonthBudget(
                teamId: $team->id,
                month: $month,
                readyToAssign: $readyToAssign,
                uncategorizedActivity: $this->uncategorizedActivity($team, $budgetAccountIds, $month),
                categories: $categories,
                groups: $groups,
            ),
            'history' => $history,
        ];
    }

    /**
     * Months from the first one holding budget data up to the given month.
     *
     * @param  array<int, array<string, int>>  $activity
     * @param  array<int, array<string, int>>  $assigned
     * @return list<CarbonImmutable>
     */
    private function monthsUpTo(CarbonImmutable $month, array $activity, array $assigned): array
    {
        $keys = [];
        foreach ([$activity, $assigned] as $byCategory) {
            foreach ($byCategory as $byMonth) {
                $keys = [...$keys, ...array_keys($byMonth)];
            }
        }

        $first = $keys === [] ? $month : min(CarbonImmutable::parse(min($keys).'-01'), $month);

        $months = [];
        for ($current = $first; $current->lessThanOrEqualTo($month); $current = $current->addMonth()) {
            $months[] = $current;
        }

        return $months;
    }

    /**
     * @param  Collection<int, int>  $budgetAccountIds
     * @return array<int, array<string, int>> category id => month (Y-m) => cents
     */
    private function activityByCategoryAndMonth(Team $team, Collection $budgetAccountIds, string $until): array
    {
        $direct = Transaction::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->whereIn('account_id', $budgetAccountIds)
            ->whereNotNull('category_id')
            ->where('date', '<=', $until)
            ->groupBy('category_id', 'date')
            ->selectRaw('category_id, date, SUM(amount) as total')
            ->toBase()
            ->get();

        $splits = TransactionSplit::query()
            ->join('transactions', 'transactions.id', '=', 'transaction_splits.transaction_id')
            ->where('transactions.team_id', $team->id)
            ->whereIn('transactions.account_id', $budgetAccountIds)
            ->whereNotNull('transaction_splits.category_id')
            ->where('transactions.date', '<=', $until)
            ->groupBy('transaction_splits.category_id', 'transactions.date')
            ->selectRaw('transaction_splits.category_id as category_id, transactions.date as date, SUM(transaction_splits.amount) as total')
            ->toBase()
            ->get();

        $activity = [];
        foreach ($direct->concat($splits) as $row) {
            $key = substr((string) $row->date, 0, 7);
            $activity[(int) $row->category_id][$key] = ($activity[(int) $row->category_id][$key] ?? 0) + (int) $row->total;
        }

        return $activity;
    }

    /**
     * Every assignment of the household, future months included.
     *
     * @return array<int, array<string, int>> category id => month (Y-m) => cents
     */
    private function assignedByCategoryAndMonth(Team $team): array
    {
        $assigned = [];
        $rows = Assignment::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->toBase()
            ->get(['category_id', 'month', 'amount']);

        foreach ($rows as $row) {
            $key = substr((string) $row->month, 0, 7);
            $assigned[(int) $row->category_id][$key] = ($assigned[(int) $row->category_id][$key] ?? 0) + (int) $row->amount;
        }

        return $assigned;
    }

    /**
     * @param  Collection<int, int>  $budgetAccountIds
     */
    private function inflowsToReadyToAssign(Team $team, Collection $budgetAccountIds, string $until): int
    {
        $direct = Transaction::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->whereIn('account_id', $budgetAccountIds)
            ->where('inflow_to_rta', true)
            ->where('date', '<=', $until)
            ->sum('amount');

        $splits = TransactionSplit::query()
            ->join('transactions', 'transactions.id', '=', 'transaction_splits.transaction_id')
            ->where('transactions.team_id', $team->id)
            ->whereIn('transactions.account_id', $budgetAccountIds)
            ->where('transaction_splits.inflow_to_rta', true)
            ->where('transactions.date', '<=', $until)
            ->sum('transaction_splits.amount');

        return (int) $direct + (int) $splits;
    }

    /**
     * Money that moved on budget accounts this month without reaching any envelope:
     * no category, not Ready to Assign, not split, and not a transfer between two budget accounts.
     *
     * @param  Collection<int, int>  $budgetAccountIds
     */
    private function uncategorizedActivity(Team $team, Collection $budgetAccountIds, CarbonImmutable $month): int
    {
        return (int) Transaction::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->whereIn('account_id', $budgetAccountIds)
            ->whereBetween('date', [$month->toDateString(), $month->endOfMonth()->toDateString()])
            ->whereNull('category_id')
            ->where('inflow_to_rta', false)
            ->whereDoesntHave('splits')
            ->where(fn (Builder $query) => $query
                ->whereNull('transfer_pair_id')
                ->orWhereHas('transferPair', fn (Builder $pair) => $pair
                    ->withoutGlobalScopes()
                    ->whereNotIn('account_id', $budgetAccountIds)))
            ->sum('amount');
    }
}
