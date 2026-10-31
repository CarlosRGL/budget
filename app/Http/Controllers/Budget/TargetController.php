<?php

namespace App\Http\Controllers\Budget;

use App\Actions\Budget\SaveTarget;
use App\Enums\TargetType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Budget\SaveTargetRequest;
use App\Models\Category;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Inertia\Inertia;

class TargetController extends Controller
{
    /**
     * Create or replace the target of a category.
     */
    public function update(SaveTargetRequest $request, Team $current_team, Category $category, SaveTarget $saveTarget): RedirectResponse
    {
        $dueDate = $request->validated('due_date');

        $saveTarget->handle(
            $category,
            TargetType::from($request->validated('type')),
            (int) $request->validated('amount'),
            $request->validated('weekday') === null ? null : (int) $request->validated('weekday'),
            $dueDate === null ? null : Carbon::parse($dueDate),
            $request->validated('repeat'),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Target saved.')]);

        return back();
    }

    /**
     * Remove the target of a category.
     */
    public function destroy(Team $current_team, Category $category): RedirectResponse
    {
        $category->target()->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Target deleted.')]);

        return back();
    }
}
