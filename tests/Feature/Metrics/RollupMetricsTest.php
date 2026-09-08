<?php

declare(strict_types=1);

use App\Models\Failure;
use App\Models\Pipeline;
use App\Models\Project;
use App\Models\User;

/**
 * The KPI tiles read `project_metrics_daily`, never the pipelines table. That
 * indirection is why a real project showed five zeros for every metric while
 * having pipelines, failures and analyses on record: the rollup that fills the
 * table was a stub, and the only populated rows came from the demo seeder.
 *
 * Zeros are the worst possible failure here — indistinguishable from a project
 * that genuinely had a quiet week.
 */
it('produces a metrics row for a project that has pipelines', function () {
    $project = Project::factory()->create();

    Pipeline::factory()->count(3)->create([
        'project_id' => $project->id,
        'status' => 'success',
        'started_at' => now()->subHours(2),
        'duration_seconds' => 100,
    ]);

    expect(DB::table('project_metrics_daily')->where('project_id', $project->id)->count())->toBe(0);

    $this->artisan('pipemind:rollup-metrics', ['--project' => $project->slug])->assertSuccessful();

    $row = DB::table('project_metrics_daily')->where('project_id', $project->id)->first();

    expect($row)->not->toBeNull()
        ->and((int) $row->pipelines_total)->toBe(3)
        ->and((int) $row->pipelines_success)->toBe(3)
        ->and((float) $row->success_rate)->toBe(100.0)
        ->and((int) $row->avg_duration_seconds)->toBe(100);
});

it('computes success rate over finished runs, ignoring anything still in flight', function () {
    $project = Project::factory()->create();

    foreach (['success', 'success', 'failed', 'running'] as $status) {
        Pipeline::factory()->create([
            'project_id' => $project->id,
            'status' => $status,
            'started_at' => now()->subHour(),
            'duration_seconds' => $status === 'running' ? null : 60,
        ]);
    }

    $this->artisan('pipemind:rollup-metrics', ['--project' => $project->slug])->assertSuccessful();

    $row = DB::table('project_metrics_daily')->where('project_id', $project->id)->first();

    // 2 of 3 finished, not 2 of 4. Counting an in-flight run as a failure drops
    // the rate every time the rollup lands mid-pipeline.
    expect((float) $row->success_rate)->toBe(66.67)
        ->and((int) $row->pipelines_running)->toBe(1)
        ->and((int) $row->pipelines_total)->toBe(4);
});

it('measures MTTR from failures resolved that day, not raised that day', function () {
    $project = Project::factory()->create();

    Failure::factory()->create([
        'team_id' => $project->team_id,
        'project_id' => $project->id,
        // Pinned to today: the factory otherwise picks a random started_at, so
        // the run and the failure land on different day-rows.
        'pipeline_id' => Pipeline::factory()->create([
            'project_id' => $project->id, 'started_at' => now()->subHours(3),
        ])->id,
        'created_at' => now()->subHours(3),
        'resolved_at' => now()->subHours(1),
    ]);

    $this->artisan('pipemind:rollup-metrics', ['--project' => $project->slug])->assertSuccessful();

    $row = DB::table('project_metrics_daily')
        ->where('project_id', $project->id)
        ->where('date', now()->toDateString())
        ->first();

    expect((int) $row->failures_count)->toBe(1)
        ->and((int) $row->failures_resolved)->toBe(1)
        // Two hours, give or take the clock moving during the test.
        ->and((int) $row->mttr_seconds)->toBeGreaterThan(7000)
        ->and((int) $row->mttr_seconds)->toBeLessThan(7400);
});

it('is safe to run twice and does not duplicate a day', function () {
    $project = Project::factory()->create();

    Pipeline::factory()->create([
        'project_id' => $project->id, 'status' => 'success',
        'started_at' => now(), 'duration_seconds' => 10,
    ]);

    $this->artisan('pipemind:rollup-metrics', ['--project' => $project->slug]);
    $this->artisan('pipemind:rollup-metrics', ['--project' => $project->slug]);

    // The scheduler runs this hourly over an overlapping window, so every run
    // recomputes days it has already written.
    expect(DB::table('project_metrics_daily')->where('project_id', $project->id)->count())->toBe(1);
});

it('corrects a day it has already written when the underlying runs change', function () {
    $project = Project::factory()->create();

    Pipeline::factory()->create([
        'project_id' => $project->id, 'status' => 'running',
        'started_at' => now(), 'duration_seconds' => null,
    ]);

    $this->artisan('pipemind:rollup-metrics', ['--project' => $project->slug]);

    // A pipeline finishing after the rollup ran is the normal case, which is why
    // this recomputes rather than incrementing.
    Pipeline::withoutGlobalScopes()->where('project_id', $project->id)
        ->update(['status' => 'success', 'duration_seconds' => 42]);

    $this->artisan('pipemind:rollup-metrics', ['--project' => $project->slug]);

    $row = DB::table('project_metrics_daily')->where('project_id', $project->id)->first();

    expect((int) $row->pipelines_success)->toBe(1)
        ->and((int) $row->pipelines_running)->toBe(0)
        ->and((int) $row->avg_duration_seconds)->toBe(42);
});

it('gives the dashboard non-zero KPIs once the rollup has run', function () {
    $user = User::factory()->withTeam()->create();
    $project = Project::factory()->create(['team_id' => $user->current_team_id]);

    Pipeline::factory()->count(2)->create([
        'project_id' => $project->id, 'status' => 'success',
        'started_at' => now()->subHour(), 'duration_seconds' => 90,
    ]);

    $this->artisan('pipemind:rollup-metrics', ['--project' => $project->slug]);

    $kpis = $this->actingAs($user)
        ->getJson("/api/v1/projects/{$project->slug}/overview?range=30d")
        ->assertOk()->json('data.kpis');

    // The end the user actually sees. Five zeros here is the bug this file exists for.
    expect($kpis['pipelines']['value'])->toBe(2)
        ->and((float) $kpis['pipeline_health']['value'])->toBe(100.0)
        ->and($kpis['avg_duration']['value'])->toBeGreaterThan(0);
});
