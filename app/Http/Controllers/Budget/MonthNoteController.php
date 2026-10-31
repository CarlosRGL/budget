<?php

namespace App\Http\Controllers\Budget;

use App\Http\Controllers\Controller;
use App\Http\Requests\Budget\MonthNoteRequest;
use App\Models\MonthNote;
use App\Models\Team;
use App\Support\BudgetMonth;
use Illuminate\Http\RedirectResponse;

class MonthNoteController extends Controller
{
    /**
     * Save the note of a month; an empty note deletes it.
     */
    public function __invoke(MonthNoteRequest $request, Team $current_team, string $month): RedirectResponse
    {
        $month = BudgetMonth::fromUrl($month)->toDateString();
        $note = trim((string) $request->validated('note'));

        if ($note === '') {
            MonthNote::where('month', $month)->delete();
        } else {
            MonthNote::updateOrCreate(['month' => $month], ['team_id' => $current_team->id, 'note' => $note]);
        }

        return back();
    }
}
