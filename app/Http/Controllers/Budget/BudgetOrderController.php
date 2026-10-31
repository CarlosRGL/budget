<?php

namespace App\Http\Controllers\Budget;

use App\Actions\Budget\ReorderBudget;
use App\Http\Controllers\Controller;
use App\Http\Requests\Budget\ReorderBudgetRequest;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;

class BudgetOrderController extends Controller
{
    /**
     * Save the order of groups and categories after a drag and drop.
     */
    public function __invoke(ReorderBudgetRequest $request, Team $current_team, ReorderBudget $reorder): RedirectResponse
    {
        $reorder->handle($current_team, $request->validated('groups'));

        return back();
    }
}
