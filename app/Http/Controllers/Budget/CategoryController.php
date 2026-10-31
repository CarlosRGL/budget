<?php

namespace App\Http\Controllers\Budget;

use App\Actions\Budget\CreateCategory;
use App\Actions\Budget\DeleteCategory;
use App\Http\Controllers\Controller;
use App\Http\Requests\Budget\DeleteCategoryRequest;
use App\Http\Requests\Budget\SaveCategoryRequest;
use App\Models\Category;
use App\Models\CategoryGroup;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class CategoryController extends Controller
{
    /**
     * Add a category at the end of a group.
     */
    public function store(SaveCategoryRequest $request, Team $current_team, CategoryGroup $group, CreateCategory $createCategory): RedirectResponse
    {
        $category = $createCategory->handle($group, $request->validated('name'));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Category “:name” added.', ['name' => $category->name])]);

        return back();
    }

    /**
     * Rename, annotate, hide or show a category.
     */
    public function update(SaveCategoryRequest $request, Team $current_team, Category $category): RedirectResponse
    {
        $category->update($request->validated());

        return back();
    }

    /**
     * Delete a category, handing its transactions and assignments to another one.
     */
    public function destroy(DeleteCategoryRequest $request, Team $current_team, Category $category, DeleteCategory $deleteCategory): RedirectResponse
    {
        $replacement = Category::findOrFail($request->integer('replacement_category_id'));

        $deleteCategory->handle($category, $replacement);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Category “:name” deleted, its transactions go to “:replacement”.', ['name' => $category->name, 'replacement' => $replacement->name])]);

        return back();
    }
}
