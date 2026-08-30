<?php

declare(strict_types=1);

use App\Models\ActivityLog;
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
