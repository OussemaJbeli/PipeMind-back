<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Usage: ->middleware('team.role:owner,admin') */
class EnsureTeamRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $role = $request->user()?->roleIn(currentTeam());

        abort_unless($role && in_array($role->value, $roles, true), 403, 'Insufficient team role.');

        return $next($request);
    }
}
