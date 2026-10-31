<?php

namespace App\Http\Controllers\Budget;

use App\Budget\SpendingReport;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Category;
use App\Models\CategoryGroup;
use App\Models\Team;
use App\Support\BudgetMonth;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Statistics over a range of months: spending trends, breakdown by category
 * and income versus expense. The page draws every view from the same figures.
 */
class StatsController extends Controller
{
    private const DEFAULT_MONTHS = 6;

    private const MAX_MONTHS = 36;

    public function __invoke(Request $request, Team $current_team, SpendingReport $report): Response
    {
        $current = CarbonImmutable::now()->startOfMonth();
        $to = $request->filled('to') ? BudgetMonth::fromUrl((string) $request->string('to')) : $current;
        $from = $request->filled('from') ? BudgetMonth::fromUrl((string) $request->string('from')) : $to->subMonths(self::DEFAULT_MONTHS - 1);

        if ($from->gt($to)) {
            [$from, $to] = [$to, $from];
        }
        $from = $from->max($to->subMonths(self::MAX_MONTHS - 1));

        $accounts = Account::query()->where('on_budget', true)->orderBy('sort')->get(['id', 'name', 'closed_at']);
        $selected = $request->filled('accounts')
            ? array_values(array_intersect($accounts->modelKeys(), array_map('intval', explode(',', (string) $request->string('accounts')))))
            : null;

        $months = [];
        for ($month = $from; $month->lte($to); $month = $month->addMonth()) {
            $months[] = BudgetMonth::toUrl($month);
        }

        return Inertia::render('stats/show', [
            'from' => BudgetMonth::toUrl($from),
            'to' => BudgetMonth::toUrl($to),
            'months' => $months,
            'accounts' => $accounts->map(fn (Account $account) => [
                'id' => $account->id,
                'name' => $account->name,
                'closed' => $account->closed_at !== null,
            ])->all(),
            'selectedAccounts' => $selected,
            'groups' => CategoryGroup::query()
                ->with(['categories' => fn ($query) => $query->orderBy('sort')])
                ->orderBy('sort')
                ->get()
                ->map(fn (CategoryGroup $group) => [
                    'id' => $group->id,
                    'name' => $group->name,
                    'categories' => $group->categories->map(fn (Category $category) => [
                        'id' => $category->id,
                        'name' => $category->name,
                    ])->all(),
                ])->all(),
            ...$report->forPeriod($current_team, $from, $to, $selected),
        ]);
    }
}
