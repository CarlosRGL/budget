<?php

namespace App\Actions\Accounts;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Payee;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Locales;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class CreateAccount
{
    public const STARTING_BALANCE_PAYEE = 'Starting balance';

    /**
     * Open an account in the member's current household with its current balance.
     *
     * The balance is recorded as an approved "starting balance" transaction which,
     * for a budget account, goes straight to "Ready to Assign".
     */
    public function handle(
        User $by,
        string $name,
        AccountType $type,
        bool $onBudget,
        int $balance,
        CarbonInterface $startingDate,
    ): Account {
        return DB::transaction(function () use ($by, $name, $type, $onBudget, $balance, $startingDate) {
            $teamId = $by->current_team_id;

            $account = Account::create([
                'team_id' => $teamId,
                'name' => $name,
                'type' => $type,
                'on_budget' => $onBudget,
                'starting_date' => $startingDate,
                'sort' => (int) Account::withoutGlobalScopes()->where('team_id', $teamId)->max('sort') + 1,
            ]);

            $payee = Payee::withoutGlobalScopes()->firstOrCreate([
                'team_id' => $teamId,
                'name' => Locales::data(self::STARTING_BALANCE_PAYEE),
            ]);

            Transaction::create([
                'team_id' => $teamId,
                'account_id' => $account->id,
                'date' => $startingDate,
                'payee_id' => $payee->id,
                'amount' => $balance,
                'cleared' => true,
                'approved_at' => now(),
                'approved_by' => $by->id,
                'is_starting_balance' => true,
                'inflow_to_rta' => $onBudget,
                'created_by' => $by->id,
            ]);

            return $account;
        });
    }
}
