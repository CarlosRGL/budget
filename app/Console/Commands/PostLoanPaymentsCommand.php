<?php

namespace App\Console\Commands;

use App\Actions\Loans\PostLoanPayments;
use App\Models\Loan;
use App\Models\Scopes\CurrentTeamScope;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class PostLoanPaymentsCommand extends Command
{
    protected $signature = 'loans:post-payments';

    protected $description = 'Record on each linked loan account the capital repaid at the due dates just passed';

    public function handle(PostLoanPayments $post): int
    {
        $today = CarbonImmutable::today();
        $posted = 0;

        Loan::withoutGlobalScope(CurrentTeamScope::class)
            ->whereNotNull('account_id')
            ->with(['parts', 'account' => fn ($query) => $query->withoutGlobalScope(CurrentTeamScope::class)])
            ->get()
            ->each(function (Loan $loan) use ($post, $today, &$posted) {
                $posted += $post->handle($loan, $today);
            });

        $this->components->info("{$posted} due date(s) posted.");

        return self::SUCCESS;
    }
}
