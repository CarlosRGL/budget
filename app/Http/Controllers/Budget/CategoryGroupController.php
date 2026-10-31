<?php

namespace App\Http\Controllers\Budget;

use App\Actions\Budget\CreateCategoryGroup;
use App\Http\Controllers\Controller;
use App\Http\Requests\Budget\SaveCategoryGroupRequest;
use App\Models\CategoryGroup;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class CategoryGroupController extends Controller
{
    /**
     * Add a group at the end of the budget.
     */
    public function store(SaveCategoryGroupRequest $request, Team $current_team, CreateCategoryGroup $createGroup): RedirectResponse
    {
        $group = $createGroup->handle($current_team, $request->validated('name'));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Group “:name” added.', ['name' => $group->name])]);

        return back();
    }

    /**
     * Rename, hide or show a group.
     */
    public function update(SaveCategoryGroupRequest $request, Team $current_team, CategoryGroup $group): RedirectResponse
    {
        $group->update($request->validated());

        return back();
    }

    /**
     * Delete a group once it is empty.
     */
    public function destroy(Team $current_team, CategoryGroup $group): RedirectResponse
    {
        abort_if(
            $group->categories()->exists(),
            422,
            __('This group still has categories: move or delete them first.'),
        );

        $group->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Group “:name” deleted.', ['name' => $group->name])]);

        return back();
    }
}
