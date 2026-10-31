<?php

namespace App\Actions\Budget;

use App\Enums\MoneyMoveKind;
use App\Models\Assignment;
use App\Models\Category;
use App\Models\MoneyMove;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class MoveMoney
{
    /**
     * Move money between two envelopes of a month. A null category is Ready to Assign.
     */
    public function handle(User $by, CarbonImmutable $month, ?Category $from, ?Category $to, int $amount): MoneyMove
    {
        return DB::transaction(function () use ($by, $month, $from, $to, $amount) {
            if ($from !== null) {
                $this->add($from, $month, -$amount);
            }

            if ($to !== null) {
                $this->add($to, $month, $amount);
            }

            return MoneyMove::create([
                'team_id' => $by->current_team_id,
                'month' => $month->toDateString(),
                'from_category_id' => $from?->id,
                'to_category_id' => $to?->id,
                'amount' => $amount,
                'kind' => MoneyMoveKind::Move,
                'user_id' => $by->id,
            ]);
        });
    }

    private function add(Category $category, CarbonImmutable $month, int $amount): void
    {
        $assignment = Assignment::withoutGlobalScopes()->firstOrNew(
            ['category_id' => $category->id, 'month' => $month->toDateString()],
            ['team_id' => $category->team_id, 'amount' => 0],
        );

        $assignment->amount += $amount;
        $assignment->save();
    }
}
