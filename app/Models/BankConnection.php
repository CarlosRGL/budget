<?php

namespace App\Models;

use App\Concerns\BelongsToTeam;
use App\Enums\BankConnectionStatus;
use Database\Factories\BankConnectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A member's consent at one bank (an EnableBanking session).
 *
 * @property int $id
 * @property int $team_id
 * @property int $user_id
 * @property string $aspsp_name
 * @property string $aspsp_country
 * @property string|null $session_id
 * @property string|null $auth_state
 * @property Carbon|null $valid_until
 * @property BankConnectionStatus $status
 * @property Carbon|null $last_synced_at
 * @property string|null $last_error
 */
#[Fillable([
    'team_id', 'user_id', 'aspsp_name', 'aspsp_country', 'session_id', 'auth_state',
    'valid_until', 'status', 'last_synced_at', 'last_error',
])]
class BankConnection extends Model
{
    /** @use HasFactory<BankConnectionFactory> */
    use BelongsToTeam, HasFactory;

    /**
     * How many days before the end of the consent members are warned.
     */
    public const WARNING_DAYS = 7;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<BankAccount, $this>
     */
    public function bankAccounts(): HasMany
    {
        return $this->hasMany(BankAccount::class);
    }

    /**
     * Connections whose consent still allows reading the bank.
     *
     * @param  Builder<BankConnection>  $query
     */
    #[Scope]
    protected function syncable(Builder $query): void
    {
        $query->where('status', BankConnectionStatus::Active)
            ->whereNotNull('session_id')
            ->where(fn (Builder $query) => $query->whereNull('valid_until')->orWhere('valid_until', '>', now()));
    }

    /**
     * Connections that ended or end within WARNING_DAYS.
     *
     * @param  Builder<BankConnection>  $query
     */
    #[Scope]
    protected function needingRenewal(Builder $query): void
    {
        $query->whereNotNull('session_id')->where(fn (Builder $query) => $query
            ->where('status', BankConnectionStatus::Expired)
            ->orWhere('valid_until', '<=', now()->addDays(self::WARNING_DAYS)));
    }

    /**
     * Determine if the consent ended, by date or as told by the bank.
     */
    public function isExpired(): bool
    {
        return $this->status === BankConnectionStatus::Expired
            || ($this->valid_until !== null && $this->valid_until->isPast());
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => BankConnectionStatus::class,
            'valid_until' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }
}
