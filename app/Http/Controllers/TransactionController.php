<?php

namespace App\Http\Controllers;

use App\Actions\Transactions\SaveTransaction;
use App\Http\Requests\Transactions\ApproveTransactionsRequest;
use App\Http\Requests\Transactions\SaveTransactionRequest;
use App\Models\PayeeRenameRule;
use App\Models\Team;
use App\Models\Transaction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/**
 * Transactions typed or confirmed by members. Route model binding only finds
 * transactions of the current household (BelongsToTeam global scope).
 */
class TransactionController extends Controller
{
    public function store(SaveTransactionRequest $request, SaveTransaction $save): RedirectResponse
    {
        $save->handle($request->user(), $request->transaction());

        return back();
    }

    public function update(SaveTransactionRequest $request, Team $current_team, Transaction $transaction, SaveTransaction $save): RedirectResponse
    {
        $transaction = $save->handle($request->user(), $request->transaction(), $transaction);

        $this->rememberRenameRule($request, $transaction);

        return back();
    }

    public function destroy(Team $current_team, Transaction $transaction): RedirectResponse
    {
        DB::transaction(function () use ($transaction) {
            $pair = $transaction->transferPair;
            $transaction->update(['transfer_pair_id' => null]);
            $pair?->delete();
            $transaction->delete();
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Transaction deleted.')]);

        return back();
    }

    /**
     * Confirm several transactions at once.
     */
    public function approve(ApproveTransactionsRequest $request): RedirectResponse
    {
        $count = Transaction::whereKey($request->validated('ids'))
            ->whereNull('approved_at')
            ->update(['approved_at' => now(), 'approved_by' => $request->user()->id, 'review_reason' => null]);

        Inertia::flash('toast', ['type' => 'success', 'message' => $count > 1 ? __(':count transactions approved.', ['count' => $count]) : __('Transaction approved.')]);

        return back();
    }

    public function cleared(Request $request, Team $current_team, Transaction $transaction): RedirectResponse
    {
        $transaction->update($request->validate(['cleared' => ['required', 'boolean']]));

        return back();
    }

    /**
     * Renaming the payee of an imported transaction may create a rule for the next imports.
     */
    private function rememberRenameRule(SaveTransactionRequest $request, Transaction $transaction): void
    {
        $contains = trim((string) $request->validated('rename_rule_contains'));

        if ($contains === '' || ! $transaction->isImported() || $transaction->payee_id === null) {
            return;
        }

        PayeeRenameRule::updateOrCreate(
            ['team_id' => $transaction->team_id, 'contains' => $contains],
            ['payee_id' => $transaction->payee_id],
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Rule created: labels containing “:text” will be renamed.', ['text' => $contains])]);
    }
}
