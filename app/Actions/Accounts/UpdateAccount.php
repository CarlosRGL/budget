<?php

namespace App\Actions\Accounts;

use App\Models\Account;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateAccount
{
    /**
     * Rename an account, change its note and, while it only holds its starting
     * balance, whether it is budgeted.
     */
    public function handle(Account $account, string $name, ?string $note, bool $onBudget): Account
    {
        if ($onBudget !== $account->on_budget && ! $this->canChangeBudgetStatus($account)) {
            throw ValidationException::withMessages([
                'on_budget' => __('This account already has transactions: it can no longer change its budget status.'),
            ]);
        }

        return DB::transaction(function () use ($account, $name, $note, $onBudget) {
            $account->update([
                'name' => $name,
                'note' => $note,
                'on_budget' => $onBudget,
            ]);

            $account->transactions()
                ->where('is_starting_balance', true)
                ->update(['inflow_to_rta' => $onBudget]);

            return $account;
        });
    }

    /**
     * Determine if the account holds no transaction other than its starting balance.
     */
    public function canChangeBudgetStatus(Account $account): bool
    {
        return ! $account->transactions()->where('is_starting_balance', false)->exists();
    }
}
