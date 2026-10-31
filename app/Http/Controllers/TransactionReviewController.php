<?php

namespace App\Http\Controllers;

use App\Banking\BankSynchronizer;
use App\Enums\ReviewReason;
use App\Models\Team;
use App\Models\Transaction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/**
 * Answers to the hints the bank sync leaves on imported transactions.
 */
class TransactionReviewController extends Controller
{
    /**
     * "Rapprocher": the imported transaction is the one typed by hand. The typed
     * transaction takes the bank's identity and the imported copy goes away.
     */
    public function merge(Team $current_team, Transaction $transaction): RedirectResponse
    {
        abort_unless($transaction->review_reason === ReviewReason::PossibleDuplicate, 422);

        $typed = $this->typedTwin($transaction);

        if (! $typed) {
            $transaction->update(['review_reason' => null]);
            Inertia::flash('toast', ['type' => 'error', 'message' => __('No entered transaction matches anymore: the imported transaction is kept.')]);

            return back();
        }

        DB::transaction(function () use ($transaction, $typed) {
            $bankFields = $transaction->only(['bank_account_id', 'external_id', 'fingerprint', 'bank_status', 'raw_label', 'card_suffix']);
            $transaction->delete();

            $typed->update([...$bankFields, 'cleared' => $typed->cleared || $transaction->cleared]);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Transactions matched.')]);

        return back();
    }

    /**
     * Keep the transaction as it is and drop the hint.
     */
    public function dismiss(Team $current_team, Transaction $transaction): RedirectResponse
    {
        $transaction->update(['review_reason' => null]);

        return back();
    }

    /**
     * The transaction typed by hand on the same account, for the same amount, dated closest.
     */
    private function typedTwin(Transaction $transaction): ?Transaction
    {
        return Transaction::query()
            ->where('account_id', $transaction->account_id)
            ->whereKeyNot($transaction->id)
            ->whereNull('fingerprint')
            ->where('is_starting_balance', false)
            ->where('amount', $transaction->amount)
            ->whereBetween('date', [
                $transaction->date->copy()->subDays(BankSynchronizer::MATCH_DAYS)->toDateString(),
                $transaction->date->copy()->addDays(BankSynchronizer::MATCH_DAYS)->toDateString(),
            ])
            ->get()
            ->sortBy(fn (Transaction $candidate) => abs($candidate->date->diffInDays($transaction->date)))
            ->first();
    }
}
