<?php

declare(strict_types=1);

use App\Models\Team;

if (! function_exists('currentTeam')) {
    /**
     * The team the current request or job is scoped to.
     *
     * Resolution order matters: an explicitly bound team (set by ResolveTeam
     * middleware or by withTeam() in a queued job) always wins over the
     * authenticated user's default, because a job has no session at all.
     */
    function currentTeam(): ?Team
    {
        if (app()->bound('pipemind.team')) {
            return app('pipemind.team');
        }

        return auth()->user()?->currentTeam;
    }
}

if (! function_exists('currentTeamId')) {
    function currentTeamId(): ?int
    {
        return currentTeam()?->id;
    }
}

if (! function_exists('withTeam')) {
    /**
     * Run a closure bound to a team.
     *
     * MANDATORY in every queued job. A worker has no authenticated user, so the
     * TeamScope global scope would be inert and the job would silently operate
     * across every tenant. This is the single most likely source of a
     * cross-tenant data leak in the application.
     *
     * @template T
     *
     * @param  callable():T  $callback
     * @return T
     */
    function withTeam(Team $team, callable $callback): mixed
    {
        $previous = app()->bound('pipemind.team') ? app('pipemind.team') : null;

        app()->instance('pipemind.team', $team);

        try {
            return $callback();
        } finally {
            // Restore rather than forget: jobs can nest, and a worker process is
            // long-lived — leaking a team binding would poison the next job.
            if ($previous) {
                app()->instance('pipemind.team', $previous);
            } else {
                app()->forgetInstance('pipemind.team');
            }
        }
    }
}
