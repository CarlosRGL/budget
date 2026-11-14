<?php

namespace App\Http\Controllers\Budget;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Team;
use App\Models\Transaction;
use App\Models\TransactionSplit;
use App\Support\BudgetMonth;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

/**
 * The transactions behind a category's activity for a month, shown when
 * hovering the "Activité" column. Same rule as the calculator: transactions
 * and split lines of the category dated in the month, on budget accounts.
 */
class CategoryActivityController extends Controller
{
    public function __invoke(Team $current_team, string $month, Category $category): JsonResponse
    {
        $from = BudgetMonth::fromUrl($month);
        $range = [$from->toDateString(), $from->endOfMonth()->toDateString()];
        $inMonthOnBudget = fn (Builder $query) => $query
            ->whereBetween('date', $range)
            ->whereHas('account', fn (Builder $account) => $account->where('on_budget', true));

        $direct = Transaction::query()
            ->where('category_id', $category->id)
            ->where($inMonthOnBudget)
            ->with(['payee:id,name', 'account:id,name'])
            ->get()
            ->map(fn (Transaction $transaction) => $this->row($transaction, $transaction->amount, $transaction->memo));

        $splits = TransactionSplit::query()
            ->where('category_id', $category->id)
            ->whereHas('transaction', $inMonthOnBudget)
            ->with(['transaction.payee:id,name', 'transaction.account:id,name'])
            ->get()
            ->map(fn (TransactionSplit $split) => $this->row($split->transaction, $split->amount, $split->memo ?? $split->transaction->memo));

        return response()->json([
            'transactions' => $direct->concat($splits)
                ->sortBy([['date', 'desc'], ['id', 'desc']])
                ->values()
                ->all(),
        ]);
    }

    /**
     * @return array{id: int, date: string, payee: string|null, account: string, memo: string|null, amount: int}
     */
    private function row(Transaction $transaction, int $amount, ?string $memo): array
    {
        return [
            'id' => $transaction->id,
            'date' => $transaction->date->toDateString(),
            'payee' => $transaction->payee?->name,
            'account' => $transaction->account->name,
            'memo' => $memo,
            'amount' => $amount,
        ];
    }
}
