<?php

namespace App\Http\Middleware;

use App\Enums\TeamRole;
use App\Models\Team;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Members with the viewer role only read: they get the simple screens, any
 * other page sends them to the month overview and nothing can be changed.
 */
class KeepViewersReadOnly
{
    /** @var list<string> */
    private const VIEWER_ROUTES = ['simple.overview', 'simple.spending', 'stats.show', 'loans.index', 'loans.show'];

    public function handle(Request $request, Closure $next): Response
    {
        $team = $request->route('current_team');

        if (! $team instanceof Team || $request->user()?->teamRole($team) !== TeamRole::Viewer) {
            return $next($request);
        }

        abort_unless($request->isMethod('GET'), 403, __('Read-only access.'));

        if (! in_array($request->route()?->getName(), self::VIEWER_ROUTES, true)) {
            return redirect()->route('simple.overview', ['current_team' => $team]);
        }

        return $next($request);
    }
}
