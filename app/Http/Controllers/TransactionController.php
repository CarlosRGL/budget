<?php

namespace App\Http\Controllers;

use App\Actions\Transactions\ConfigurePayee;
use App\Actions\Transactions\SaveTransaction;
use App\Enums\PayeeCategoryMode;
use App\Http\Requests\Transactions\ApproveTransactionsRequest;
use App\Http\Requests\Transactions\SaveTransactionRequest;
use App\Models\Payee;
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
    public function store(SaveTransactionRequest $request, SaveTransaction $save, ConfigurePayee $configure): RedirectResponse
    {
        $transaction = $save->handle($request->user(), $request->transaction());

        $this->configurePayee($request, $transaction, $configure);

        return back();
    }

    public function update(SaveTransactionRequest $request, Team $current_team, Transaction $transaction, SaveTransaction $save, ConfigurePayee $configure): RedirectResponse
    {
        $transaction = $save->handle($request->user(), $request->transaction(), $transaction);

        $this->configurePayee($request, $transaction, $configure);

        return back();
    }

    public function destroy(Team $current_team, Transaction $transaction): RedirectResponse
    {
        DB::transaction(function () use ($transaction) {
            $pair = $transaction->transferPair;
            $transaction->update(['transfer_pair_id' => null]);
            $pair?->delete();
            $transaction->delete();
            Payee::deleteUnused([$transaction->payee_id]);
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
     * The member may set up the payee while saving: how it categorizes and the
     * bank labels it takes over, optionally run on the transactions already imported.
     */
    private function configurePayee(SaveTransactionRequest $request, Transaction $transaction, ConfigurePayee $configure): void
    {
        $settings = $request->payeeSettings();
        $payee = $transaction->payee;

        if ($settings === null || $payee === null || $payee->isTransfer()) {
            return;
        }

        $mode = $settings['category_mode'];

        $changed = DB::transaction(function () use ($payee, $mode, $settings, $transaction, $configure) {
            $payee->update([
                'category_mode' => $mode,
                'last_category_id' => match ($mode) {
                    PayeeCategoryMode::Fixed => $settings['category_id'],
                    default => $transaction->category_id ?? $payee->last_category_id,
                },
            ]);

            $configure->syncRules($payee, $settings['rules']);

            return $settings['apply_to_existing'] ? $configure->applyToExisting($payee) : 0;
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => $changed > 0
            ? trans_choice('Payee updated, :count transaction changed.|Payee updated, :count transactions changed.', $changed)
            : __('Payee updated.')]);
    }
}
