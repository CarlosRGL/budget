<?php

namespace App\Actions\Budget;

use App\Models\Category;
use App\Models\CategoryGroup;

class CreateCategory
{
    /**
     * Add a category at the end of its group.
     */
    public function handle(CategoryGroup $group, string $name): Category
    {
        $siblings = Category::withoutGlobalScopes()->where('category_group_id', $group->id);

        return Category::create([
            'team_id' => $group->team_id,
            'category_group_id' => $group->id,
            'name' => $name,
            'sort' => $siblings->exists() ? (int) $siblings->max('sort') + 1 : 0,
        ]);
    }
}
