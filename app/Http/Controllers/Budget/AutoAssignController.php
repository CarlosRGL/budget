<?php

namespace App\Http\Controllers\Budget;

use App\Actions\Budget\AutoAssign;
use App\Enums\AutoAssignStrategy;
use App\Http\Controllers\Controller;
use App\Http\Requests\Budget\AutoAssignRequest;
use App\Models\Team;
use App\Support\BudgetMonth;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class AutoAssignController extends Controller
{
    /**
     * The "Affecter" menu: fund targets, copy last month, use the average, or reset.
     */
    public function __invoke(AutoAssignRequest $request, Team $current_team, string $month, AutoAssign $autoAssign): RedirectResponse
    {
        ['amount' => $amount, 'categories' => $count] = $autoAssign->handle(
            $request->user(),
            $current_team,
            BudgetMonth::fromUrl($month),
            AutoAssignStrategy::from($request->validated('strategy')),
            $request->categoryIds(),
        );

        Inertia::flash('toast', $count === 0
            ? ['type' => 'info', 'message' => __('Nothing to assign.')]
            : ['type' => 'success', 'message' => $this->summary($amount, $count)]);

        return back();
    }

    private function summary(int $amount, int $count): string
    {
        return $amount >= 0
            ? trans_choice(':amount assigned to :count category.|:amount assigned to :count categories.', $count, ['amount' => Money::format($amount)])
            : trans_choice(':amount returned to Ready to Assign from :count category.|:amount returned to Ready to Assign from :count categories.', $count, ['amount' => Money::format(-$amount)]);
    }
}
