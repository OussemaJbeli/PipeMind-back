<?php

declare(strict_types=1);

use App\Models\ActivityLog;
use App\Models\Analysis;
use App\Models\Failure;
use App\Models\JobLog;
use App\Models\Pipeline;
use App\Models\PipelineJob;
use App\Models\Project;
use App\Models\User;

/**
 * The class of bug that ends projects. One test per list endpoint.
 */
dataset('list_endpoints', [
    'workspace projects' => ['/api/v1/workspace/projects'],
    'workspace activity' => ['/api/v1/workspace/activity'],
    'projects' => ['/api/v1/projects'],
]);

it('never returns another team\'s data', function (string $endpoint) {
    $mine = User::factory()->withTeam()->create();
    $theirs = User::factory()->withTeam()->create();

    $projects = Project::factory()->count(3)->create(['team_id' => $theirs->current_team_id]);
    foreach ($projects as $project) {
        ActivityLog::factory()->count(2)->create([
            'team_id' => $theirs->current_team_id,
            'project_id' => $project->id,
            'action' => 'pipeline.failed',
            'title' => $project->name,
        ]);
    }

    $this->actingAs($mine)->getJson($endpoint)
        ->assertOk()
        ->assertJsonCount(0, 'data');
})->with('list_endpoints');

it('returns 404 for a project belonging to another team', function () {
    $mine = User::factory()->withTeam()->create();
    $theirs = User::factory()->withTeam()->create();

    $project = Project::factory()->create(['team_id' => $theirs->current_team_id]);

    // 404, not 403 — do not confirm that the resource exists.
    $this->actingAs($mine)->getJson("/api/v1/projects/{$project->slug}")
        ->assertNotFound();
});

it('rejects an X-Team header for a team the user does not belong to', function () {
    $mine = User::factory()->withTeam()->create();
    $theirs = User::factory()->withTeam()->create();

    // The header is a selector, never an authorisation.
    $this->actingAs($mine)
        ->getJson('/api/v1/workspace/summary', ['X-Team' => $theirs->currentTeam->uuid])
        ->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| Failure investigation
|--------------------------------------------------------------------------
| Job logs are the most sensitive data in the system. pipeline_jobs and
| pipelines carry no team_id, so TeamScope has no column to filter on and
| route model binding would otherwise resolve any team's UUID.
*/

it('never lists another team\'s failures', function () {
    $mine = User::factory()->withTeam()->create();
    $theirs = User::factory()->withTeam()->create();

    $project = Project::factory()->create(['team_id' => $theirs->current_team_id]);
    Failure::factory()->count(3)->create([
        'team_id' => $theirs->current_team_id,
        'project_id' => $project->id,
    ]);

    $this->actingAs($mine)->getJson('/api/v1/failures')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('returns 404 for another team\'s failure', function () {
    $mine = User::factory()->withTeam()->create();
    $theirs = User::factory()->withTeam()->create();

    $project = Project::factory()->create(['team_id' => $theirs->current_team_id]);
    $failure = Failure::factory()->create([
        'team_id' => $theirs->current_team_id,
        'project_id' => $project->id,
    ]);

    $this->actingAs($mine)->getJson("/api/v1/failures/{$failure->uuid}")->assertNotFound();
    $this->actingAs($mine)->postJson("/api/v1/failures/{$failure->uuid}/analyze")->assertNotFound();
    $this->actingAs($mine)->putJson("/api/v1/failures/{$failure->uuid}/resolve", [
        'resolution_type' => 'fixed',
    ])->assertNotFound();
});

it('returns 404 for another team\'s job log', function () {
    $mine = User::factory()->withTeam()->create();
    $theirs = User::factory()->withTeam()->create();

    $project = Project::factory()->create(['team_id' => $theirs->current_team_id]);
    $pipeline = Pipeline::factory()->failed()->for($project)->create();
    $job = PipelineJob::factory()->failed()->for($pipeline)->create();

    JobLog::factory()->create([
        'job_id' => $job->id,
        'pipeline_id' => $pipeline->id,
        'project_id' => $project->id,
    ]);

    $this->actingAs($mine)->getJson("/api/v1/jobs/{$job->uuid}/log")->assertNotFound();
});

it('returns 404 when giving feedback on another team\'s analysis', function () {
    $mine = User::factory()->withTeam()->create();
    $theirs = User::factory()->withTeam()->create();

    $project = Project::factory()->create(['team_id' => $theirs->current_team_id]);
    $failure = Failure::factory()->create([
        'team_id' => $theirs->current_team_id,
        'project_id' => $project->id,
    ]);
    $analysis = Analysis::factory()->create([
        'failure_id' => $failure->id,
        'team_id' => $theirs->current_team_id,
    ]);

    $this->actingAs($mine)->postJson("/api/v1/analyses/{$analysis->uuid}/feedback", [
        'was_helpful' => true,
    ])->assertNotFound();
});
