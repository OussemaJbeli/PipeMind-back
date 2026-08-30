<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Usage: ->middleware('can.do:remediation.approve')
 *
 * Checks the capability matrix in App\Enums\TeamRole rather than a role name, so
 * changing what an admin can do happens in exactly one place.
 */
class EnsurePermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $role = $request->user()?->roleIn(currentTeam());

        abort_unless($role?->can($permission), 403, "Missing permission: {$permission}");

        return $next($request);
    }
}
