<?php

namespace App\Models;

use App\Banking\BankLabel;
use App\Concerns\BelongsToTeam;
use App\Models\Scopes\CurrentTeamScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Imported bank labels containing `contains` are given this payee.
 *
 * @property int $id
 * @property int $team_id
 * @property string $contains
 * @property int $payee_id
 */
#[Fillable(['team_id', 'contains', 'payee_id'])]
class PayeeRenameRule extends Model
{
    use BelongsToTeam;

    /**
     * @return BelongsTo<Payee, $this>
     */
    public function payee(): BelongsTo
    {
        return $this->belongsTo(Payee::class);
    }

    /**
     * Determine if a raw bank label contains the rule's text, ignoring case and spacing.
     */
    public function matches(string $rawLabel): bool
    {
        $needle = BankLabel::normalize($this->contains);

        return $needle !== '' && str_contains(BankLabel::normalize($rawLabel), $needle);
    }

    /**
     * Give the rule's payee to the imported transactions of the household it matches.
     *
     * @return int How many transactions changed payee.
     */
    public function applyToExisting(): int
    {
        $ids = Transaction::withoutGlobalScope(CurrentTeamScope::class)
            ->where('team_id', $this->team_id)
            ->whereNotNull('raw_label')
            ->whereNull('transfer_pair_id')
            ->where(fn (Builder $query) => $query->whereNull('payee_id')->orWhere('payee_id', '!=', $this->payee_id))
            ->get(['id', 'raw_label'])
            ->filter(fn (Transaction $transaction) => $this->matches((string) $transaction->raw_label))
            ->modelKeys();

        return $ids === [] ? 0 : Transaction::withoutGlobalScope(CurrentTeamScope::class)
            ->whereKey($ids)
            ->update(['payee_id' => $this->payee_id]);
    }
}
