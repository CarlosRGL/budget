<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Concerns\BelongsToTeam;
use App\Enums\MoneyMoveKind;
use Database\Factories\MoneyMoveFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * History of money assigned or moved within a month. A null category means Ready to Assign.
 *
 * @property int $id
 * @property int $team_id
 * @property Carbon $month
 * @property int|null $from_category_id
 * @property int|null $to_category_id
 * @property int $amount
 * @property MoneyMoveKind $kind
 * @property int|null $user_id
 */
#[Fillable(['team_id', 'month', 'from_category_id', 'to_category_id', 'amount', 'kind', 'user_id'])]
class MoneyMove extends Model
{
    /** @use HasFactory<MoneyMoveFactory> */
    use BelongsToTeam, HasFactory;

    /**
     * @return BelongsTo<Category, $this>
     */
    public function fromCategory(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'from_category_id');
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function toCategory(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'to_category_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'month' => DateOnly::class,
            'amount' => 'integer',
            'kind' => MoneyMoveKind::class,
        ];
    }
}
