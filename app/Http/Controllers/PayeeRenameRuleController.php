<?php

namespace App\Http\Controllers;

use App\Models\Payee;
use App\Models\PayeeRenameRule;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Rename rules: "if the bank label contains … the payee is …".
 */
class PayeeRenameRuleController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('rename-rules/index', [
            'rules' => PayeeRenameRule::query()
                ->with('payee')
                ->orderBy('contains')
                ->get()
                ->map(fn (PayeeRenameRule $rule) => [
                    'id' => $rule->id,
                    'contains' => $rule->contains,
                    'payee' => ['id' => $rule->payee->id, 'name' => $rule->payee->name],
                ])
                ->all(),
        ]);
    }

    public function update(Request $request, Team $current_team, PayeeRenameRule $payeeRenameRule): RedirectResponse
    {
        $validated = $request->validate([
            'contains' => ['required', 'string', 'max:255'],
            'payee_name' => ['required', 'string', 'max:255'],
        ], attributes: ['contains' => __('label text'), 'payee_name' => __('payee')]);

        $payee = Payee::firstOrCreate(['team_id' => $current_team->id, 'name' => trim($validated['payee_name'])]);

        $payeeRenameRule->update(['contains' => trim($validated['contains']), 'payee_id' => $payee->id]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Rule updated.')]);

        return back();
    }

    public function destroy(Team $current_team, PayeeRenameRule $payeeRenameRule): RedirectResponse
    {
        $payeeRenameRule->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Rule deleted.')]);

        return back();
    }

    /**
     * Give the rule's payee to the imported transactions it matches.
     */
    public function apply(Team $current_team, PayeeRenameRule $payeeRenameRule): RedirectResponse
    {
        $count = $payeeRenameRule->applyToExisting();

        Inertia::flash('toast', ['type' => 'success', 'message' => match ($count) {
            0 => __('No transaction to rename.'),
            default => trans_choice(':count transaction renamed.|:count transactions renamed.', $count),
        }]);

        return back();
    }
}
