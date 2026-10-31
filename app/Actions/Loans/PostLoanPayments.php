<?php

namespace App\Actions\Loans;

use App\Loans\LoanReport;
use App\Models\Loan;
use App\Models\Payee;
use App\Models\Transaction;
use App\Support\Locales;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class PostLoanPayments
{
    public const PAYEE = 'Loan payment';

    /**
     * Record on the linked loan account the capital repaid at each due date
     * passed since the last run, so the account follows the remaining capital.
     * The interest stays out: it is spending, already counted in the budget
     * through the bank debit.
     *
     * The first run on a newly linked account only starts the count. Returns
     * the number of due dates posted.
     */
    public function handle(Loan $loan, CarbonImmutable $today): int
    {
        $account = $loan->account;

        if ($account === null || $account->isClosed()) {
            return 0;
        }

        $since = $loan->posted_through;

        if ($since === null) {
            $loan->update(['posted_through' => $today]);

            return 0;
        }

        $dues = array_filter(
            LoanReport::dueDates($loan),
            fn (array $due) => $due['date']->gt($since) && $due['date']->lte($today) && $due['principal'] > 0,
        );

        DB::transaction(function () use ($loan, $account, $dues, $today) {
            if ($dues !== []) {
                $payee = Payee::withoutGlobalScopes()->firstOrCreate([
                    'team_id' => $loan->team_id,
                    'name' => Locales::data(self::PAYEE),
                ]);

                foreach ($dues as $due) {
                    Transaction::create([
                        'team_id' => $loan->team_id,
                        'account_id' => $account->id,
                        'date' => $due['date'],
                        'payee_id' => $payee->id,
                        'memo' => Locales::data('Principal :principal · interest :interest', [
                            'principal' => Money::format($due['principal'], Locales::forData()),
                            'interest' => Money::format($due['interest'], Locales::forData()),
                        ]),
                        'amount' => $due['principal'],
                        'inflow_to_rta' => false,
                        'cleared' => true,
                        'approved_at' => now(),
                    ]);
                }
            }

            $loan->update(['posted_through' => $today]);
        });

        return count($dues);
    }
}
