<?php

namespace App\Actions\Transactions;

use App\Banking\BankLabel;
use App\Models\Payee;
use App\Models\PayeeRenameRule;
use App\Models\Transaction;
use App\Models\TransactionSplit;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The rename rules of a payee: the bank labels it takes over, and running them
 * on what the household already has.
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
     * Fold in the other payees whose name a rule matches, run the rules on the
     * transactions already imported, then give the default category to the
     * payee's transactions still without one. Payees left unused are deleted.
     *
     * @return int How many transactions changed.
     */
    public function applyToExisting(Payee $payee): int
    {
        $changed = 0;

        foreach ($this->payeesCoveredBy($payee) as $covered) {
            $changed += $this->merge($covered, $payee);
        }

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

        return $changed;
    }

    /**
     * The other payees of the household whose name contains one of the
     * payee's rule texts: they stand for the same merchant.
     *
     * @return Collection<int, Payee>
     */
    public function payeesCoveredBy(Payee $payee): Collection
    {
        $needles = $payee->renameRules()->pluck('contains')
            ->map(fn (string $text) => BankLabel::normalize($text))
            ->filter()
            ->values();

        if ($needles->isEmpty()) {
            return new Collection;
        }

        return Payee::query()
            ->whereKeyNot($payee->id)
            ->whereNull('transfer_account_id')
            ->get()
            ->filter(fn (Payee $other) => $needles->contains(
                fn (string $needle) => str_contains(BankLabel::normalize($other->name), $needle),
            ))
            ->values();
    }

    /**
     * Move a payee's transactions, split lines and rules to another one, then
     * delete it.
     *
     * @return int How many transactions changed payee.
     */
    public function merge(Payee $source, Payee $into): int
    {
        return DB::transaction(function () use ($source, $into) {
            $moved = Transaction::query()->where('payee_id', $source->id)->update(['payee_id' => $into->id]);
            TransactionSplit::query()->where('payee_id', $source->id)->update(['payee_id' => $into->id]);
            PayeeRenameRule::query()->where('payee_id', $source->id)->update(['payee_id' => $into->id]);

            if ($into->last_category_id === null && $source->last_category_id !== null) {
                $into->update(['last_category_id' => $source->last_category_id]);
            }

            $source->delete();

            return $moved;
        });
    }
}
