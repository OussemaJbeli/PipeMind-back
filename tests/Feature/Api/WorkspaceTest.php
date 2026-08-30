<?php

declare(strict_types=1);

use App\Models\Project;
use App\Models\User;

it('returns the workspace summary shape', function () {
    $user = User::factory()->withTeam()->create();

    $this->actingAs($user)->getJson('/api/v1/workspace/summary')
        ->assertOk()
        ->assertJsonStructure(['data' => [
            'projects' => ['value', 'trend', 'positive_direction'],
            'pipelines_today' => ['value', 'trend'],
            'failures_today' => ['value', 'positive_direction'],
            'success_rate' => ['value', 'unit', 'trend'],
        ]]);
});

it('marks failures as better when they go down', function () {
    $user = User::factory()->withTeam()->create();

    $summary = $this->actingAs($user)->getJson('/api/v1/workspace/summary')->json('data');

    // The UI colours the arrow from this. Without it, every improvement in
    // failures or MTTR would render red.
    expect($summary['failures_today']['positive_direction'])->toBe('down')
        ->and($summary['success_rate']['positive_direction'])->toBe('up');
});

it('returns project cards with health and last pipeline', function () {
    $user = User::factory()->withTeam()->create();
    Project::factory()->count(2)->create(['team_id' => $user->current_team_id]);

    $this->actingAs($user)->getJson('/api/v1/workspace/projects')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonStructure(['data' => [['uuid', 'name', 'slug', 'tech_stack',
            'health_status', 'success_rate', 'failures_today', 'pipelines_count']]]);
});
