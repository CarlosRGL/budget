<?php

namespace App\Concerns;

use App\Models\Scopes\CurrentTeamScope;
use App\Models\Team;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

/**
 * Household data: always read through the member's current team, and filled
 * with it when created without an explicit team.
 */
trait BelongsToTeam
{
    public static function bootBelongsToTeam(): void
    {
        static::addGlobalScope(new CurrentTeamScope);

        static::creating(function (self $model) {
            $model->team_id ??= Auth::user()?->current_team_id;
        });
    }

    /**
     * Get the household this record belongs to.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
