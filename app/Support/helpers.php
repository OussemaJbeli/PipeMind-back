<?php

declare(strict_types=1);

use App\Events\ActivityCreated;
use App\Models\ActivityLog;
use App\Models\Project;
use App\Models\Team;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

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

if (! function_exists('activity_log')) {
    /**
     * Append to the feed shown on both target pages.
     *
     * The `action` vocabulary is fixed and the frontend maps each to an icon and
     * colour, degrading unknown values to a neutral dot — so adding an action
     * here never requires a frontend release.
     */
    function activity_log(
        ?Project $project,
        string $action,
        string $level = 'info',
        string $title = '',
        ?string $description = null,
        ?Model $subject = null,
        array $metadata = [],
    ): void {
        $teamId = $project?->team_id ?? currentTeamId();

        if (! $teamId) {
            return;
        }

        $entry = ActivityLog::create([
            'team_id' => $teamId,
            'project_id' => $project?->id,
            'user_id' => auth()->id(),
            'actor_type' => auth()->check() ? 'user' : (str_starts_with($action, 'analysis') ? 'ai' : 'system'),
            'action' => $action,
            'level' => $level,
            'title' => $title ?: ($project?->name ?? 'PipeMind'),
            'description' => $description,
            'subject_type' => $subject ? class_basename($subject) : null,
            'subject_id' => $subject?->getKey(),
            'subject_uuid' => $subject->uuid ?? null,
            'metadata' => $metadata,
        ]);

        // Broadcast from here rather than from each call site: every activity
        // entry in the application already flows through this function, and
        // dispatching at each of the ~40 callers would guarantee some of them
        // are missed.
        //
        // Never allowed to break the write. Activity logging is called from
        // ingestion and from queued jobs, and a broken broadcaster must not turn
        // "a pipeline was recorded" into a failed job.
        try {
            ActivityCreated::dispatch($entry->loadMissing('team'));
        } catch (Throwable $exception) {
            Log::warning('activity.broadcast_failed', [
                'activity' => $entry->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
