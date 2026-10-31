<?php

namespace App\Models;

use App\Concerns\BelongsToTeam;
use Database\Factories\BankAccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An account seen at the bank, imported into the household account it is mapped to.
 *
 * `account_uid` changes with every new session; `identification_hash` does not,
 * so it finds the same bank account again when the consent is renewed.
 *
 * @property int $id
 * @property int $team_id
 * @property int $bank_connection_id
 * @property string|null $account_uid
 * @property string|null $identification_hash
 * @property string|null $iban_masked
 * @property string $name
 * @property string|null $currency
 * @property int|null $account_id
 * @property int|null $last_balance Balance given by the bank, in cents.
 * @property Carbon|null $last_balance_at
 */
#[Fillable([
    'team_id', 'bank_connection_id', 'account_uid', 'identification_hash', 'iban_masked', 'name',
    'currency', 'account_id', 'last_balance', 'last_balance_at',
])]
class BankAccount extends Model
{
    /** @use HasFactory<BankAccountFactory> */
    use BelongsToTeam, HasFactory;

    /**
     * @return BelongsTo<BankConnection, $this>
     */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(BankConnection::class, 'bank_connection_id');
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * @return HasMany<Transaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * A name for the bank's account data: banks often send an empty name or
     * details (Boursorama), and a card only as its product code.
     *
     * @param  array<string, mixed>  $data
     */
    public static function nameFrom(array $data): string
    {
        $name = collect([$data['details'] ?? null, $data['product'] ?? null, $data['name'] ?? null])
            ->first(fn (mixed $value) => is_string($value) && trim($value) !== '');

        $name = match ($name) {
            'immediat_debit' => __('Immediate debit card'),
            'deferred_debit' => __('Deferred debit card'),
            default => $name,
        };

        return mb_substr((string) $name, 0, 255);
    }

    /**
     * What to show for this account: its name, else its masked IBAN.
     */
    public function label(): string
    {
        $name = self::nameFrom(['name' => $this->name]);

        return match (true) {
            $name !== '' => $name,
            $this->iban_masked !== null => __('Account :iban', ['iban' => $this->iban_masked]),
            default => __('Account'),
        };
    }

    /**
     * A card has no IBAN: its payments already show on the current account,
     * so syncing it too would import them twice.
     */
    public function isCard(): bool
    {
        return $this->iban_masked === null;
    }

    /**
     * Show only the country, the check digits and the last four characters of an IBAN.
     */
    public static function maskIban(?string $iban): ?string
    {
        $iban = $iban === null ? '' : preg_replace('/\s+/', '', $iban);

        return $iban === '' || $iban === null ? null : substr($iban, 0, 4).' •••• '.substr($iban, -4);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_balance' => 'integer',
            'last_balance_at' => 'datetime',
        ];
    }
}
