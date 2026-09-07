<?php

declare(strict_types=1);

use App\Models\Anomaly;
use App\Models\Pipeline;
use App\Models\PipelineJob;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Builds a stable history: 20 runs at ~100s, so the baseline has real spread. */
function withHistory(Project $project, string $job = 'npm-ci', int $runs = 20, int $around = 100): void
{
    foreach (range(1, $runs) as $index) {
        $pipeline = Pipeline::factory()->for($project)->create([
            'status' => 'success',
            'ref' => 'main',
            'finished_at' => now()->subDays(2),
            'created_at' => now()->subDays(2),
        ]);

        PipelineJob::factory()->for($pipeline)->create([
            'name' => $job,
            'status' => 'success',
            'duration_seconds' => $around + ($index % 5) - 2,
            'peak_memory_mb' => null,
            'created_at' => now()->subDays(2),
        ]);
    }
}

function slowRun(Project $project, int $seconds, string $job = 'npm-ci'): Pipeline
{
    $pipeline = Pipeline::factory()->for($project)->create([
        'status' => 'success', 'ref' => 'main', 'finished_at' => now(),
    ]);

    PipelineJob::factory()->for($pipeline)->create([
        'name' => $job, 'status' => 'success',
        'duration_seconds' => $seconds, 'peak_memory_mb' => null,
    ]);

    return $pipeline;
}

it('raises a duration anomaly for a deliberately slowed job', function () {
    $user = User::factory()->withTeam()->create();
    $project = Project::factory()->create(['team_id' => $user->current_team_id]);

    withHistory($project);
    slowRun($project, 400);   // the roadmap's "add sleep 300" scenario

    $this->artisan('pipemind:compute-baselines')->assertSuccessful();
    $this->artisan('pipemind:detect-anomalies --all')->assertSuccessful();

    $anomaly = Anomaly::withoutGlobalScopes()->where('type', 'duration')->first();

    expect($anomaly)->not->toBeNull()
        ->and((float) $anomaly->observed_value)->toBe(400.0)
        ->and($anomaly->deviation_ratio)->toBeGreaterThan(3.0)
        ->and($anomaly->z_score)->toBeGreaterThan(3.5)
        ->and($anomaly->detection_method)->toBe('mad')
        // The sample size belongs in the text: "4x slower" measured against
        // three runs means nothing, and the reader must be able to tell.
        ->and($anomaly->description)->toContain('runs in the last');
});

it('never flags against a baseline too thin to mean anything', function () {
    $user = User::factory()->withTeam()->create();
    $project = Project::factory()->create(['team_id' => $user->current_team_id]);

    // Three runs is not a distribution.
    withHistory($project, runs: 3);
    slowRun($project, 900);

    $this->artisan('pipemind:compute-baselines')->assertSuccessful();
    $this->artisan('pipemind:detect-anomalies --all')->assertSuccessful();

    expect(DB::table('job_baselines')->count())->toBe(0)
        ->and(Anomaly::withoutGlobalScopes()->count())->toBe(0);
});

it('does not flag a job that got faster', function () {
    $user = User::factory()->withTeam()->create();
    $project = Project::factory()->create(['team_id' => $user->current_team_id]);

    withHistory($project);
    slowRun($project, 5);   // dramatically quicker — good news, not an alert

    $this->artisan('pipemind:compute-baselines')->assertSuccessful();
    $this->artisan('pipemind:detect-anomalies --all')->assertSuccessful();

    expect(Anomaly::withoutGlobalScopes()->where('type', 'duration')->count())->toBe(0);
});

it('updates the existing row instead of duplicating on every pipeline', function () {
    $user = User::factory()->withTeam()->create();
    $project = Project::factory()->create(['team_id' => $user->current_team_id]);

    withHistory($project);
    $this->artisan('pipemind:compute-baselines')->assertSuccessful();

    foreach ([400, 500, 600] as $seconds) {
        slowRun($project, $seconds);
        $this->artisan('pipemind:detect-anomalies --all')->assertSuccessful();
    }

    // The same slow job across three pipelines is one problem, not three rows.
    expect(Anomaly::withoutGlobalScopes()->where('type', 'duration')->count())->toBe(1);

    $anomaly = Anomaly::withoutGlobalScopes()->where('type', 'duration')->first();
    expect((float) $anomaly->observed_value)->toBe(600.0);
});

it('never quietly lowers a severity that was already raised', function () {
    $user = User::factory()->withTeam()->create();
    $project = Project::factory()->create(['team_id' => $user->current_team_id]);

    withHistory($project);
    $this->artisan('pipemind:compute-baselines')->assertSuccessful();

    slowRun($project, 2000);   // critical
    $this->artisan('pipemind:detect-anomalies --all')->assertSuccessful();
    $severity = Anomaly::withoutGlobalScopes()->first()->severity->value;

    slowRun($project, 130);    // barely over — would score lower on its own
    $this->artisan('pipemind:detect-anomalies --all')->assertSuccessful();

    // A critical alert that quietly becomes "low" is how a real problem
    // disappears from the top of the list.
    expect(Anomaly::withoutGlobalScopes()->first()->severity->value)->toBe($severity);
});

it('closes an anomaly once the metric has recovered for three runs', function () {
    $user = User::factory()->withTeam()->create();
    $project = Project::factory()->create(['team_id' => $user->current_team_id]);

    withHistory($project);
    $this->artisan('pipemind:compute-baselines')->assertSuccessful();

    slowRun($project, 800);
    $this->artisan('pipemind:detect-anomalies --all')->assertSuccessful();
    expect(Anomaly::withoutGlobalScopes()->where('status', 'open')->count())->toBe(1);

    // Two healthy runs is not enough — absence of evidence is not recovery.
    slowRun($project, 100);
    slowRun($project, 101);
    $this->artisan('pipemind:detect-anomalies --all')->assertSuccessful();
    expect(Anomaly::withoutGlobalScopes()->where('status', 'open')->count())->toBe(1);

    slowRun($project, 99);
    $this->artisan('pipemind:detect-anomalies --all')->assertSuccessful();

    // Nobody acknowledges a stale alert, and a list nobody trusts is worse
    // than no list at all.
    expect(Anomaly::withoutGlobalScopes()->where('status', 'resolved')->count())->toBe(1);
});

it('catches a job that both passed and failed on the same commit', function () {
    $user = User::factory()->withTeam()->create();
    $project = Project::factory()->create(['team_id' => $user->current_team_id]);

    $sha = str_repeat('a', 40);

    foreach (['success', 'failed'] as $status) {
        $pipeline = Pipeline::factory()->for($project)->create([
            'status' => $status, 'commit_sha' => $sha, 'finished_at' => now(),
        ]);
        PipelineJob::factory()->for($pipeline)->create([
            'name' => 'e2e-tests', 'status' => $status, 'duration_seconds' => 60,
        ]);
    }

    $this->artisan('pipemind:detect-anomalies --all')->assertSuccessful();

    $anomaly = Anomaly::withoutGlobalScopes()->where('type', 'flaky_test')->first();

    // A direct contradiction — the code did not change between the runs.
    expect($anomaly)->not->toBeNull()
        ->and($anomaly->title)->toContain('e2e-tests')
        ->and($anomaly->detection_method)->toBe('rule');
});
