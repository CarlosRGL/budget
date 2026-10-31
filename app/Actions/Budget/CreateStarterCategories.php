<?php

namespace App\Actions\Budget;

use App\Models\CategoryGroup;
use App\Models\Team;
use Illuminate\Support\Facades\DB;

class CreateStarterCategories
{
    public function __construct(
        private CreateCategoryGroup $createGroup,
        private CreateCategory $createCategory,
    ) {}

    /**
     * Create the groups and categories picked during the onboarding, reusing
     * a group of the household that already has the same name.
     *
     * @param  list<array{name: string, categories: list<string>}>  $groups
     */
    public function handle(Team $team, array $groups): void
    {
        DB::transaction(function () use ($team, $groups) {
            foreach ($groups as $group) {
                $name = trim($group['name']);

                $categoryGroup = CategoryGroup::withoutGlobalScopes()
                    ->where('team_id', $team->id)
                    ->where('name', $name)
                    ->first() ?? $this->createGroup->handle($team, $name);

                foreach ($group['categories'] as $category) {
                    $this->createCategory->handle($categoryGroup, trim($category));
                }
            }
        });
    }
}
