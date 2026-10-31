<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Concerns\BelongsToTeam;
use App\Enums\BankStatus;
use App\Enums\ReviewReason;
use Carbon\CarbonInterface;
use Database\Factories\TransactionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * @property int $id
 * @property int $team_id
 * @property int $account_id
 * @property Carbon $date
 * @property int|null $payee_id
 * @property int|null $category_id
 * @property string|null $memo
 * @property int $amount Signed amount in cents: negative for an outflow.
 * @property bool $cleared
 * @property Carbon|null $approved_at
 * @property int|null $approved_by
 * @property string|null $flag
 * @property int|null $transfer_pair_id
 * @property bool $is_starting_balance
 * @property bool $inflow_to_rta
 * @property int|null $created_by
 * @property int|null $bank_account_id The bank account it was imported from, while that connection exists.
 * @property string|null $external_id The bank's id (entry_reference or transaction_id).
 * @property string|null $fingerprint Fallback identity: date, amount and normalized raw label.
 * @property BankStatus|null $bank_status
 * @property string|null $raw_label The label as the bank gives it.
 * @property string|null $card_suffix Last four digits of the card that paid.
 * @property ReviewReason|null $review_reason
 */
#[Fillable([
    'team_id', 'account_id', 'date', 'payee_id', 'category_id', 'memo', 'amount', 'cleared',
    'approved_at', 'approved_by', 'flag', 'transfer_pair_id', 'is_starting_balance', 'inflow_to_rta', 'created_by',
    'bank_account_id', 'external_id', 'fingerprint', 'bank_status', 'raw_label', 'card_suffix', 'review_reason',
])]
class Transaction extends Model
{
    /** @use HasFactory<TransactionFactory> */
    use BelongsToTeam, HasFactory;

    /**
     * Record a transfer as two linked transactions, one on each account.
     *
     * A transfer from a budget account to an off-budget account (e.g. paying the mortgage)
     * is spending for the budget, so its outflow side takes the given category.
     *
     * @return array{0: Transaction, 1: Transaction} The outflow and the inflow.
     */
    public static function createTransfer(
        Account $from,
        Account $to,
        int $amount,
        CarbonInterface $date,
        ?User $by = null,
        ?Category $category = null,
        ?string $memo = null,
    ): array {
        return DB::transaction(function () use ($from, $to, $amount, $date, $by, $category, $memo) {
            $leavesBudget = $from->on_budget && ! $to->on_budget;

            $outflow = static::create([
                'team_id' => $from->team_id,
                'account_id' => $from->id,
                'date' => $date,
                'payee_id' => Payee::forTransferTo($to)->id,
                'category_id' => $leavesBudget ? $category?->id : null,
                'memo' => $memo,
                'amount' => -abs($amount),
                'created_by' => $by?->id,
            ]);

            $inflow = static::create([
                'team_id' => $to->team_id,
                'account_id' => $to->id,
                'date' => $date,
                'payee_id' => Payee::forTransferTo($from)->id,
                'memo' => $memo,
                'amount' => abs($amount),
                'transfer_pair_id' => $outflow->id,
                'created_by' => $by?->id,
            ]);

            $outflow->update(['transfer_pair_id' => $inflow->id]);

            return [$outflow, $inflow];
        });
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * @return BelongsTo<Payee, $this>
     */
    public function payee(): BelongsTo
    {
        return $this->belongsTo(Payee::class);
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return HasMany<TransactionSplit, $this>
     */
    public function splits(): HasMany
    {
        return $this->hasMany(TransactionSplit::class);
    }

    /**
     * @return BelongsTo<Transaction, $this>
     */
    public function transferPair(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'transfer_pair_id');
    }

    /**
     * @return BelongsTo<BankAccount, $this>
     */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Money that moved on a budget account without reaching any envelope: no category,
     * not Ready to Assign, not split, and not a transfer between two budget accounts.
     *
     * @param  Builder<Transaction>  $query
     */
    #[Scope]
    protected function needingCategory(Builder $query): void
    {
        $query->whereNull('category_id')
            ->where('inflow_to_rta', false)
            ->whereDoesntHave('splits')
            ->whereHas('account', fn (Builder $account) => $account->where('on_budget', true))
            ->where(fn (Builder $query) => $query
                ->whereNull('transfer_pair_id')
                ->orWhereHas('transferPair.account', fn (Builder $account) => $account->where('on_budget', false)));
    }

    /**
     * The inbox: transactions nobody confirmed yet, or that still need a category.
     *
     * @param  Builder<Transaction>  $query
     */
    #[Scope]
    protected function inInbox(Builder $query): void
    {
        $query->where(fn (Builder $query) => $query
            ->whereNull('approved_at')
            ->orWhere(fn (Builder $query) => $query->needingCategory()));
    }

    /**
     * Determine if the transaction is spread over several categories.
     */
    public function isSplit(): bool
    {
        return $this->splits()->exists();
    }

    /**
     * Determine if the split lines add up exactly to the transaction amount.
     */
    public function splitsAreBalanced(): bool
    {
        return (int) $this->splits()->sum('amount') === $this->amount;
    }

    /**
     * Determine if the transaction moves money between two accounts.
     */
    public function isTransfer(): bool
    {
        return $this->transfer_pair_id !== null;
    }

    /**
     * Determine if the transaction is linked to the bank (imported, or typed then
     * reconciled). The fingerprint stays when the bank connection is removed.
     */
    public function isImported(): bool
    {
        return $this->fingerprint !== null;
    }

    /**
     * Determine if a member has confirmed this transaction.
     */
    public function isApproved(): bool
    {
        return $this->approved_at !== null;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => DateOnly::class,
            'amount' => 'integer',
            'cleared' => 'boolean',
            'approved_at' => 'datetime',
            'is_starting_balance' => 'boolean',
            'inflow_to_rta' => 'boolean',
            'bank_status' => BankStatus::class,
            'review_reason' => ReviewReason::class,
        ];
    }
}
