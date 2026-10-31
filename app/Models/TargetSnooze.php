<?php

namespace App\Models;

use App\Casts\DateOnly;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A category's target paused for one month. Scoped to the household through its category.
 *
 * @property int $id
 * @property int $category_id
 * @property Carbon $month
 */
#[Fillable(['category_id', 'month'])]
class TargetSnooze extends Model
{
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
        ];
    }
}
