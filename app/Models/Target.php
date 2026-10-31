<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Concerns\BelongsToTeam;
use App\Enums\TargetType;
use Database\Factories\TargetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $team_id
 * @property int $category_id
 * @property TargetType $type
 * @property int $amount
 * @property int|null $weekday ISO weekday (1 = Monday) for weekly targets.
 * @property Carbon|null $due_date
 * @property string|null $repeat
 */
#[Fillable(['team_id', 'category_id', 'type', 'amount', 'weekday', 'due_date', 'repeat'])]
class Target extends Model
{
    /** @use HasFactory<TargetFactory> */
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
            'type' => TargetType::class,
            'amount' => 'integer',
            'weekday' => 'integer',
            'due_date' => DateOnly::class,
        ];
    }
}
