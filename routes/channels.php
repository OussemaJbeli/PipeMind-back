<?php

declare(strict_types=1);

use App\Models\Pipeline;
use App\Models\Project;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast channels
|--------------------------------------------------------------------------
| Every channel here is PRIVATE and authorised against team membership.
|
| Never make any of these public. Pipeline names, branch names, commit
| messages and error text are proprietary — a public channel hands all of it
| to anyone who can guess a UUID, and UUIDs appear in URLs people paste into
| tickets and chat.
|
| Authorisation runs on `/broadcasting/auth`, which sits in the `web` group and
| so uses the same Sanctum session cookie as the SPA. A bearer-token client
| cannot subscribe, which is correct: only a browser needs live updates.
*/

Broadcast::channel('team.{teamUuid}', function ($user, string $teamUuid): bool {
    return $user->teams()->where('teams.uuid', $teamUuid)->exists();
});

Broadcast::channel('project.{projectUuid}', function ($user, string $projectUuid): bool {
    // Scoped through the user's own teams rather than the currently bound team:
    // a socket outlives a request, and the team a user was "in" when they
    // subscribed is not a safe basis for what they may keep hearing.
    return Project::withoutGlobalScopes()
        ->where('uuid', $projectUuid)
        ->whereIn('team_id', $user->teams()->select('teams.id'))
        ->exists();
});

Broadcast::channel('pipeline.{pipelineUuid}', function ($user, string $pipelineUuid): bool {
    // Pipelines carry no team_id — they belong to a team only through their
    // project — so the join is the tenancy check here, not a convenience.
    return Pipeline::withoutGlobalScopes()
        ->where('pipelines.uuid', $pipelineUuid)
        ->whereExists(fn ($query) => $query
            ->selectRaw('1')
            ->from('projects')
            ->whereColumn('projects.id', 'pipelines.project_id')
            ->whereIn('projects.team_id', $user->teams()->select('teams.id')))
        ->exists();
});
