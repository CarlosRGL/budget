<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Concerns\BelongsToTeam;
use Database\Factories\AssignmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Money assigned to a category for one budget month.
 *
 * @property int $id
 * @property int $team_id
 * @property int $category_id
 * @property Carbon $month
 * @property int $amount
 */
#[Fillable(['team_id', 'category_id', 'month', 'amount'])]
class Assignment extends Model
{
    /** @use HasFactory<AssignmentFactory> */
    use BelongsToTeam, HasFactory;

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
            'month' => DateOnly::class,
            'amount' => 'integer',
        ];
    }
}
