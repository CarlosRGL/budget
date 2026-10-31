<?php

namespace App\Actions\Budget;

use App\Enums\TargetType;
use App\Models\Category;
use App\Models\Target;
use Carbon\CarbonInterface;

class SaveTarget
{
    /**
     * Give a category its target, replacing the previous one. Only weekly targets keep
     * a weekday, and only "by date" targets keep a due date and a yearly repeat.
     */
    public function handle(
        Category $category,
        TargetType $type,
        int $amount,
        ?int $weekday = null,
        ?CarbonInterface $dueDate = null,
        ?string $repeat = null,
    ): Target {
        $byDate = $type === TargetType::ByDate;

        return Target::withoutGlobalScopes()->updateOrCreate(
            ['category_id' => $category->id],
            [
                'team_id' => $category->team_id,
                'type' => $type,
                'amount' => $amount,
                'weekday' => $type === TargetType::Weekly ? $weekday : null,
                'due_date' => $byDate ? $dueDate : null,
                'repeat' => $byDate ? $repeat : null,
            ],
        );
    }
}
