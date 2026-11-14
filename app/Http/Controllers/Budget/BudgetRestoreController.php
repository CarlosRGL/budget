<?php

namespace App\Http\Controllers\Budget;

use App\Actions\Budget\RestoreBudget;
use App\Enums\MoneyMoveKind;
use App\Http\Controllers\Controller;
use App\Http\Requests\Budget\RestoreBudgetRequest;
use App\Models\Team;
use App\Support\BudgetMonth;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class BudgetRestoreController extends Controller
{
    /**
     * Undo or redo a change of the budget screen (Cmd/Ctrl+Z, Shift+Cmd/Ctrl+Z).
     */
    public function __invoke(RestoreBudgetRequest $request, Team $current_team, string $month, RestoreBudget $restore): RedirectResponse
    {
        $kind = $request->validated('kind') === 'redo' ? MoneyMoveKind::Redo : MoneyMoveKind::Undo;

        $restore->handle($request->user(), BudgetMonth::fromUrl($month), $kind, $request->assignments(), $request->snoozes());

        Inertia::flash('toast', ['type' => 'success', 'message' => $kind === MoneyMoveKind::Redo ? __('Change redone.') : __('Change undone.')]);

        return back();
    }
}
