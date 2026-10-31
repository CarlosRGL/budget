<?php

namespace App\Http\Controllers\Budget;

use App\Actions\Budget\MoveMoney;
use App\Http\Controllers\Controller;
use App\Http\Requests\Budget\MoveMoneyRequest;
use App\Models\Category;
use App\Models\Team;
use App\Support\BudgetMonth;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class MoneyMoveController extends Controller
{
    /**
     * Move money between two categories, or to and from Ready to Assign.
     */
    public function __invoke(MoveMoneyRequest $request, Team $current_team, string $month, MoveMoney $moveMoney): RedirectResponse
    {
        $amount = $request->integer('amount');

        $moveMoney->handle(
            $request->user(),
            BudgetMonth::fromUrl($month),
            $this->category($request, 'from_category_id'),
            $this->category($request, 'to_category_id'),
            $amount,
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':amount moved.', ['amount' => Money::format($amount)])]);

        return back();
    }

    /**
     * The household category behind a validated id, or null for Ready to Assign.
     */
    private function category(MoveMoneyRequest $request, string $key): ?Category
    {
        return $request->validated($key) === null ? null : Category::findOrFail($request->integer($key));
    }
}
