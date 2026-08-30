<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Binds the team for the request. Every tenant-scoped query depends on this.
 *
 * An explicit X-Team header wins over the user's default so the frontend can
 * switch workspace without a round trip, but the header is validated against
 * actual membership — it is a selector, never an authorisation.
 */
class ResolveTeam
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            abort(401);
        }

        $uuid = $request->header('X-Team');

        $team = $uuid
            ? $user->teams()->where('teams.uuid', $uuid)->first()
            : $user->currentTeam;

        abort_if(! $team, 403, 'No accessible team for this request.');

        app()->instance('pipemind.team', $team);
        $request->attributes->set('team', $team);

        return $next($request);
    }
}
