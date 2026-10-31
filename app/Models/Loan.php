<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Concerns\BelongsToTeam;
use Database\Factories\LoanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $team_id
 * @property int|null $account_id The loan account its remaining capital belongs to.
 * @property int|null $category_id The budget category its monthly payments are spent from.
 * @property Carbon|null $posted_through Last day whose due dates were posted to the loan account.
 * @property string $name
 */
#[Fillable(['team_id', 'account_id', 'category_id', 'posted_through', 'name'])]
class Loan extends Model
{
    /** @use HasFactory<LoanFactory> */
    use BelongsToTeam, HasFactory;

    /**
     * @return HasMany<LoanPart, $this>
     */
    public function parts(): HasMany
    {
        return $this->hasMany(LoanPart::class)->orderBy('id');
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'posted_through' => DateOnly::class,
        ];
    }
}
