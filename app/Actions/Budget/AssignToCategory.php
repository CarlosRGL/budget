<?php

namespace App\Actions\Budget;

use App\Enums\MoneyMoveKind;
use App\Models\Assignment;
use App\Models\Category;
use App\Models\MoneyMove;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class AssignToCategory
{
    /**
     * Set what a category gets for a month and log the change in the money moves.
     *
     * A raise comes from Ready to Assign, a cut goes back to it. Returns the change, in cents.
     */
    public function handle(
        User $by,
        Category $category,
        CarbonImmutable $month,
        int $amount,
        MoneyMoveKind $kind = MoneyMoveKind::Assign,
    ): int {
        return DB::transaction(function () use ($by, $category, $month, $amount, $kind) {
            $assignment = Assignment::withoutGlobalScopes()->firstOrNew(
                ['category_id' => $category->id, 'month' => $month->toDateString()],
                ['team_id' => $category->team_id, 'amount' => 0],
            );

            $delta = $amount - $assignment->amount;

            if ($delta === 0) {
                return 0;
            }

            $assignment->amount = $amount;
            $assignment->save();

            MoneyMove::create([
                'team_id' => $category->team_id,
                'month' => $month->toDateString(),
                'from_category_id' => $delta < 0 ? $category->id : null,
                'to_category_id' => $delta > 0 ? $category->id : null,
                'amount' => abs($delta),
                'kind' => $kind,
                'user_id' => $by->id,
            ]);

            return $delta;
        });
    }
}
