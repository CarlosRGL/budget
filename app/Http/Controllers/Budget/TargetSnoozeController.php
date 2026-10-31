<?php

namespace App\Http\Controllers\Budget;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\TargetSnooze;
use App\Models\Team;
use App\Support\BudgetMonth;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class TargetSnoozeController extends Controller
{
    /**
     * Pause the category's target for the month.
     */
    public function store(Team $current_team, string $month, Category $category): RedirectResponse
    {
        TargetSnooze::firstOrCreate([
            'category_id' => $category->id,
            'month' => BudgetMonth::fromUrl($month)->toDateString(),
        ]);

        return back();
    }

    /**
     * Resume the category's target for the month.
     */
    public function destroy(Team $current_team, string $month, Category $category): RedirectResponse
    {
        $category->targetSnoozes()->where('month', BudgetMonth::fromUrl($month)->toDateString())->delete();

        return back();
    }

    /**
     * Pause or resume the targets of several categories for the month.
     */
    public function bulk(Request $request, Team $current_team, string $month): RedirectResponse
    {
        $validated = $request->validate([
            'category_ids' => ['required', 'array', 'min:1'],
            'category_ids.*' => ['integer', 'distinct', Rule::exists('categories', 'id')->where('team_id', $current_team->id)],
            'snoozed' => ['required', 'boolean'],
        ]);

        $day = BudgetMonth::fromUrl($month)->toDateString();
        $categories = Category::query()->whereKey($validated['category_ids'])->has('target')->get();

        foreach ($categories as $category) {
            if ($validated['snoozed']) {
                TargetSnooze::firstOrCreate(['category_id' => $category->id, 'month' => $day]);
            } else {
                $category->targetSnoozes()->where('month', $day)->delete();
            }
        }

        $count = $categories->count();
        Inertia::flash('toast', ['type' => 'success', 'message' => match (true) {
            $count === 0 => __('No target in the selection.'),
            (bool) $validated['snoozed'] => trans_choice(':count target snoozed this month.|:count targets snoozed this month.', $count),
            default => trans_choice(':count target resumed.|:count targets resumed.', $count),
        }]);

        return back();
    }
}
