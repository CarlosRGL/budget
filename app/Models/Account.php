<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Concerns\BelongsToTeam;
use App\Enums\AccountType;
use Database\Factories\AccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $team_id
 * @property string $name
 * @property AccountType $type
 * @property bool $on_budget
 * @property Carbon $starting_date
 * @property Carbon|null $closed_at
 * @property int $sort
 * @property string|null $note
 */
#[Fillable(['team_id', 'name', 'type', 'on_budget', 'starting_date', 'closed_at', 'sort', 'note'])]
class Account extends Model
{
    /** @use HasFactory<AccountFactory> */
    use BelongsToTeam, HasFactory;

    protected static function booted(): void
    {
        static::creating(function (Account $account) {
            $account->on_budget ??= $account->type->isOnBudgetByDefault();
        });
    }

    /**
     * @return HasMany<Transaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * Determine if the account has been closed.
     */
    public function isClosed(): bool
    {
        return $this->closed_at !== null;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => AccountType::class,
            'on_budget' => 'boolean',
            'starting_date' => DateOnly::class,
            'closed_at' => 'datetime',
        ];
    }
}
