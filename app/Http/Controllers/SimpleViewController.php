<?php

namespace App\Http\Controllers;

use App\Budget\BudgetCalculator;
use App\Budget\MonthBudget;
use App\Loans\LoanReport;
use App\Models\Category;
use App\Models\CategoryGroup;
use App\Models\Loan;
use App\Models\Team;
use App\Models\Transaction;
use App\Support\BudgetMonth;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The budget for someone who follows it without running it: what was planned
 * for each category, what was spent and what is left, then every expense.
 */
class SimpleViewController extends Controller
{
    public function overview(Team $current_team, BudgetCalculator $calculator, ?string $month = null): Response
    {
        $month = $month === null ? CarbonImmutable::now()->startOfMonth() : BudgetMonth::fromUrl($month);
        $budget = $calculator->forMonth($current_team, $month);
        $today = CarbonImmutable::today();

        return Inertia::render('simple/overview', [
            'month' => BudgetMonth::toUrl($month),
            'previousMonth' => BudgetMonth::toUrl($month->subMonth()),
            'nextMonth' => BudgetMonth::toUrl($month->addMonth()),
            'groups' => $this->groups($budget),
            'previousSpent' => $this->spent($calculator->forMonth($current_team, $month->subMonth())),
            'recent' => $this->transactions($month)->limit(5)->get()->map($this->row(...))->all(),
            'loans' => Loan::query()->with(['parts', 'account', 'category'])->orderBy('name')->get()
                ->map(fn (Loan $loan) => LoanReport::summary($loan, $today))
                ->all(),
        ]);
    }

    public function spending(Team $current_team, ?string $month = null): Response
    {
        $month = $month === null ? CarbonImmutable::now()->startOfMonth() : BudgetMonth::fromUrl($month);

        return Inertia::render('simple/spending', [
            'month' => BudgetMonth::toUrl($month),
            'previousMonth' => BudgetMonth::toUrl($month->subMonth()),
            'nextMonth' => BudgetMonth::toUrl($month->addMonth()),
            'transactions' => $this->transactions($month)->get()->map($this->row(...))->all(),
        ]);
    }

    /**
     * Spending of the month over every category, refunds deducted.
     */
    private function spent(MonthBudget $budget): int
    {
        return -array_sum(array_map(fn ($category) => $budget->category($category)->activity, Category::query()->pluck('id')->all()));
    }

    /**
     * The visible categories that have something this month, by group.
     *
     * @return list<array<string, mixed>>
     */
    private function groups(MonthBudget $budget): array
    {
        return array_values(CategoryGroup::with('categories')
            ->where('hidden', false)
            ->orderBy('sort')
            ->orderBy('id')
            ->get()
            ->map(function (CategoryGroup $group) use ($budget) {
                $categories = $group->categories
                    ->reject(fn (Category $category) => $category->hidden)
                    ->map(function (Category $category) use ($budget) {
                        $month = $budget->category($category);

                        return [
                            'id' => $category->id,
                            'name' => $category->name,
                            // What the category had to spend this month: last
                            // month's leftover plus what was put in.
                            'planned' => $month->carryover + $month->assigned,
                            'spent' => -$month->activity,
                            'left' => $month->available,
                        ];
                    })
                    ->reject(fn (array $category) => $category['planned'] === 0 && $category['spent'] === 0 && $category['left'] === 0)
                    ->values()
                    ->all();

                return ['id' => $group->id, 'name' => $group->name, 'categories' => $categories];
            })
            ->filter(fn (array $group) => $group['categories'] !== [])
            ->all());
    }

    /**
     * The month's transactions on budget accounts, newest first, without the
     * transfers from one of the household's accounts to another.
     *
     * @return Builder<Transaction>
     */
    private function transactions(CarbonImmutable $month): Builder
    {
        return Transaction::query()
            ->with(['payee:id,name', 'category:id,name', 'account:id,name', 'splits.category:id,name'])
            ->whereHas('account', fn (Builder $query) => $query->where('on_budget', true))
            ->where(fn (Builder $query) => $query->whereNull('transfer_pair_id')->orWhereNotNull('category_id'))
            ->whereBetween('date', [$month->toDateString(), $month->endOfMonth()->toDateString()])
            ->orderByDesc('date')
            ->orderByDesc('id');
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Transaction $transaction): array
    {
        $categories = $transaction->splits->isNotEmpty()
            ? $transaction->splits->map(fn ($split) => $split->category?->name)->filter()->unique()->values()->all()
            : ($transaction->category ? [$transaction->category->name] : []);

        return [
            'id' => $transaction->id,
            'date' => $transaction->date->toDateString(),
            'payee' => $transaction->payee?->name,
            'categories' => $categories,
            'income' => $transaction->inflow_to_rta,
            'account' => $transaction->account?->name,
            'memo' => $transaction->memo,
            'amount' => $transaction->amount,
        ];
    }
}
