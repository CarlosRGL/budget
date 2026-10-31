<?php

namespace App\Actions\Budget;

use App\Models\Assignment;
use App\Models\Category;
use App\Models\MoneyMove;
use App\Models\Payee;
use App\Models\Transaction;
use App\Models\TransactionSplit;
use Illuminate\Support\Facades\DB;

class DeleteCategory
{
    /**
     * Delete a category after handing everything it holds to another one of the household:
     * transactions, split lines, payees' last category, assignments (summed month by month)
     * and money moves. Its target and snoozes are dropped with it.
     */
    public function handle(Category $category, Category $replacement): void
    {
        DB::transaction(function () use ($category, $replacement) {
            Transaction::withoutGlobalScopes()
                ->where('category_id', $category->id)
                ->update(['category_id' => $replacement->id]);

            TransactionSplit::query()
                ->where('category_id', $category->id)
                ->update(['category_id' => $replacement->id]);

            Payee::withoutGlobalScopes()
                ->where('last_category_id', $category->id)
                ->update(['last_category_id' => $replacement->id]);

            $this->mergeAssignments($category, $replacement);
            $this->repointMoneyMoves($category, $replacement);

            $category->delete();
        });
    }

    private function mergeAssignments(Category $category, Category $replacement): void
    {
        $assignments = Assignment::withoutGlobalScopes()->where('category_id', $category->id)->get();

        foreach ($assignments as $assignment) {
            $target = Assignment::withoutGlobalScopes()->firstOrNew(
                ['category_id' => $replacement->id, 'month' => $assignment->month->toDateString()],
                ['team_id' => $replacement->team_id, 'amount' => 0],
            );

            $target->amount += $assignment->amount;
            $target->save();
            $assignment->delete();
        }
    }

    /**
     * Keep the history readable: moves of the deleted category now show its replacement,
     * and moves between the two categories, now meaningless, disappear.
     */
    private function repointMoneyMoves(Category $category, Category $replacement): void
    {
        $moves = MoneyMove::withoutGlobalScopes()->where('team_id', $category->team_id);

        (clone $moves)->where('from_category_id', $category->id)->update(['from_category_id' => $replacement->id]);
        (clone $moves)->where('to_category_id', $category->id)->update(['to_category_id' => $replacement->id]);
        (clone $moves)->where('from_category_id', $replacement->id)->where('to_category_id', $replacement->id)->delete();
    }
}
