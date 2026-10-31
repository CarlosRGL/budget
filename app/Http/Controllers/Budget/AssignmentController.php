<?php

namespace App\Http\Controllers\Budget;

use App\Actions\Budget\AssignToCategory;
use App\Http\Controllers\Controller;
use App\Http\Requests\Budget\AssignRequest;
use App\Models\Category;
use App\Models\Team;
use App\Support\BudgetMonth;
use Illuminate\Http\RedirectResponse;

class AssignmentController extends Controller
{
    /**
     * Set what a category gets this month (the "Affecté" column).
     */
    public function __invoke(
        AssignRequest $request,
        Team $current_team,
        string $month,
        Category $category,
        AssignToCategory $assign,
    ): RedirectResponse {
        $assign->handle($request->user(), $category, BudgetMonth::fromUrl($month), (int) $request->validated('amount'));

        return back();
    }
}
