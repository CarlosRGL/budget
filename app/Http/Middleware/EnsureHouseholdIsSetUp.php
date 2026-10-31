<?php

namespace App\Http\Middleware;

use App\Enums\TeamRole;
use App\Models\Team;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A household that has not been set up yet opens on the onboarding, for the
 * members who manage it (viewers only read, they keep their simple screens).
 */
class EnsureHouseholdIsSetUp
{
    public function handle(Request $request, Closure $next): Response
    {
        $team = $request->route('current_team');

        if (
            $team instanceof Team
            && ! $team->isOnboarded()
            && $request->isMethod('GET')
            && $request->user()?->teamRole($team) !== TeamRole::Viewer
        ) {
            return redirect()->route('onboarding.show', ['current_team' => $team]);
        }

        return $next($request);
    }
}
