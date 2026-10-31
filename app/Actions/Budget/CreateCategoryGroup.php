<?php

namespace App\Actions\Budget;

use App\Models\CategoryGroup;
use App\Models\Team;

class CreateCategoryGroup
{
    /**
     * Add a group at the end of the household's budget.
     */
    public function handle(Team $team, string $name): CategoryGroup
    {
        return CategoryGroup::create([
            'team_id' => $team->id,
            'name' => $name,
            'sort' => $this->nextSort($team),
        ]);
    }

    private function nextSort(Team $team): int
    {
        $query = CategoryGroup::withoutGlobalScopes()->where('team_id', $team->id);

        return $query->exists() ? (int) $query->max('sort') + 1 : 0;
    }
}
