<?php

namespace App\Actions\Budget;

use App\Models\Category;
use App\Models\CategoryGroup;
use App\Models\Team;
use Illuminate\Support\Facades\DB;

class ReorderBudget
{
    /**
     * Save the order of the groups and of the categories inside them; a category may
     * land in another group. Ids must already be checked to belong to the household.
     *
     * @param  array<int, array{group_id: int, category_ids: array<int, int>}>  $groups
     */
    public function handle(Team $team, array $groups): void
    {
        DB::transaction(function () use ($team, $groups) {
            foreach (array_values($groups) as $groupSort => $group) {
                CategoryGroup::withoutGlobalScopes()
                    ->where('team_id', $team->id)
                    ->whereKey($group['group_id'])
                    ->update(['sort' => $groupSort]);

                foreach (array_values($group['category_ids']) as $sort => $categoryId) {
                    Category::withoutGlobalScopes()
                        ->where('team_id', $team->id)
                        ->whereKey($categoryId)
                        ->update(['category_group_id' => $group['group_id'], 'sort' => $sort]);
                }
            }
        });
    }
}
