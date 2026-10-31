<?php

namespace App\Actions\Accounts;

use App\Models\Account;
use App\Models\Payee;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Locales;

class AdjustAccountBalance
{
    public const ADJUSTMENT_PAYEE = 'Balance adjustment';

    /**
     * Bring an account to the given balance with an adjustment transaction.
     *
     * On a budget account the difference goes to (or comes from) Ready to
     * Assign, as in a reconciliation: money nobody had put in an envelope.
     *
     * Returns null when the balance is already right.
     */
    public function handle(User $by, Account $account, int $newBalance): ?Transaction
    {
        $difference = $newBalance - (int) $account->transactions()->sum('amount');

        if ($difference === 0) {
            return null;
        }

        $payee = Payee::withoutGlobalScopes()->firstOrCreate([
            'team_id' => $account->team_id,
            'name' => Locales::data(self::ADJUSTMENT_PAYEE),
        ]);

        return Transaction::create([
            'team_id' => $account->team_id,
            'account_id' => $account->id,
            'date' => today(),
            'payee_id' => $payee->id,
            'amount' => $difference,
            'inflow_to_rta' => $account->on_budget,
            'cleared' => true,
            'approved_at' => now(),
            'approved_by' => $by->id,
            'created_by' => $by->id,
        ]);
    }
}
