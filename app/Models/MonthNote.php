<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Concerns\BelongsToTeam;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $team_id
 * @property Carbon $month
 * @property string $note
 */
#[Fillable(['team_id', 'month', 'note'])]
class MonthNote extends Model
{
    use BelongsToTeam;

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
