<?php

declare(strict_types=1);

use App\Models\ActivityLog;
use App\Models\Integration;
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

describe('activity feed', function () {
    it('cursor-paginates rather than using an offset', function () {
        $user = User::factory()->withTeam()->create();
        $project = Project::factory()->create(['team_id' => $user->current_team_id]);

        ActivityLog::factory()->count(30)->create([
            'team_id' => $user->current_team_id,
            'project_id' => $project->id,
            'action' => 'pipeline.failed',
        ]);

        $first = $this->actingAs($user)->getJson('/api/v1/workspace/activity?limit=10')
            ->assertOk()->json();

        expect($first['data'])->toHaveCount(10)
            ->and($first['meta']['has_more'])->toBeTrue()
            ->and($first['meta']['next_cursor'])->not->toBeNull();

        $second = $this->actingAs($user)
            ->getJson("/api/v1/workspace/activity?limit=10&cursor={$first['meta']['next_cursor']}")
            ->assertOk()->json();

        // The feed grows while it is being read. An offset would repeat or skip
        // a row as new entries push the window down; a cursor cannot.
        $firstIds = collect($first['data'])->pluck('uuid');
        $secondIds = collect($second['data'])->pluck('uuid');

        expect($firstIds->intersect($secondIds))->toBeEmpty();
    });

    it('filters by action prefix so a family of actions is one choice', function () {
        $user = User::factory()->withTeam()->create();
        $project = Project::factory()->create(['team_id' => $user->current_team_id]);

        ActivityLog::factory()->create([
            'team_id' => $user->current_team_id, 'project_id' => $project->id,
            'action' => 'analysis.completed',
        ]);
        ActivityLog::factory()->create([
            'team_id' => $user->current_team_id, 'project_id' => $project->id,
            'action' => 'analysis.feedback',
        ]);
        ActivityLog::factory()->create([
            'team_id' => $user->current_team_id, 'project_id' => $project->id,
            'action' => 'pipeline.failed',
        ]);

        // `analysis` covers .completed and .feedback, and anything added later
        // without touching the client.
        expect($this->actingAs($user)->getJson('/api/v1/workspace/activity?action=analysis')->json('data'))
            ->toHaveCount(2);
    });

    it('offers only action types this workspace has actually produced', function () {
        $user = User::factory()->withTeam()->create();
        $project = Project::factory()->create(['team_id' => $user->current_team_id]);

        ActivityLog::factory()->create([
            'team_id' => $user->current_team_id, 'project_id' => $project->id,
            'action' => 'anomaly.detected',
        ]);

        $filters = $this->actingAs($user)->getJson('/api/v1/workspace/activity')->json('filters.actions');

        // A filter listing options that return nothing is a filter nobody trusts.
        expect($filters)->toBe(['anomaly']);
    });

    it('filters by project slug and level', function () {
        $user = User::factory()->withTeam()->create();
        $mine = Project::factory()->create(['team_id' => $user->current_team_id]);
        $other = Project::factory()->create(['team_id' => $user->current_team_id]);

        ActivityLog::factory()->create([
            'team_id' => $user->current_team_id, 'project_id' => $mine->id,
            'action' => 'pipeline.failed', 'level' => 'error',
        ]);
        ActivityLog::factory()->create([
            'team_id' => $user->current_team_id, 'project_id' => $other->id,
            'action' => 'pipeline.succeeded', 'level' => 'success',
        ]);

        expect($this->actingAs($user)->getJson("/api/v1/workspace/activity?project={$mine->slug}")->json('data'))
            ->toHaveCount(1);
        expect($this->actingAs($user)->getJson('/api/v1/workspace/activity?level=error')->json('data'))
            ->toHaveCount(1);
    });
});

it('exposes the provider so the projects filter can use it', function () {
    $user = User::factory()->withTeam()->create();
    $integration = Integration::factory()->github()->create([
        'team_id' => $user->current_team_id,
    ]);
    Project::factory()->for($integration)->create(['team_id' => $user->current_team_id]);

    $card = $this->actingAs($user)->getJson('/api/v1/workspace/projects')->assertOk()->json('data.0');

    // whenLoaded, so a caller that skips the eager load triggers no N+1 —
    // the key is simply absent rather than lazily fetched per row.
    expect($card['provider'])->toBe('github');
});
