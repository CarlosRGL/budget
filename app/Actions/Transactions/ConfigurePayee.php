<?php

namespace App\Actions\Transactions;

use App\Models\Payee;
use App\Models\PayeeRenameRule;
use App\Models\Transaction;

/**
 * The rename rules of a payee: the bank labels it takes over, and running them
 * on the transactions already imported.
 */
class ConfigurePayee
{
    /**
     * Give the payee exactly these rename rules. A text already used by another
     * payee's rule moves to this one.
     *
     * @param  list<string>  $texts
     */
    public function syncRules(Payee $payee, array $texts): void
    {
        $texts = array_values(array_unique(array_map('trim', $texts)));

        PayeeRenameRule::query()->where('payee_id', $payee->id)->whereNotIn('contains', $texts)->delete();

        foreach ($texts as $text) {
            PayeeRenameRule::updateOrCreate(
                ['team_id' => $payee->team_id, 'contains' => $text],
                ['payee_id' => $payee->id],
            );
        }
    }

    /**
     * Run the payee's rules on the transactions already imported, then give
     * its default category to its transactions still without one. Payees the
     * rules emptied are deleted.
     *
     * @return int How many transactions changed.
     */
    public function applyToExisting(Payee $payee): int
    {
        $previous = Transaction::query()
            ->whereNotNull('raw_label')
            ->where('payee_id', '!=', $payee->id)
            ->pluck('payee_id', 'id');

        $changed = 0;

        foreach ($payee->renameRules()->get() as $rule) {
            $changed += $rule->applyToExisting();
        }

        $categoryId = $payee->refresh()->defaultCategoryId();

        if ($categoryId !== null) {
            $changed += Transaction::query()
                ->where('payee_id', $payee->id)
                ->whereNull('category_id')
                ->whereNull('transfer_pair_id')
                ->where('inflow_to_rta', false)
                ->whereDoesntHave('splits')
                ->whereHas('account', fn ($query) => $query->where('on_budget', true))
                ->update(['category_id' => $categoryId]);
        }

        Payee::query()
            ->whereKey($previous->filter()->unique()->values())
            ->whereKeyNot($payee->id)
            ->whereNull('transfer_account_id')
            ->doesntHave('transactions')
            ->doesntHave('renameRules')
            ->get()
            ->each(fn (Payee $emptied) => $emptied->delete());

        return $changed;
    }
}
