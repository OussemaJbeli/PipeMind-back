<?php

declare(strict_types=1);

use App\Models\Pipeline;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * Channel authorisation is the only thing standing between one workspace's
 * pipeline names, branches and error text and anyone who can guess a UUID.
 * UUIDs appear in URLs people paste into tickets, so "hard to guess" is not the
 * property being relied on here — team membership is.
 *
 * These tests MUST NOT run on the null broadcaster. `phpunit.xml` sets
 * BROADCAST_CONNECTION=null so the rest of the suite never broadcasts, and the
 * null driver's auth returns 200 for everything — every negative case below
 * passed against it, and would have kept passing with `channels.php` deleted.
 * A real driver is what makes the refusals mean anything.
 */
beforeEach(function () {
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'test-key',
        'broadcasting.connections.reverb.secret' => 'test-secret',
        'broadcasting.connections.reverb.app_id' => 'test-app',
    ]);

    // Changing the driver resolves a NEW broadcaster, and `Broadcast::channel()`
    // registers onto whichever instance was current at boot — so the fresh one
    // knows no channels and refuses everything. Without this the suite still
    // goes green on every negative case while proving nothing at all.
    require base_path('routes/channels.php');
});
function authorise(string $channel): TestResponse
{
    return test()->postJson('/broadcasting/auth', [
        'channel_name' => $channel,
        'socket_id' => '123.456',
    ]);
}

it('authorises a team channel for a member', function () {
    $user = User::factory()->withTeam()->create();

    $this->actingAs($user);
    authorise("private-team.{$user->currentTeam->uuid}")->assertOk();
});

it('refuses a team channel for an outsider', function () {
    $user = User::factory()->withTeam()->create();
    $other = Team::factory()->create();

    $this->actingAs($user);
    authorise("private-team.{$other->uuid}")->assertForbidden();
});

it('authorises a project channel for a member of its team', function () {
    $user = User::factory()->withTeam()->create();
    $project = Project::factory()->create(['team_id' => $user->current_team_id]);

    $this->actingAs($user);
    authorise("private-project.{$project->uuid}")->assertOk();
});

it('refuses a project channel belonging to another workspace', function () {
    $user = User::factory()->withTeam()->create();
    $foreign = Project::factory()->create(['team_id' => Team::factory()->create()->id]);

    $this->actingAs($user);
    authorise("private-project.{$foreign->uuid}")->assertForbidden();
});

it('authorises a pipeline channel through the project that owns it', function () {
    $user = User::factory()->withTeam()->create();
    $project = Project::factory()->create(['team_id' => $user->current_team_id]);
    $pipeline = Pipeline::factory()->create(['project_id' => $project->id]);

    $this->actingAs($user);
    authorise("private-pipeline.{$pipeline->uuid}")->assertOk();
});

it('refuses a pipeline channel from another workspace', function () {
    $user = User::factory()->withTeam()->create();
    $foreign = Pipeline::factory()->create([
        'project_id' => Project::factory()->create(['team_id' => Team::factory()->create()->id])->id,
    ]);

    // Pipelines carry no team_id, so the join to projects IS the tenancy check.
    // Without it this returns 200 and streams another workspace's build log.
    $this->actingAs($user);
    authorise("private-pipeline.{$foreign->uuid}")->assertForbidden();
});

it('refuses every channel to an unauthenticated visitor', function () {
    $project = Project::factory()->create();

    foreach ([
        "private-team.{$project->team->uuid}",
        "private-project.{$project->uuid}",
    ] as $channel) {
        // 403 rather than a redirect: this endpoint is only ever called by XHR.
        expect(authorise($channel)->status())->toBeIn([401, 403]);
    }
});

it('refuses a channel whose subject does not exist', function () {
    $user = User::factory()->withTeam()->create();

    $this->actingAs($user);
    authorise('private-project.'.Str::uuid())->assertForbidden();
});

it('keeps a user subscribed only to teams they still belong to', function () {
    $user = User::factory()->withTeam()->create();
    $team = $user->currentTeam;

    $this->actingAs($user);
    authorise("private-team.{$team->uuid}")->assertOk();

    // A socket outlives a request. Removal has to end the subscription's
    // authority, not just hide the workspace in the UI.
    $user->teams()->detach($team->id);

    authorise("private-team.{$team->uuid}")->assertForbidden();
});
