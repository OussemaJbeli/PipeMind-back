<?php

declare(strict_types=1);

use App\Models\Analysis;
use App\Models\Anomaly;
use App\Models\Failure;
use App\Models\Project;
use App\Models\User;

it('returns every analytics panel in one request', function () {
    $user = User::factory()->withTeam()->create();
    $project = Project::factory()->create(['team_id' => $user->current_team_id]);

    Failure::factory()->count(3)->create([
        'team_id' => $user->current_team_id,
        'project_id' => $project->id,
        'category' => 'DATABASE',
        'failed_at' => now()->subDays(2),
    ]);

    $data = $this->actingAs($user)->getJson("/api/v1/projects/{$project->slug}/analytics")
        ->assertOk()->json('data');

    // One request, one date range. Six endpoints could disagree about which rows
    // they saw, and a dashboard that contradicts itself is worse than none.
    foreach (['failure_trend', 'mttr_trend', 'heatmap', 'slowest_jobs', 'flakiest_jobs', 'top_signatures', 'ai_performance'] as $panel) {
        expect($data)->toHaveKey($panel);
    }
});

it('zero-fills the heatmap so the grid is never ragged', function () {
    $user = User::factory()->withTeam()->create();
    $project = Project::factory()->create(['team_id' => $user->current_team_id]);

    $data = $this->actingAs($user)->getJson("/api/v1/projects/{$project->slug}/analytics")
        ->assertOk()->json('data.heatmap');

    expect($data['cells'])->toHaveCount(7)
        ->and($data['cells'][1])->toHaveCount(24)
        ->and($data['max'])->toBe(0);
});

it('says MTTR falling is good, so the arrow renders green', function () {
    $user = User::factory()->withTeam()->create();
    $project = Project::factory()->create(['team_id' => $user->current_team_id]);

    $data = $this->actingAs($user)->getJson("/api/v1/projects/{$project->slug}/analytics")
        ->assertOk()->json('data.mttr_trend');

    // Encoded server-side so no component has to know the semantics of a metric.
    expect($data['positive_direction'])->toBe('down');
});

it('distinguishes no feedback from unhelpful feedback', function () {
    $user = User::factory()->withTeam()->create();
    $project = Project::factory()->create(['team_id' => $user->current_team_id]);
    $failure = Failure::factory()->create([
        'team_id' => $user->current_team_id, 'project_id' => $project->id,
    ]);
    Analysis::factory()->create([
        'failure_id' => $failure->id, 'team_id' => $user->current_team_id, 'status' => 'completed',
    ]);

    $ai = $this->actingAs($user)->getJson("/api/v1/projects/{$project->slug}/analytics")
        ->assertOk()->json('data.ai_performance');

    // "0% helpful" and "nobody has said" are opposite conclusions.
    expect($ai['helpful_rate'])->toBeNull()
        ->and($ai['feedback_count'])->toBe(0)
        ->and($ai['analyses'])->toBe(1);
});

it('lists open anomalies and records a push-back', function () {
    $user = User::factory()->withTeam()->create();
    $project = Project::factory()->create(['team_id' => $user->current_team_id]);

    $anomaly = Anomaly::factory()->create([
        'team_id' => $user->current_team_id,
        'project_id' => $project->id,
        'status' => 'open',
    ]);

    expect($this->actingAs($user)->getJson("/api/v1/projects/{$project->slug}/anomalies")
        ->assertOk()->json('data'))->toHaveCount(1);

    // "Not an issue" is a first-class action: it records a false positive, which
    // is what feeds threshold tuning. A detector nobody can disagree with gets
    // ignored within a week, taking the real alerts with it.
    $this->actingAs($user)->putJson("/api/v1/anomalies/{$anomaly->uuid}/false-positive")->assertOk();

    expect($anomaly->fresh()->status)->toBe('false_positive')
        ->and($anomaly->fresh()->acknowledged_by)->toBe($user->id);
});

it('acknowledges an anomaly without resolving it', function () {
    $user = User::factory()->withTeam()->create();
    $project = Project::factory()->create(['team_id' => $user->current_team_id]);
    $anomaly = Anomaly::factory()->create([
        'team_id' => $user->current_team_id, 'project_id' => $project->id, 'status' => 'open',
    ]);

    $this->actingAs($user)->putJson("/api/v1/anomalies/{$anomaly->uuid}/acknowledge")->assertOk();

    // Acknowledged stays visible: somebody has seen it, nobody has fixed it.
    expect($anomaly->fresh()->status)->toBe('acknowledged');
    expect($this->actingAs($user)->getJson("/api/v1/projects/{$project->slug}/anomalies")->json('data'))
        ->toHaveCount(1);
});

it('never exposes another team\'s anomalies', function () {
    $mine = User::factory()->withTeam()->create();
    $theirs = User::factory()->withTeam()->create();
    $project = Project::factory()->create(['team_id' => $theirs->current_team_id]);

    $anomaly = Anomaly::factory()->create([
        'team_id' => $theirs->current_team_id, 'project_id' => $project->id,
    ]);

    $this->actingAs($mine)->getJson("/api/v1/projects/{$project->slug}/anomalies")->assertNotFound();
    $this->actingAs($mine)->putJson("/api/v1/anomalies/{$anomaly->uuid}/acknowledge")->assertNotFound();
});
