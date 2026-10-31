<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * @implements Scope<Model>
 */
class CurrentTeamScope implements Scope
{
    /**
     * Limit queries to the household the authenticated member is working in.
     *
     * Without an authenticated user (console, queued jobs) nothing is filtered:
     * that code must scope its queries explicitly.
     */
    public function apply(Builder $builder, Model $model): void
    {
        $teamId = Auth::user()?->current_team_id;

        if ($teamId !== null) {
            $builder->where($model->qualifyColumn('team_id'), $teamId);
        }
    }
}
