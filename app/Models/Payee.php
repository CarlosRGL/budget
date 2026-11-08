<?php

namespace App\Models;

use App\Concerns\BelongsToTeam;
use App\Enums\PayeeCategoryMode;
use App\Support\Locales;
use Database\Factories\PayeeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $team_id
 * @property string $name
 * @property int|null $last_category_id
 * @property PayeeCategoryMode $category_mode
 * @property int|null $transfer_account_id
 */
#[Fillable(['team_id', 'name', 'last_category_id', 'category_mode', 'transfer_account_id'])]
class Payee extends Model
{
    /** @use HasFactory<PayeeFactory> */
    use BelongsToTeam, HasFactory;

    /**
     * The special payee used for transfers to the given account.
     */
    public static function forTransferTo(Account $account): self
    {
        return static::withoutGlobalScopes()->firstOrCreate(
            ['team_id' => $account->team_id, 'transfer_account_id' => $account->id],
            ['name' => Locales::data('Transfer: :account', ['account' => $account->name])],
        );
    }

    /**
     * Delete the given payees that nothing uses any more: no transaction, no
     * split line, no rename rule. Transfer payees are kept.
     *
     * @param  iterable<int|null>  $ids
     * @return int How many payees were deleted.
     */
    public static function deleteUnused(iterable $ids): int
    {
        $ids = array_values(array_unique(array_filter([...$ids])));

        if ($ids === []) {
            return 0;
        }

        return static::withoutGlobalScopes()
            ->whereKey($ids)
            ->whereNull('transfer_account_id')
            ->whereDoesntHave('transactions', fn ($query) => $query->withoutGlobalScopes())
            ->whereDoesntHave('splits')
            ->whereDoesntHave('renameRules', fn ($query) => $query->withoutGlobalScopes())
            ->delete();
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function lastCategory(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'last_category_id');
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function transferAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'transfer_account_id');
    }

    /**
     * @return HasMany<Transaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * @return HasMany<TransactionSplit, $this>
     */
    public function splits(): HasMany
    {
        return $this->hasMany(TransactionSplit::class);
    }

    /**
     * @return HasMany<PayeeRenameRule, $this>
     */
    public function renameRules(): HasMany
    {
        return $this->hasMany(PayeeRenameRule::class);
    }

    /**
     * The category new transactions of this payee get, if any.
     */
    public function defaultCategoryId(): ?int
    {
        return $this->category_mode === PayeeCategoryMode::None ? null : $this->last_category_id;
    }

    /**
     * Determine if this payee stands for a transfer between accounts.
     */
    public function isTransfer(): bool
    {
        return $this->transfer_account_id !== null;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category_mode' => PayeeCategoryMode::class,
        ];
    }
}
