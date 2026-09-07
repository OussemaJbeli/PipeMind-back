<?php

declare(strict_types=1);

use App\Models\Analysis;
use App\Models\CommitChange;
use App\Models\Failure;
use App\Models\FailureSignature;
use App\Models\Pipeline;
use App\Models\PipelineJob;
use App\Models\Project;
use App\Models\User;

function projectFor(User $user): Project
{
    return Project::factory()->create(['team_id' => $user->current_team_id]);
}

it('lists pipelines newest first with the branch filter options', function () {
    $user = User::factory()->withTeam()->create();
    $project = projectFor($user);

    Pipeline::factory()->for($project)->create(['ref' => 'main', 'iid' => 1]);
    Pipeline::factory()->failed()->for($project)->create(['ref' => 'feature/x', 'iid' => 2]);

    $body = $this->actingAs($user)->getJson("/api/v1/projects/{$project->slug}/pipelines")
        ->assertOk()->json();

    expect($body['data'][0]['iid'])->toBe(2)
        ->and($body['meta']['total'])->toBe(2)
        // Populates the branch filter without a second request, and without the
        // client guessing from whatever happens to be on page one.
        ->and($body['filters']['refs'])->toContain('main', 'feature/x');
});

it('filters pipelines by status and branch', function () {
    $user = User::factory()->withTeam()->create();
    $project = projectFor($user);

    Pipeline::factory()->for($project)->create(['ref' => 'main', 'status' => 'success']);
    Pipeline::factory()->failed()->for($project)->create(['ref' => 'feature/x']);

    expect($this->actingAs($user)->getJson("/api/v1/projects/{$project->slug}/pipelines?status=failed")->json('data'))
        ->toHaveCount(1);

    expect($this->actingAs($user)->getJson("/api/v1/projects/{$project->slug}/pipelines?ref=main")->json('data'))
        ->toHaveCount(1);
});

it('surfaces the failure inline so the list beats the provider\'s own', function () {
    $user = User::factory()->withTeam()->create();
    $project = projectFor($user);
    $pipeline = Pipeline::factory()->failed()->for($project)->create();

    Failure::factory()->create([
        'team_id' => $user->current_team_id,
        'project_id' => $project->id,
        'pipeline_id' => $pipeline->id,
    ]);

    $row = $this->actingAs($user)->getJson("/api/v1/projects/{$project->slug}/pipelines")
        ->assertOk()->json('data.0');

    expect($row['has_failure'])->toBeTrue()
        ->and($row['failure_uuid'])->not->toBeNull();
});

it('returns a pipeline with its stages, jobs, changes and failures', function () {
    $user = User::factory()->withTeam()->create();
    $project = projectFor($user);
    $pipeline = Pipeline::factory()->failed()->for($project)->create(['iid' => 821]);

    PipelineJob::factory()->failed()->for($pipeline)->create(['name' => 'backend-tests']);
    CommitChange::factory()->config()->create([
        'pipeline_id' => $pipeline->id, 'project_id' => $project->id,
    ]);

    $failure = Failure::factory()->create([
        'team_id' => $user->current_team_id,
        'project_id' => $project->id,
        'pipeline_id' => $pipeline->id,
    ]);
    Analysis::factory()->create([
        'failure_id' => $failure->id,
        'team_id' => $user->current_team_id,
        'status' => 'completed',
        'confidence' => 0.92,
    ]);

    $data = $this->actingAs($user)->getJson("/api/v1/projects/{$project->slug}/pipelines/821")
        ->assertOk()->json('data');

    expect($data['iid'])->toBe(821)
        ->and($data['jobs'])->toHaveCount(1)
        ->and($data['changes'])->toHaveCount(1)
        ->and($data['failures'])->toHaveCount(1)
        // The banner needs the confidence without a second request.
        ->and($data['failures'][0]['analysis_confidence'])->toBe(0.92);
});

it('reports the AI cost and quality totals the analyses page leads with', function () {
    $user = User::factory()->withTeam()->create();
    $project = projectFor($user);
    $failure = Failure::factory()->create([
        'team_id' => $user->current_team_id, 'project_id' => $project->id,
    ]);

    Analysis::factory()->create([
        'failure_id' => $failure->id, 'team_id' => $user->current_team_id,
        'status' => 'completed', 'cost_usd' => 0.002, 'latency_ms' => 4000,
        'confidence' => 0.9, 'cache_hit' => false,
    ]);
    Analysis::factory()->create([
        'failure_id' => $failure->id, 'team_id' => $user->current_team_id,
        'status' => 'completed', 'cost_usd' => 0, 'latency_ms' => 0,
        'confidence' => 0.9, 'cache_hit' => true,
    ]);

    $totals = $this->actingAs($user)->getJson("/api/v1/projects/{$project->slug}/analyses")
        ->assertOk()->json('totals');

    expect($totals['analyses'])->toBe(2)
        ->and($totals['cost_usd'])->toBe(0.002)
        // The number that shows whether caching earns its complexity.
        ->and($totals['cache_hit_rate'])->toBe(0.5);
});

it('groups the history by signature rather than listing every failure', function () {
    $user = User::factory()->withTeam()->create();
    $project = projectFor($user);

    $signature = FailureSignature::factory()->create([
        'team_id' => $user->current_team_id,
        'hash' => str_repeat('d', 64),
        'is_known' => true,
        'known_resolution' => 'Add a healthcheck',
    ]);

    Failure::factory()->count(4)->create([
        'team_id' => $user->current_team_id,
        'project_id' => $project->id,
        'signature_id' => $signature->id,
    ]);

    $data = $this->actingAs($user)->getJson("/api/v1/projects/{$project->slug}/signatures")
        ->assertOk()->json('data');

    // Four failures, one row. That is the shape the information actually has.
    expect($data)->toHaveCount(1)
        ->and($data[0]['occurrences'])->toBe(4)
        ->and($data[0]['is_known'])->toBeTrue()
        ->and($data[0]['known_resolution'])->toBe('Add a healthcheck')
        ->and(strlen($data[0]['hash']))->toBe(12);
});

it('never exposes another team\'s pipelines or history', function () {
    $mine = User::factory()->withTeam()->create();
    $theirs = User::factory()->withTeam()->create();
    $project = projectFor($theirs);

    Pipeline::factory()->for($project)->create();

    // Project binding is team-scoped, so the whole subtree 404s.
    foreach (['pipelines', 'analyses', 'signatures'] as $path) {
        $this->actingAs($mine)->getJson("/api/v1/projects/{$project->slug}/{$path}")->assertNotFound();
    }
});

it('lists only active projects, matching the workspace', function () {
    $user = User::factory()->withTeam()->create();

    Project::factory()->create(['team_id' => $user->current_team_id, 'name' => 'Live', 'is_active' => true]);
    Project::factory()->create(['team_id' => $user->current_team_id, 'name' => 'Dead', 'is_active' => false]);

    // The workspace filtered on is_active and this did not, so the two pages
    // disagreed and a user could reach an empty board through the list and
    // conclude ingestion was broken.
    $active = $this->actingAs($user)->getJson('/api/v1/projects')->assertOk()->json('data');
    expect($active)->toHaveCount(1)->and($active[0]['name'])->toBe('Live');

    // Still reachable — deactivated projects keep their history.
    expect($this->actingAs($user)->getJson('/api/v1/projects?include_inactive=1')->json('data'))
        ->toHaveCount(2);
});
